<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auctions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->string('title');
            $table->string('status', 32)->default('draft');
            $table->string('close_reason', 32)->nullable();

            // Prices, in minor units.
            $table->unsignedBigInteger('starting_price');
            $table->unsignedBigInteger('reserve_price')->nullable();
            $table->unsignedBigInteger('buy_now_price')->nullable();
            $table->unsignedBigInteger('min_increment');

            // Cached bidding state, written only by the bidding actions under
            // a row lock. current_price equals starting_price until the first bid.
            $table->unsignedBigInteger('current_price');
            $table->unsignedInteger('bid_count')->default(0);
            $table->foreignId('leader_id')->nullable()->constrained('users');
            $table->boolean('reserve_met')->default(false);

            // The seller proposes a schedule; the platform approves the real one.
            $table->dateTime('proposed_starts_at')->nullable();
            $table->dateTime('proposed_ends_at')->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->dateTime('original_ends_at')->nullable();
            $table->unsignedSmallInteger('extension_count')->default(0);

            // Anti-sniping settings and the fees, fixed at approval so later
            // changes to settings or agreements do not alter a running auction.
            $table->unsignedSmallInteger('anti_snipe_window_seconds')->nullable();
            $table->unsignedSmallInteger('anti_snipe_extend_seconds')->nullable();
            $table->unsignedSmallInteger('anti_snipe_max_extensions')->nullable();
            $table->json('fee_snapshot')->nullable();

            // Review.
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->dateTime('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // Outcome.
            $table->foreignId('winner_id')->nullable()->constrained('users');
            $table->unsignedBigInteger('final_price')->nullable();
            $table->dateTime('closed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'ends_at']);
            $table->index(['status', 'starts_at']);
            $table->index(['company_id', 'status']);
        });

        // Append-only. seq numbers the bids of one auction without gaps.
        Schema::create('bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->unsignedBigInteger('amount');
            $table->string('type', 16);
            $table->unsignedInteger('seq');
            $table->timestamp('created_at')->nullable();

            $table->unique(['auction_id', 'seq']);
            $table->index(['user_id', 'auction_id']);
        });

        // A buyer's standing maximum for one auction. Never shown to others.
        Schema::create('auto_bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->unsignedBigInteger('max_amount');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['auction_id', 'user_id']);
        });

        Schema::create('watchlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'auction_id']);
        });

        // Lets a repeated form submission return the first result instead of
        // acting twice.
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('action', 64);
            $table->json('response')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'key']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('watchlists');
        Schema::dropIfExists('auto_bids');
        Schema::dropIfExists('bids');
        Schema::dropIfExists('auctions');
    }
};
