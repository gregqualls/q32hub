<?php

namespace App\Mcp\Tools;

use App\Enums\PointRequestStatus;
use App\Enums\PointTransactionType;
use App\Enums\RewardVisibility;
use App\Mcp\Tools\Concerns\MergesUpdates;
use App\Mcp\Tools\Concerns\RequiresModule;
use App\Mcp\Tools\Concerns\ScopesToFamily;
use App\Models\PointRequest;
use App\Models\PointTransaction;
use App\Models\Reward;
use App\Models\RewardPurchase;
use App\Models\User;
use App\Services\AuctionService;
use App\Services\BadgeService;
use App\Services\PointsService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('kinhold-points')]
#[Description(<<<'DESC'
Points, kudos, point requests, rewards store, and auctions.

Points view:
  points_bank (user_id?) — Single user's balance (defaults to current).
  points_leaderboard (period?) — daily, weekly, monthly. Defaults to family setting.
  points_feed (limit?) — Recent point transactions.

Points give/deduct:
  points_kudos (user_id*, reason*) — Any member can give +1.
  points_stack_kudos (transaction_id*) — "+1" onto another member's kudo. Copies recipient + reason from the source kudo. Cannot stack on your own kudos or kudos given to you.
  points_deduct (user_id*, points*, reason*) — Parent only.

Point requests (children → parents):
  request_list (status?) — Filter: pending, approved, denied. Children see only their own.
  request_approve (request_id*) — Parent only. Awards points + checks badges.
  request_deny (request_id*) — Parent only.

Rewards store + auctions:
  reward_list — All rewards with stock, expiry, visibility, auction state.
  reward_create (title*, point_cost*, ...) — Parent only. See params for full set.
  reward_update (reward_id*, [any field]) — Parent only.
  reward_delete (reward_id*) — Parent only.
  reward_purchase (reward_id*) — Spend points to redeem.
  reward_bid (reward_id*, bid_amount*) — Place auction bid (holds points).
  reward_close_auction (reward_id*) — Close auction, award winner. Parent only.
  reward_cancel_auction (reward_id*) — Cancel + release held points. Parent only.
  purchase_history (user_id?) — Recent reward purchases (filterable).

Visibility: everyone, parent_only, child_only, specific. Reward types: standard, auction.
DESC)]
class KinholdPoints extends Tool
{
    use MergesUpdates, RequiresModule, ScopesToFamily;

    public const MODULE = 'points';

    public function schema($schema): array
    {
        return [
            'action' => $schema->string()->required()->enum([
                'points_bank', 'points_leaderboard', 'points_feed',
                'points_kudos', 'points_stack_kudos', 'points_deduct',
                'request_list', 'request_approve', 'request_deny',
                'reward_list', 'reward_create', 'reward_update', 'reward_delete',
                'reward_purchase', 'reward_bid', 'reward_close_auction', 'reward_cancel_auction',
                'purchase_history',
            ])->description('Action to perform'),
            'user_id' => $schema->string()->description('User UUID (varies by action — bank target, kudos/deduct target, history filter)'),
            'period' => $schema->string()->enum(['daily', 'weekly', 'monthly'])->description('Leaderboard period'),
            'limit' => $schema->integer()->description('Number of feed items (default 20)'),
            'points' => $schema->integer()->description('Points amount (required for points_deduct)'),
            'reason' => $schema->string()->description('Reason for kudos or deduction (required for points_kudos and points_deduct)'),
            'request_id' => $schema->string()->description('PointRequest UUID (required for request_approve/request_deny)'),
            'transaction_id' => $schema->string()->description('PointTransaction UUID of the kudos to +1 (required for points_stack_kudos)'),
            'status' => $schema->string()->enum(['pending', 'approved', 'denied'])->description('Filter for request_list'),
            'reward_id' => $schema->string()->description('Reward UUID (required for reward_*/purchase actions on a specific reward)'),
            'bid_amount' => $schema->integer()->description('Bid amount in points (required for reward_bid)'),
            'title' => $schema->string()->description('Reward title (required for reward_create)'),
            'description' => $schema->string()->description('Reward description'),
            'point_cost' => $schema->integer()->description('Points required to purchase (required for reward_create)'),
            'icon' => $schema->string()->description('Emoji or icon for the reward'),
            'is_active' => $schema->boolean()->description('Whether the reward is available for purchase'),
            'quantity' => $schema->integer()->description('Stock limit (null = unlimited)'),
            'expires_at' => $schema->string()->description('Expiration ISO 8601 (null = never expires)'),
            'visibility' => $schema->string()->enum(['everyone', 'parent_only', 'child_only', 'specific'])->description('Who can see this reward'),
            'visible_to' => $schema->string()->description('Comma-separated user UUIDs when visibility is "specific"'),
            'min_age' => $schema->integer()->description('Minimum age to see this reward'),
            'max_age' => $schema->integer()->description('Maximum age to see this reward'),
            'reward_type' => $schema->string()->enum(['standard', 'auction'])->description('Reward type (default: standard)'),
            'min_bid' => $schema->integer()->description('Minimum bid amount for auctions'),
            'bid_start_at' => $schema->string()->description('Auction start ISO 8601 (null = open immediately)'),
            'bid_end_at' => $schema->string()->description('Auction end ISO 8601 (null = parent closes manually)'),
        ];
    }

