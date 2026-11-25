<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;

/** Shared helpers of the v1 API controllers. */
abstract class ApiController extends Controller
{
    protected function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : abort(401);
    }

    /** The company a seller account works for. */
    protected function company(Request $request): Company
    {
        $user = $this->user($request);

        abort_unless($user->isSeller() && $user->company_id !== null, 403, __('Only seller accounts can do this.'));

        return Company::query()->findOrFail($user->company_id);
    }

    /** Rows per page: what the client asked for, within the limits. */
    protected function perPage(Request $request): int
    {
        $asked = (int) $request->query('per_page', (int) config('auction.api.per_page'));

        return max(1, min($asked, (int) config('auction.api.per_page_max')));
    }
}
