<?php

namespace App\Policies;

use App\Models\User;

/**
 * Authorization for member allergy profiles. Family scoping + edit rights:
 * - Any family member may VIEW any other family member's profile (allergies
 *   are not private within a family).
 * - Parents may EDIT anyone in the family.
 * - Non-parent members may only edit their own profile.
 * - Cross-family access is treated as not-found at the controller layer.
 */
class UserAllergenPolicy
{
    /**
     * Members of the same family can read each other's allergy profile.
     */
    public function view(User $actor, User $target): bool
    {
        return $actor->family_id !== null
            && $actor->family_id === $target->family_id;
    }

    /**
     * Parents can edit any family member. Members can edit only themselves.
     */
    public function update(User $actor, User $target): bool
    {
        if (! $this->view($actor, $target)) {
            return false;
        }

        return $actor->isParent() || (string) $actor->id === (string) $target->id;
    }
}
