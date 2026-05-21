<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Allergen;
use App\Models\User;
use App\Policies\UserAllergenPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserAllergenController extends Controller
{
    /**
     * Read a member's allergy profile. Auth: any family member can read any
     * other family member's profile (allergies are not private within a family).
     */
    public function index(Request $request, User $user): JsonResponse
    {
        $this->authorizeView($request->user(), $user);

        return response()->json([
            'allergen_ids' => $user->allergens()->pluck('allergens.id')->values(),
            'reviewed_at' => optional($user->allergen_profile_reviewed_at)->toIso8601String(),
        ]);
    }

    /**
     * Replace a member's allergy profile. Auth: parents may edit anyone in the
     * family; members may edit only their own. Setting any IDs counts as a review.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorizeEdit($request->user(), $user);

        $validated = $request->validate([
            'allergen_ids' => ['present', 'array'],
            'allergen_ids.*' => ['uuid'],
        ]);

        $family = $request->user()->currentFamily()->firstOrFail();

        // All submitted IDs must be available to this family (global Big 9 or family customs).
        $valid = Allergen::availableToFamily($family->id)
            ->whereIn('id', $validated['allergen_ids'])
            ->pluck('id')
            ->all();

        if (count($valid) !== count($validated['allergen_ids'])) {
            return response()->json(['message' => 'One or more allergens are not available to this family.'], 422);
        }

        $user->allergens()->sync($valid);
        $user->forceFill(['allergen_profile_reviewed_at' => now()])->save();

        return response()->json([
            'allergen_ids' => $user->allergens()->pluck('allergens.id')->values(),
            'reviewed_at' => $user->allergen_profile_reviewed_at->toIso8601String(),
        ]);
    }

    /**
     * Mark a member's allergy profile as reviewed without changing it (e.g., to
     * confirm "no allergies" and clear the dashboard prompt).
     */
    public function markReviewed(Request $request, User $user): JsonResponse
    {
        $this->authorizeEdit($request->user(), $user);

        $user->forceFill(['allergen_profile_reviewed_at' => now()])->save();

        return response()->json([
            'reviewed_at' => $user->allergen_profile_reviewed_at->toIso8601String(),
        ]);
    }

    /**
     * Cross-family access returns 404 (don't leak existence). Same-family but
     * unauthorized actions return 403.
     */
    private function authorizeView(User $actor, User $target): void
    {
        if ($actor->family_id !== $target->family_id) {
            abort(404);
        }
        if (! (new UserAllergenPolicy)->view($actor, $target)) {
            abort(403);
        }
    }

    private function authorizeEdit(User $actor, User $target): void
    {
        if ($actor->family_id !== $target->family_id) {
            abort(404);
        }
        if (! (new UserAllergenPolicy)->update($actor, $target)) {
            abort(403, 'Only parents can edit another member\'s allergy profile.');
        }
    }
}
