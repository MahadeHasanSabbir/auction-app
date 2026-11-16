<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Created when an auction closes with a winner; the price is already
        // in escrow at that moment.
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('auction_id')->unique()->constrained();
            $table->foreignId('buyer_id')->constrained('users');
            $table->foreignId('company_id')->constrained();
            // amount is the winning price. commission and tax come out of it;
            // buyer_fee is charged on top of it.
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('buyer_fee')->default(0);
            $table->unsignedBigInteger('commission')->default(0);
            $table->unsignedBigInteger('tax')->default(0);
            $table->unsignedBigInteger('seller_payout');
            $table->unsignedBigInteger('refunded_amount')->default(0);
            $table->string('status', 32)->default('in_escrow');
            $table->string('shipping_name')->nullable();
            $table->string('shipping_phone', 32)->nullable();
            $table->string('shipping_address', 500)->nullable();
            $table->dateTime('ship_by_at')->nullable();
            $table->dateTime('shipped_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('auto_release_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'auto_release_at']);
            $table->index(['status', 'ship_by_at']);
            $table->index(['buyer_id', 'status']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('courier');
            $table->string('tracking_number')->nullable();
            $table->string('tracking_url', 500)->nullable();
            $table->string('status', 16)->default('in_transit');
            $table->string('proof_path')->nullable();
            $table->string('note', 500)->nullable();
            $table->dateTime('shipped_at');
            $table->dateTime('delivered_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        // The order timeline shown to the buyer and the company.
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('event', 32);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('description', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['order_id', 'id']);
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('buyer_id')->constrained('users');
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->text('reply')->nullable();
            $table->dateTime('replied_at')->nullable();
            $table->boolean('is_visible')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'is_visible', 'created_at']);
        });

        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opened_by')->constrained('users');
            $table->string('reason');
            $table->text('description')->nullable();
            $table->string('status', 16)->default('open');
            $table->string('resolution', 32)->nullable();
            $table->unsignedBigInteger('refund_amount')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->dateTime('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            // At most one open dispute per order (same technique as the
            // active bid hold in wallet_holds).
            $table->unsignedBigInteger('open_order_id')
                ->virtualAs("if(`status` = 'open', `order_id`, null)");
            $table->unique('open_order_id');

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('orders');
    }
};
