<?php

namespace App\Filament\Seller\Resources\Auctions\Pages\Concerns;

use App\Actions\Auctions\SaveAuction;
use App\Filament\Support\FormErrors;
use App\Models\Auction;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** Create and edit both hand the form to SaveAuction. */
trait SavesAuction
{
    /** @param  array<string, mixed>  $data */
    protected function saveAuction(?Auction $auction, array $data): Auction
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant instanceof Company, 404);

        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        try {
            return app(SaveAuction::class)->handle($user, $tenant, $auction, $data);
        } catch (ValidationException $e) {
            FormErrors::rethrow($e, ['product_id', 'title', 'starting_price', 'reserve_price', 'buy_now_price', 'min_increment', 'proposed_starts_at', 'proposed_ends_at']);
        }
    }
}
