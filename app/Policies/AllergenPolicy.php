<?php

namespace App\Policies;

use App\Models\Allergen;
use App\Models\User;

class AllergenPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isParent();
    }

    /**
     * Updates are restricted to family-scoped customs by a parent in the same family.
     * Global Big 9 rows (family_id null) are immutable.
     */
    public function update(User $user, Allergen $allergen): bool
    {
        if ($allergen->family_id === null) {
            return false;
        }

        return $user->isParent() && $allergen->family_id === $user->family_id;
    }

    public function delete(User $user, Allergen $allergen): bool
    {
        return $this->update($user, $allergen);
    }
}
