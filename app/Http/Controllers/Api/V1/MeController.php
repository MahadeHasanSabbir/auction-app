<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Account
 *
 * The signed-in account. Tokens are created by the account holder on the website (Profile → API tokens).
 *
 * @authenticated
 */
class MeController extends ApiController
{
    /**
     * Who am I
     *
     * The account the token belongs to, with the company for seller accounts.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $company = $user->company_id === null ? null : Company::query()->find($user->company_id, ['id', 'name', 'slug']);
        $token = $user->currentAccessToken();

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'email_verified' => $user->hasVerifiedEmail(),
            'identity_status' => $user->kyc_status->value,
            'company' => $company === null ? null : ['id' => $company->id, 'name' => $company->name, 'slug' => $company->slug],
            'token' => [
                'name' => $token->name,
                'abilities' => $token->abilities,
                'expires_at' => $token->expires_at?->toIso8601ZuluString(),
            ],
        ]]);
    }

    /**
     * Revoke this token
     *
     * The token used for this request stops working at once.
     */
    public function revoke(Request $request): JsonResponse
    {
        $this->user($request)->currentAccessToken()->delete();

        return response()->json(['message' => __('Token revoked.')]);
    }
}
