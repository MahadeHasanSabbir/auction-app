<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform staff ask a buyer or a company for identity documents.
        // Exactly one of user_id and company_id is set.
        Schema::create('kyc_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->foreignId('company_id')->nullable()->constrained();
            $table->string('status', 32)->default('requested');
            $table->text('note')->nullable();
            $table->foreignId('requested_by')->constrained('users');
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->dateTime('reviewed_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'status']);
            $table->index(['company_id', 'status']);
        });

        // Files live on the private "kyc" disk and are never served directly.
        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_request_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
        });

        // Values platform staff can change at runtime; config/auction.php
        // holds the defaults.
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('kyc_documents');
        Schema::dropIfExists('kyc_requests');
    }
};