    public function handle(Request $request): Response
    {
        return match ($request->get('action')) {
            'points_bank' => $this->pointsBank($request),
            'points_leaderboard' => $this->pointsLeaderboard($request),
            'points_feed' => $this->pointsFeed($request),
            'points_kudos' => $this->pointsKudos($request),
            'points_stack_kudos' => $this->pointsStackKudos($request),
            'points_deduct' => $this->pointsDeduct($request),
            'request_list' => $this->requestList($request),
            'request_approve' => $this->requestApprove($request),
            'request_deny' => $this->requestDeny($request),
            'reward_list' => $this->rewardList(),
            'reward_create' => $this->rewardCreate($request),
            'reward_update' => $this->rewardUpdate($request),
            'reward_delete' => $this->rewardDelete($request),
            'reward_purchase' => $this->rewardPurchase($request),
            'reward_bid' => $this->rewardBid($request),
            'reward_close_auction' => $this->rewardCloseAuction($request),
            'reward_cancel_auction' => $this->rewardCancelAuction($request),
            'purchase_history' => $this->purchaseHistory($request),
            default => Response::error("Unknown action: {$request->get('action')}"),
        };
    }

    private function pointsBank(Request $request): Response
    {
        $userId = $request->get('user_id');
        $user = $userId
            ? $this->family()->members()->findOrFail($userId)
            : $this->user();

        return Response::json([
            'user' => $user->name,
            'balance' => $user->pointBank(),
        ]);
    }

    private function pointsLeaderboard(Request $request): Response
    {
        $pointsService = app(PointsService::class);
        $period = $request->get('period');
        $leaderboard = $pointsService->getLeaderboard($this->family(), $period);

        return Response::json([
            'period' => $period ?? $this->family()->getLeaderboardPeriod(),
            'leaderboard' => $leaderboard->toArray(),
        ]);
    }

