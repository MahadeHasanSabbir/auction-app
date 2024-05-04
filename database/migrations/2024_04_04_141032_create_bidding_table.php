<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id()->primary()->index();
            $table->string('name');
            $table->string('category');
            $table->string('description');
            $table->integer('starting_price');
            $table->string('picture');
            $table->timestamps();
        });
        
        Schema::create('auctions', function (Blueprint $table) {
            $table->id()->primary();
            $table->foreignId('product_id')->constrained('products')->cascadeOnUpdate()->cascadeOnDelete();
            $table->integer('host_id');
            $table->string('host_name');
            $table->string('name');
            $table->dateTime('start_time')->index();
            $table->dateTime('end_time');
            $table->integer('final_price')->nullable();
            $table->integer('no_of_bid')->default(0);
            $table->bigInteger('owner_id')->nullable();
            $table->string('owner_name')->nullable();
            $table->integer('status')->default(0);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id()->primary();
            $table->foreignId('auction_id')->constrained('auctions')->cascadeOnUpdate()->cascadeOnDelete();
            /* $table->unsignedBigInteger('bidding_id');
            $table->foreign('bidding_id')->references('id')->on('bidding')->onUpdate('cascade')->onDelete('cascade'); */
            $table->integer('amount');
            $table->integer('commission');
            $table->string('gateway');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bidding');
        Schema::dropIfExists('product');
        Schema::dropIfExists('payment');
    }
};
