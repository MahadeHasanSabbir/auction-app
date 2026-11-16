<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->unsignedBigInteger('price_monthly');
            $table->unsignedBigInteger('price_yearly')->nullable();
            // Seats: the company owner plus team members.
            $table->unsignedInteger('max_users');
            $table->unsignedInteger('max_active_auctions')->nullable();
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->json('features')->nullable();
            // Non-public plans are custom plans the system owner assigns.
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'is_public', 'sort_order']);
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('legal_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('banner_path')->nullable();
            $table->text('about')->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('kyc_status', 32)->default('not_required');
            $table->foreignId('user_type_id')->nullable()->constrained()->nullOnDelete();
            // Trusted companies skip the platform review of each auction.
            $table->boolean('auto_approve')->default(false);
            // Rating cache; the average is rating_sum / rating_count.
            $table->unsignedInteger('rating_sum')->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('suspended_at')->nullable();
            $table->string('suspension_reason', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });

        Schema::create('company_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by')->constrained('users');
            $table->dateTime('expires_at');
            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'accepted_at', 'revoked_at']);
            $table->index('email');
        });

        // One row per company; a plan change updates it in place.
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->string('status', 32);
            $table->string('billing_interval', 16)->default('monthly');
            // Price of one billing period, fixed when the period starts.
            $table->unsignedBigInteger('price');
            // Custom limits agreed with the system owner; null means "use the plan".
            $table->unsignedInteger('max_users_override')->nullable();
            $table->unsignedInteger('max_active_auctions_override')->nullable();
            $table->dateTime('trial_ends_at')->nullable();
            $table->dateTime('current_period_start')->nullable();
            $table->dateTime('current_period_end')->nullable();
            $table->dateTime('grace_ends_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->string('gateway', 32)->nullable();
            $table->string('gateway_customer_id')->nullable();
            $table->string('gateway_payment_method_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'current_period_end']);
        });

        // Commission terms the system owner agreed with one company.
        Schema::create('company_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('use_case', 32);
            $table->string('basis', 32)->default('final_price');
            $table->unsignedInteger('rate_bps')->default(0);
            $table->unsignedBigInteger('fixed_amount')->default(0);
            $table->unsignedBigInteger('min_amount')->nullable();
            $table->unsignedBigInteger('max_amount')->nullable();
            $table->dateTime('effective_from');
            $table->dateTime('effective_until')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'use_case', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_agreements');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('company_invitations');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
        });

        Schema::dropIfExists('companies');
        Schema::dropIfExists('plans');
    }
};
