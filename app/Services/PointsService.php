<?php

namespace App\Services;

use App\Enums\PointTransactionType;
use App\Models\Family;
use App\Models\PointTransaction;
use App\Models\Reward;
use App\Models\RewardPurchase;
use App\Models\Task;
use App\Models\User;
use App\Notifications\KudosReceivedNotification;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PointsService
{
    /**
     * Award points for completing a task.
     *
     * @param  bool  $forceZero  When true, awards 0 points (e.g., child completing own task).
     */
    public function awardTaskPoints(Task $task, User $user, bool $forceZero = false): PointTransaction
    {
        $points = $forceZero ? 0 : $task->getEffectivePoints();

        return PointTransaction::create([
            'family_id' => $user->family_id,
            'user_id' => $user->id,
            'type' => PointTransactionType::TaskCompletion,
            'points' => $points,
            'description' => "Completed: {$task->title}",
            'source_type' => Task::class,
            'source_id' => $task->id,
        ]);
    }

    /**
     * Reverse points when a task is uncompleted.
     */
    public function reverseTaskPoints(Task $task, User $user): PointTransaction
    {
        return PointTransaction::create([
            'family_id' => $user->family_id,
            'user_id' => $user->id,
            'type' => PointTransactionType::TaskReversal,
            'points' => -$task->getEffectivePoints(),
            'description' => "Reversed: {$task->title}",
            'source_type' => Task::class,
            'source_id' => $task->id,
        ]);
    }

    /**
     * Give kudos from one user to another (+1 point).
     * If kudos_cost_enabled is on, deduct 1 point from the giver.
     * If $stackOnto is provided, the new transaction is linked to that source kudo.
     */
    public function giveKudos(User $from, User $to, Family $family, string $reason, ?PointTransaction $stackOnto = null): PointTransaction
    {
        $kudosCostEnabled = $family->settings['kudos_cost_enabled'] ?? false;

        if ($kudosCostEnabled && ! $from->hasSufficientPoints(1)) {
            throw new \Exception('Not enough points to give kudos');
        }

        // Deduct from giver if cost is enabled
        if ($kudosCostEnabled) {
            PointTransaction::create([
                'family_id' => $from->family_id,
                'user_id' => $from->id,
                'type' => PointTransactionType::Deduction,
                'points' => -1,
                'description' => "Kudos cost: gave kudos to {$to->name}",
                'awarded_by' => $from->id,
            ]);
        }

        $transaction = PointTransaction::create([
            'family_id' => $to->family_id,
            'user_id' => $to->id,
            'type' => PointTransactionType::Kudos,
            'points' => 1,
            'description' => $reason,
            'awarded_by' => $from->id,
            'stacked_from_transaction_id' => $stackOnto?->id,
        ]);

        // Notify the recipient (skip if giving to self, or if this is a stack).
        if ($to->getKey() !== $from->getKey() && $stackOnto === null) {
            $to->notify(new KudosReceivedNotification($from, $reason, $transaction));
        }

        return $transaction;
    }

    /**
     * Deduct points from a user (parent only).
     */
    public function deductPoints(User $from, User $target, int $points, string $reason): PointTransaction
    {
        return PointTransaction::create([
            'family_id' => $target->family_id,
            'user_id' => $target->id,
            'type' => PointTransactionType::Deduction,
            'points' => -abs($points),
            'description' => $reason,
            'awarded_by' => $from->id,
        ]);
    }

    /**
     * Redeem a reward (purchase with points).
     */
    public function redeemReward(Reward $reward, User $user): array
    {
        return DB::transaction(function () use ($reward, $user) {
            // Re-fetch with lock to prevent race conditions
            $reward = Reward::lockForUpdate()->findOrFail($reward->id);

            if (! $reward->isPurchasable()) {
                $reason = $reward->isExpired() ? 'This reward has expired' : (! $reward->hasStock() ? 'This reward is sold out' : 'This reward is not available');
                throw new \Exception($reason);
            }

            if (! $user->hasSufficientPoints($reward->point_cost)) {
                throw new \Exception('Insufficient points');
            }

            $transaction = PointTransaction::create([
                'family_id' => $user->family_id,
                'user_id' => $user->id,
                'type' => PointTransactionType::Redemption,
                'points' => -$reward->point_cost,
                'description' => "Purchased: {$reward->title}",
                'source_type' => Reward::class,
                'source_id' => $reward->id,
            ]);

            $purchase = RewardPurchase::create([
                'family_id' => $user->family_id,
                'reward_id' => $reward->id,
                'user_id' => $user->id,
                'points_spent' => $reward->point_cost,
                'purchased_at' => now(),
            ]);

            // Increment purchase counter for limited-stock rewards
            if ($reward->quantity !== null) {
                $reward->increment('quantity_purchased');
            }

            return ['transaction' => $transaction, 'purchase' => $purchase, 'reward' => $reward->fresh()];
        });
    }

    /**
     * Get a user's point bank balance.
     */
    public function getBank(User $user): int
    {
        return $user->pointBank();
    }

    /**
     * Get leaderboard for a family.
     */
    public function getLeaderboard(Family $family, ?string $period = null): Collection
    {
        $period = $period ?? $family->getLeaderboardPeriod();

        [$start, $end] = $this->getPeriodBounds($period);

        return PointTransaction::where('family_id', $family->id)
            ->where('points', '>', 0)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('user_id, SUM(points) as total_points')
            ->groupBy('user_id')
            ->orderByDesc('total_points')
            ->with('user:id,name,avatar')
            ->get()
            ->map(fn ($row) => [
                'user_id' => $row->user_id,
                'user' => $row->user ? ['id' => $row->user->id, 'name' => $row->user->name, 'avatar' => $row->user->avatar] : null,
                'total_points' => (int) $row->total_points,
            ]);
    }

    /**
     * Get the start/end bounds of a leaderboard period.
     */
    private function getPeriodBounds(string $period): array
    {
        $now = Carbon::now();

        return match ($period) {
            'daily' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'weekly' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'monthly' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            default => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
        };
    }
}
