<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class AllergenAcknowledgementRequired extends RuntimeException
{
    /**
     * @param  array<int, array{member_id: string, member_name: string, allergen_id: string, allergen_name: string, presence: string}>  $hits
     */
    public function __construct(public readonly array $hits)
    {
        parent::__construct('This recipe contains allergens for one or more family members. Acknowledge to proceed.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'requires_acknowledgement' => true,
            'hits' => $this->hits,
        ], 409);
    }
}
