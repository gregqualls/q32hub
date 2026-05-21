<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PointTransactionType;
use App\Http\Controllers\Controller;
use App\Models\PointTransaction;
use App\Services\BadgeService;
use App\Services\PointsService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PointsController extends Controller
{
    public function __construct(
        private PointsService $pointsService,
        private BadgeService $badgeService,
    ) {}

    /**
     * Get current user's point bank balance.
     */
    public function bank(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'bank' => $this->pointsService->getBank($user),
        ]);
    }

    /**
     * Get family leaderboard.
     */
    public function leaderboard(Request $request): JsonResponse
    {
        $family = $request->user()->currentFamily()->firstOrFail();

        return response()->json([
            'leaderboard' => $this->pointsService->getLeaderboard($family),
            'period' => $family->getLeaderboardPeriod(),
        ]);
    }

    /**
     * Get family-wide activity feed.
     */
    public function feed(Request $request): JsonResponse
    {
        $user = $request->user();
        $family = $user->currentFamily()->firstOrFail();

        $transactions = PointTransaction::where('family_id', $family->id)
            ->with(['user:id,name,avatar', 'awardedBy:id,name,avatar'])
            ->withCount('stacks')
            ->orderByDesc('created_at')
            ->paginate(30);

        // Mark which kudos the current user has already stacked (one query per page).
        $kudosIds = collect($transactions->items())
            ->where('type', PointTransactionType::Kudos)
            ->whereNull('stacked_from_transaction_id')
            ->pluck('id');

        $stackedByMe = PointTransaction::whereIn('stacked_from_transaction_id', $kudosIds)
            ->where('awarded_by', $user->id)
            ->pluck('stacked_from_transaction_id')
            ->flip();

        $items = collect($transactions->items())->map(function ($t) use ($stackedByMe) {
            $t->stacked_by_me = isset($stackedByMe[$t->id]);

            return $t;
        });

        return response()->json([
            'feed' => $items,
            'pagination' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }

    /**
     * Give kudos to a family member.
     */
    public function kudos(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'reason' => 'required|string|max:255',
        ]);

        $from = $request->user();

        // Block self-kudos
        if ($validated['user_id'] === $from->id) {
            return response()->json(['message' => "You can't give kudos to yourself"], 422);
        }

        $family = $from->currentFamily()->firstOrFail();
        $to = $family->members()->findOrFail($validated['user_id']);

        try {
            $transaction = $this->pointsService->giveKudos($from, $to, $family, $validated['reason']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Check for badges on the recipient
        $newBadges = $this->badgeService->checkAndAwardBadges($to);

        $response = [
            'transaction' => $transaction,
            'message' => "Gave kudos to {$to->name}",
        ];

        if (! empty($newBadges)) {
            $response['badges_earned'] = collect($newBadges)->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'description' => $b->description,
                'icon' => $b->icon,
                'color' => $b->color,
                'user_id' => $to->id,
            ]);
        }

        return response()->json($response, 201);
    }

    /**
     * Stack a "+1" onto an existing kudos transaction.
     * Re-gives the same kudo (same recipient, same reason) on behalf of the caller.
     */
    public function stackKudos(Request $request, PointTransaction $transaction): JsonResponse
    {
        $from = $request->user();
        $family = $from->currentFamily()->firstOrFail();

        if ($transaction->family_id !== $family->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // @phpstan-ignore-next-line ternary.alwaysTrue — Larastan reads $type as string, but cast may return enum at runtime
        $typeValue = is_string($transaction->type) ? $transaction->type : $transaction->type->value;
        if ($typeValue !== PointTransactionType::Kudos->value) {
            return response()->json(['message' => 'You can only stack onto a kudos.'], 422);
        }

        if ($transaction->stacked_from_transaction_id !== null) {
            return response()->json(['message' => 'Stack onto the original kudo, not another stack.'], 422);
        }

        $fromId = (string) $from->id;

        if ((string) $transaction->user_id === $fromId) {
            return response()->json(['message' => "You can't stack onto kudos given to you."], 422);
        }

        if ((string) $transaction->awarded_by === $fromId) {
            return response()->json(['message' => 'You already gave this kudo.'], 422);
        }

        $alreadyStacked = PointTransaction::where('stacked_from_transaction_id', $transaction->id)
            ->where('awarded_by', $from->id)
            ->exists();

        if ($alreadyStacked) {
            return response()->json(['message' => "You've already +1'd this kudo."], 422);
        }

        $to = $family->members()->findOrFail($transaction->user_id);

        try {
            $stack = $this->pointsService->giveKudos($from, $to, $family, $transaction->description, $transaction);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => "You've already +1'd this kudo."], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $newBadges = $this->badgeService->checkAndAwardBadges($to);

        $response = [
            'transaction' => $stack,
            'message' => "+1 kudos to {$to->name}",
        ];

        if (! empty($newBadges)) {
            $response['badges_earned'] = collect($newBadges)->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'description' => $b->description,
                'icon' => $b->icon,
                'color' => $b->color,
                'user_id' => $to->id,
            ]);
        }

        return response()->json($response, 201);
    }

    /**
     * Deduct points from a family member (parent only).
     */
    public function deduct(Request $request): JsonResponse
    {
        $from = $request->user();

        if (! $from->isParent()) {
            return response()->json(['message' => 'Only parents can deduct points'], 403);
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'points' => 'required|integer|min:1',
            'reason' => 'required|string|max:255',
        ]);

        $family = $from->currentFamily()->firstOrFail();
        $target = $family->members()->findOrFail($validated['user_id']);

        $transaction = $this->pointsService->deductPoints(
            $from,
            $target,
            $validated['points'],
            $validated['reason']
        );

        return response()->json([
            'transaction' => $transaction,
            'message' => "Deducted {$validated['points']} points from {$target->name}",
        ], 201);
    }
}