    private function pointsFeed(Request $request): Response
    {
        $limit = $request->get('limit', 20);

        $transactions = PointTransaction::where('family_id', $this->familyId())
            ->with(['user:id,name', 'awardedBy:id,name'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return Response::json([
            'feed' => $transactions->map(fn ($t) => [
                'id' => $t->id,
                'user' => $t->user?->name,
                'type' => $t->type->value,
                'points' => $t->points,
                'description' => $t->description,
                'awarded_by' => $t->awardedBy?->name,
                'created_at' => $t->created_at->toIso8601String(),
            ])->toArray(),
        ]);
    }

    private function pointsKudos(Request $request): Response
    {
        $userId = $request->get('user_id');
        $reason = $request->get('reason');
        if (! $userId || ! $reason) {
            return Response::error('user_id and reason are required for points_kudos.');
        }

        $user = $this->user();
        $family = $this->family();
        $target = $family->members()->findOrFail($userId);

        $pointsService = app(PointsService::class);

        try {
            $transaction = $pointsService->giveKudos($user, $target, $family, $reason);
        } catch (\Exception $e) {
            return Response::error($e->getMessage());
        }

        return Response::json([
            'message' => "Kudos given to {$target->name}! +1 point.",
            'transaction_id' => $transaction->id,
        ]);
    }

    private function pointsStackKudos(Request $request): Response
    {
        $transactionId = $request->get('transaction_id');
        if (! $transactionId) {
            return Response::error('transaction_id is required for points_stack_kudos.');
        }

        $user = $this->user();
        $family = $this->family();

        $source = PointTransaction::where('family_id', $family->id)->find($transactionId);
        if (! $source) {
            return Response::error('Kudos transaction not found in this family.');
        }

        // @phpstan-ignore-next-line ternary.alwaysTrue — Larastan reads $type as string, but cast may return enum at runtime
        $typeValue = is_string($source->type) ? $source->type : $source->type->value;
        if ($typeValue !== PointTransactionType::Kudos->value) {
            return Response::error('You can only stack onto a kudos.');
        }

        if ($source->stacked_from_transaction_id !== null) {
            return Response::error('Stack onto the original kudo, not another stack.');
        }

        $userId = (string) $user->id;

        if ((string) $source->user_id === $userId) {
            return Response::error("You can't stack onto kudos given to you.");
        }

        if ((string) $source->awarded_by === $userId) {
            return Response::error('You already gave this kudo.');
        }

        $alreadyStacked = PointTransaction::where('stacked_from_transaction_id', $source->id)
            ->where('awarded_by', $user->id)
            ->exists();

        if ($alreadyStacked) {
            return Response::error("You've already +1'd this kudo.");
        }

        $target = $family->members()->findOrFail($source->user_id);
        $pointsService = app(PointsService::class);

        try {
            $stack = $pointsService->giveKudos($user, $target, $family, $source->description, $source);
        } catch (UniqueConstraintViolationException) {
            return Response::error("You've already +1'd this kudo.");
        } catch (\Exception $e) {
            return Response::error($e->getMessage());
        }

        return Response::json([
            'message' => "+1 kudos to {$target->name}.",
            'transaction_id' => $stack->id,
            'stacked_from_transaction_id' => $source->id,
        ]);
    }

    private function pointsDeduct(Request $request): Response
    {
        if ($denied = $this->requireParent()) {
            return $denied;
        }

        $userId = $request->get('user_id');
        $reason = $request->get('reason');
        if (! $userId || ! $reason) {
            return Response::error('user_id and reason are required for points_deduct.');
        }

        $points = $request->get('points');
        if (! $points || $points < 1) {
            return Response::error('points must be a positive integer for deduction.');
        }

        $user = $this->user();
        $target = $this->family()->members()->findOrFail($userId);

        $pointsService = app(PointsService::class);
        $transaction = $pointsService->deductPoints($user, $target, $points, $reason);

        return Response::json([
            'message' => "Deducted {$points} points from {$target->name}.",
            'transaction_id' => $transaction->id,
        ]);
    }

    private function requestList(Request $request): Response
    {
        $user = $this->user();
        $query = PointRequest::where('family_id', $this->familyId())
            ->with(['user:id,name', 'reviewer:id,name']);

        if (! $user->isParent()) {
            $query->where('user_id', $user->id);
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        } else {
            $query->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END");
        }

        $requests = $query->orderByDesc('created_at')->limit(50)->get();

        return Response::json([
            'requests' => $requests->map(fn ($r) => [
                'id' => $r->id,
                'user' => $r->user?->name,
                'points' => $r->points,
                'reason' => $r->reason,
                'status' => $r->status->value,
                'reviewer' => $r->reviewer?->name,
                'reviewed_at' => $r->reviewed_at?->toIso8601String(),
                'created_at' => $r->created_at->toIso8601String(),
            ])->toArray(),
        ]);
    }

    private function requestApprove(Request $request): Response
    {
        if ($denied = $this->requireParent()) {
            return $denied;
        }

        $requestId = $request->get('request_id');
        if (! $requestId) {
            return Response::error('request_id is required for request_approve.');
        }

        $pointRequest = PointRequest::where('family_id', $this->familyId())->findOrFail($requestId);

        if (! $pointRequest->isPending()) {
            return Response::error('This request has already been reviewed.');
        }

        $user = $this->user();

        $pointRequest->update([
            'status' => PointRequestStatus::Approved,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        $transaction = PointTransaction::create([
            'family_id' => $pointRequest->family_id,
            'user_id' => $pointRequest->user_id,
            'type' => PointTransactionType::PointRequest,
            'points' => $pointRequest->points,
            'description' => "Approved request: {$pointRequest->reason}",
            'source_type' => PointRequest::class,
            'source_id' => $pointRequest->id,
            'awarded_by' => $user->id,
        ]);

        $requestingUser = $pointRequest->user;
        $badgeService = app(BadgeService::class);
        $newBadges = $badgeService->checkAndAwardBadges($requestingUser);

        $result = [
            'message' => "Approved {$pointRequest->points} points for {$requestingUser->name}.",
            'transaction_id' => $transaction->id,
        ];

        if (! empty($newBadges)) {
            $result['badges_earned'] = collect($newBadges)->map(fn ($b) => $b->name)->toArray();
        }

        return Response::json($result);
    }

    private function requestDeny(Request $request): Response
    {
        if ($denied = $this->requireParent()) {
            return $denied;
        }

        $requestId = $request->get('request_id');
        if (! $requestId) {
            return Response::error('request_id is required for request_deny.');
        }

        $pointRequest = PointRequest::where('family_id', $this->familyId())->findOrFail($requestId);

        if (! $pointRequest->isPending()) {
            return Response::error('This request has already been reviewed.');
        }

        $pointRequest->update([
            'status' => PointRequestStatus::Denied,
            'reviewed_by' => $this->user()->id,
            'reviewed_at' => now(),
        ]);

        return Response::text("Denied point request from {$pointRequest->user->name}.");
    }

    private function rewardList(): Response
    {
        $rewards = Reward::where('family_id', $this->familyId())
            ->withCount('purchases')
            ->orderBy('sort_order')
            ->get();

        return Response::json([
            'rewards' => $rewards->map(function (Reward $r) {
                /** @var Carbon|null $expiresAt */
                $expiresAt = $r->expires_at;
                /** @var RewardVisibility $visibility */
                $visibility = $r->visibility;

                return [
                    'id' => $r->id,
                    'title' => $r->title,
                    'description' => $r->description,
                    'point_cost' => $r->point_cost,
                    'icon' => $r->icon,
                    'is_active' => $r->is_active,
                    'times_purchased' => $r->purchases_count,
                    'quantity' => $r->quantity,
                    'remaining_stock' => $r->remainingStock(),
                    'is_expired' => $r->isExpired(),
                    'is_purchasable' => $r->isPurchasable(),
                    'expires_at' => $expiresAt?->toIso8601String(),
                    'visibility' => $visibility->value,
                    'visible_to' => $r->visible_to,
                    'min_age' => $r->min_age,
                    'max_age' => $r->max_age,
                    'reward_type' => $r->reward_type->value,
                    'min_bid' => $r->min_bid,
                    'bid_start_at' => $r->bid_start_at?->toIso8601String(),
                    'bid_end_at' => $r->bid_end_at?->toIso8601String(),
                    'bidding_open' => $r->isAuction() ? $r->isBiddingOpen() : null,
                    'highest_bid' => $r->isAuction() ? $r->highestBid()?->bid_amount : null,
                    'total_bids' => $r->isAuction() ? $r->activeBids()->count() : null,
                ];
            })->toArray(),
        ]);
    }

    private function rewardCreate(Request $request): Response
    {
        if ($denied = $this->authorize('create', Reward::class)) {
            return $denied;
        }

        $title = $request->get('title');
        if (! $title) {
            return Response::error('title is required to create a reward.');
        }

        $pointCost = $request->get('point_cost');
        if (! $pointCost || $pointCost < 1) {
            return Response::error('point_cost must be a positive integer.');
        }

        $visibleTo = $this->parseVisibleTo($request->get('visible_to'));

        $reward = Reward::create([
            'family_id' => $this->familyId(),
            'created_by' => $this->user()->id,
            'title' => $title,
            'description' => $request->get('description'),
            'point_cost' => $pointCost,
            'icon' => $request->get('icon'),
            'is_active' => $request->get('is_active', true),
            'sort_order' => Reward::where('family_id', $this->familyId())->max('sort_order') + 1,
            'quantity' => $request->get('quantity'),
            'expires_at' => $request->get('expires_at'),
            'visibility' => $request->get('visibility', 'everyone'),
            'visible_to' => $visibleTo,
            'min_age' => $request->get('min_age'),
            'max_age' => $request->get('max_age'),
            'reward_type' => $request->get('reward_type', 'standard'),
            'min_bid' => $request->get('min_bid'),
            'bid_start_at' => $request->get('bid_start_at'),
            'bid_end_at' => $request->get('bid_end_at'),
        ]);

        /** @var Carbon|null $expiresAt */
        $expiresAt = $reward->expires_at;
        /** @var RewardVisibility $visibility */
        $visibility = $reward->visibility;

        return Response::json([
            'message' => "Reward \"{$reward->title}\" created ({$reward->point_cost} pts).",
            'reward' => [
                'id' => $reward->id,
                'title' => $reward->title,
                'point_cost' => $reward->point_cost,
                'quantity' => $reward->quantity,
                'expires_at' => $expiresAt?->toIso8601String(),
                'visibility' => $visibility->value,
            ],
        ]);
    }

    private function rewardUpdate(Request $request): Response
    {
        $rewardId = $request->get('reward_id');
        if (! $rewardId) {
            return Response::error('reward_id is required for reward_update.');
        }

        $reward = Reward::where('family_id', $this->familyId())->findOrFail($rewardId);

        if ($denied = $this->authorize('update', $reward)) {
            return $denied;
        }

        // mergeUpdates distinguishes "field absent" from "field present-but-null".
        // Nullable columns (quantity, expires_at, min_age, max_age, min_bid,
        // bid_start_at, bid_end_at) can now be cleared via null/"" on update.
        $updates = $this->mergeUpdates(
            $request,
            simpleFields: ['title', 'point_cost', 'is_active', 'visibility', 'reward_type'],
            nullableFields: [
                'description', 'icon', 'quantity', 'expires_at',
                'min_age', 'max_age', 'min_bid', 'bid_start_at', 'bid_end_at',
            ],
        );

        // visible_to needs custom parsing (comma-separated UUIDs → array, scoped to family).
        $input = $request->all();
        if (array_key_exists('visible_to', $input)) {
            $updates['visible_to'] = $this->parseVisibleTo($input['visible_to']);
        }

        $reward->update($updates);

        /** @var Carbon|null $expiresAt */
        $expiresAt = $reward->expires_at;
        /** @var RewardVisibility $visibility */
        $visibility = $reward->visibility;

        return Response::json([
            'message' => "Reward \"{$reward->title}\" updated.",
            'reward' => [
                'id' => $reward->id,
                'title' => $reward->title,
                'point_cost' => $reward->point_cost,
                'is_active' => $reward->is_active,
                'quantity' => $reward->quantity,
                'remaining_stock' => $reward->remainingStock(),
                'expires_at' => $expiresAt?->toIso8601String(),
                'visibility' => $visibility->value,
            ],
        ]);
    }

    private function rewardDelete(Request $request): Response
    {
        $rewardId = $request->get('reward_id');
        if (! $rewardId) {
            return Response::error('reward_id is required for reward_delete.');
        }

        $reward = Reward::where('family_id', $this->familyId())->findOrFail($rewardId);

        if ($denied = $this->authorize('delete', $reward)) {
            return $denied;
        }
        $title = $reward->title;
        $reward->delete();

        return Response::text("Reward \"{$title}\" deleted.");
    }

    private function rewardPurchase(Request $request): Response
    {
        $rewardId = $request->get('reward_id');
        if (! $rewardId) {
            return Response::error('reward_id is required for reward_purchase.');
        }

        $reward = Reward::where('family_id', $this->familyId())
            ->where('is_active', true)
            ->findOrFail($rewardId);

        if ($denied = $this->authorize('purchase', $reward)) {
            return $denied;
        }

        $user = $this->user();
        $pointsService = app(PointsService::class);

        try {
            $result = $pointsService->redeemReward($reward, $user);
        } catch (\Exception $e) {
            return Response::error($e->getMessage());
        }

        $badgeService = app(BadgeService::class);
        $newBadges = $badgeService->checkAndAwardBadges($user);

        $response = [
            'message' => "Purchased \"{$reward->title}\" for {$reward->point_cost} points!",
            'remaining_balance' => $user->pointBank(),
            'remaining_stock' => $result['reward']->remainingStock(),
        ];

        if (! empty($newBadges)) {
            $response['badges_earned'] = collect($newBadges)->map(fn ($b) => $b->name)->toArray();
        }

        return Response::json($response);
    }

    private function rewardBid(Request $request): Response
    {
        $rewardId = $request->get('reward_id');
        if (! $rewardId) {
            return Response::error('reward_id is required for reward_bid.');
        }

        $bidAmount = $request->get('bid_amount');
        if (! $bidAmount || $bidAmount < 1) {
            return Response::error('bid_amount must be a positive integer.');
        }

        $reward = Reward::where('family_id', $this->familyId())->findOrFail($rewardId);

        if ($denied = $this->authorize('bid', $reward)) {
            return $denied;
        }

        $user = $this->user();
        $auctionService = app(AuctionService::class);

        try {
            $bid = $auctionService->placeBid($reward, $user, (int) $bidAmount);
        } catch (\Exception $e) {
            return Response::error($e->getMessage());
        }

        return Response::json([
            'message' => "Bid of {$bid->bid_amount} points placed on \"{$reward->title}\".",
            'bid_amount' => $bid->bid_amount,
            'held_points' => $bid->held_points,
            'available_points' => $user->availablePoints(),
        ]);
    }

    private function rewardCloseAuction(Request $request): Response
    {
        $rewardId = $request->get('reward_id');
        if (! $rewardId) {
            return Response::error('reward_id is required for reward_close_auction.');
        }

        $reward = Reward::where('family_id', $this->familyId())->findOrFail($rewardId);

        if ($denied = $this->authorize('closeAuction', $reward)) {
            return $denied;
        }

        $auctionService = app(AuctionService::class);

        try {
            $winner = $auctionService->closeAuction($reward);
        } catch (\Exception $e) {
            return Response::error($e->getMessage());
        }

        return Response::json([
            'message' => $winner
                ? "Auction closed! {$winner->user->name} won with {$winner->bid_amount} points."
                : 'Auction closed with no bids.',
            'winner' => $winner ? ['user_name' => $winner->user->name, 'bid_amount' => $winner->bid_amount] : null,
        ]);
    }

    private function rewardCancelAuction(Request $request): Response
    {
        $rewardId = $request->get('reward_id');
        if (! $rewardId) {
            return Response::error('reward_id is required for reward_cancel_auction.');
        }

        $reward = Reward::where('family_id', $this->familyId())->findOrFail($rewardId);

        if ($denied = $this->authorize('cancelAuction', $reward)) {
            return $denied;
        }

        $auctionService = app(AuctionService::class);

        try {
            $auctionService->cancelAuction($reward);
        } catch (\Exception $e) {
            return Response::error($e->getMessage());
        }

        return Response::json([
            'message' => "Auction for \"{$reward->title}\" cancelled. All held points released.",
        ]);
    }

    private function purchaseHistory(Request $request): Response
    {
        $query = RewardPurchase::where('family_id', $this->familyId())
            ->with(['reward:id,title,point_cost,icon', 'user:id,name']);

        if ($userId = $request->get('user_id')) {
            $query->where('user_id', $userId);
        }

        $purchases = $query->orderByDesc('purchased_at')->limit(50)->get();

        return Response::json([
            'purchases' => $purchases->map(fn ($p) => [
                'id' => $p->id,
                'reward' => $p->reward?->title,
                'icon' => $p->reward?->icon,
                'user' => $p->user?->name,
                'points_spent' => $p->points_spent,
                'purchased_at' => $p->purchased_at->toIso8601String(),
            ])->toArray(),
        ]);
    }

    private function parseVisibleTo(?string $value): ?array
    {
        if (! $value) {
            return null;
        }

        $ids = array_filter(array_map('trim', explode(',', $value)));

        if (empty($ids)) {
            return null;
        }

        return User::where('family_id', $this->familyId())
            ->whereIn('id', $ids)
            ->pluck('id')
            ->toArray();
    }
}
