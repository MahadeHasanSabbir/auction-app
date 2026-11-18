<?php

namespace Database\Factories;

use App\Enums\KycStatus;
use App\Models\KycRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A buyer's request waiting for documents by default.
 *
 * @extends Factory<KycRequest>
 */
class KycRequestFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $user = User::factory();

        return [
            'user_id' => $user,
            'company_id' => null,
            'status' => KycStatus::Requested,
            'requested_by' => $user,
        ];
    }

    public function submitted(): static
    {
        return $this->state(['status' => KycStatus::Submitted, 'submitted_at' => now()]);
    }
}
