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

        // Every sign-in, and every failed password for an existing account.
        // Pruned after auction.trust.login_history_days.
        Schema::create('login_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('email')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('method', 32);
            $table->boolean('succeeded');
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index(['ip_address', 'succeeded', 'created_at']);
            $table->index('created_at');
        });

        // Something that looks wrong, for platform staff to look at. One row
        // per kind, auction and user.
        Schema::create('fraud_flags', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 32);
            $table->foreignId('user_id')->constrained();
            $table->foreignId('auction_id')->nullable()->constrained();
            $table->foreignId('company_id')->nullable()->constrained();
            $table->string('ip_address', 45)->nullable();
            $table->json('details')->nullable();
            $table->string('status', 16)->default('open');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->dateTime('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();

            $table->unique(['kind', 'auction_id', 'user_id']);
            $table->index(['status', 'created_at']);
        });

        // A mark against a buyer. Enough active ones suspend the account.
        Schema::create('strikes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('source', 32);
            $table->string('reason', 500);
            $table->nullableMorphs('reference');
            $table->foreignId('issued_by')->nullable()->constrained('users');
            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['user_id', 'revoked_at', 'expires_at']);
        });

        // Email addresses, email domains and IP addresses that may not sign
        // up or sign in. Values are stored lower case.
        Schema::create('blacklist_entries', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16);
            $table->string('value');
            $table->string('reason', 500);
            $table->foreignId('created_by')->constrained('users');
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['type', 'value']);
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
        Schema::dropIfExists('blacklist_entries');
        Schema::dropIfExists('strikes');
        Schema::dropIfExists('fraud_flags');
        Schema::dropIfExists('login_events');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('kyc_documents');
        Schema::dropIfExists('kyc_requests');
    }
};
