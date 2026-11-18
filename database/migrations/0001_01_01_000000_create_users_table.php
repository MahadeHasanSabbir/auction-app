<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin-managed account types ("Standard buyer", "Premium seller", …).
        // Fee rules can target one type.
        Schema::create('user_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('account_kind', 16);
            $table->string('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['account_kind', 'is_active']);
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('role', 32);
            // Set for company owners and members only. The foreign key is
            // added by the companies migration.
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreignId('user_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kyc_status', 32)->default('not_required');
            $table->boolean('is_active')->default(true);
            // Why platform staff suspended the account (for staff; sign-in fails like a wrong password).
            $table->string('suspension_reason', 500)->nullable();
            // Set when the system owner gave a temporary password; cleared by the first change.
            $table->boolean('password_change_required')->default(false);
            $table->string('phone', 32)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('locale', 12)->nullable();
            $table->string('timezone', 64)->nullable();
            // Which notifications the user wants, by category and channel; null means the defaults.
            $table->json('notification_preferences')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['role', 'is_active']);
            $table->index(['company_id', 'role']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        // Emailed one-time codes. Only a hash of the code is stored.
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 32);
            $table->string('code_hash');
            $table->string('sent_to');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('expires_at');
            $table->dateTime('consumed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'purpose', 'consumed_at']);
        });

        // Browsers that may skip the login code for a while.
        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('user_agent', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });

        // Sign-in through another provider (Google for buyers): one row per
        // provider account, linked to one user.
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_id');
            $table->string('email')->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_id']);
            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
        Schema::dropIfExists('trusted_devices');
        Schema::dropIfExists('otp_codes');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('user_types');
    }
};
