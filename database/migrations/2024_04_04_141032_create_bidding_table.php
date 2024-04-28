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
        Schema::create('bidding', function (Blueprint $table) {
            $table->id()->primary();
            $table->string('name');
            $table->date('start_time')->index();
            $table->date('end_time');
            $table->integer('final_price')->nullable();
            $table->integer('no_of_bid')->default(0);
            $table->string('owner')->nullable();
            $table->boolean('status')->default(0);
            $table->timestamps();
        });

        Schema::create('product', function (Blueprint $table) {
            $table->id()->primary()->index();
            $table->foreignId('bidding_id')->constrained('bidding')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('name');
            $table->string('category');
            $table->string('description');
            $table->integer('starting_price');
            $table->timestamps();
        });
        
        Schema::create('payment', function (Blueprint $table) {
            $table->id()->primary();
            $table->foreignId('bidding_id')->constrained('bidding')->cascadeOnUpdate()->cascadeOnDelete();
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