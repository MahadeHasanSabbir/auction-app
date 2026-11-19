<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Charges set by platform staff: a percentage plus a fixed amount,
        // clamped between min and max. The most specific active rule wins.
        Schema::create('fee_rules', function (Blueprint $table) {
            $table->id();
            $table->string('use_case', 32);
            $table->string('charge_type', 16);
            // Null applies to every user type / every gateway.
            $table->foreignId('user_type_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('gateway', 32)->nullable();
            $table->unsignedInteger('percent_bps')->default(0);
            $table->unsignedBigInteger('fixed_amount')->default(0);
            $table->unsignedBigInteger('min_amount')->nullable();
            $table->unsignedBigInteger('max_amount')->nullable();
            $table->dateTime('effective_from');
            $table->dateTime('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['use_case', 'charge_type', 'is_active', 'effective_from'], 'fee_rules_lookup_index');
        });

        // One wallet per buyer, per company and per platform account.
        // balance and held are caches of the ledger entries and the active
        // holds; they are only changed together with those rows, under a lock.
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16);
            $table->foreignId('user_id')->nullable()->unique()->constrained();
            $table->foreignId('company_id')->nullable()->unique()->constrained();
            $table->string('system_account', 32)->nullable()->unique();
            $table->char('currency', 3);
            // Signed: platform accounts such as gateway clearing run negative.
            $table->bigInteger('balance')->default(0);
            $table->unsignedBigInteger('held')->default(0);
            $table->timestamps();
        });

        // Append-only. The entries of one transaction always sum to zero.
        Schema::create('ledger_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->string('idempotency_key')->nullable()->unique();
            $table->nullableMorphs('reference');
            $table->string('description')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->nullable();

            $table->index(['type', 'created_at']);
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_transaction_id')->constrained();
            $table->foreignId('wallet_id')->constrained();
            // Positive credits the wallet, negative debits it.
            $table->bigInteger('amount');
            $table->string('component', 32)->default('principal');
            // The rule or agreement that produced a charge.
            $table->foreignId('fee_rule_id')->nullable()->constrained();
            $table->foreignId('company_agreement_id')->nullable()->constrained();
            $table->bigInteger('balance_after');
            $table->timestamp('created_at')->nullable();

            $table->index(['wallet_id', 'id']);
        });

        Schema::create('deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('gateway', 32);
            // Our reference sent to the gateway; a repeated callback for the
            // same reference credits once.
            $table->string('tran_id', 64)->unique();
            $table->string('gateway_reference')->nullable();
            // amount is what the payer is charged; net_amount is credited.
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('gateway_charge')->default(0);
            $table->unsignedBigInteger('service_charge')->default(0);
            $table->unsignedBigInteger('net_amount');
            // The rules the charges were quoted from, credited with them.
            $table->foreignId('gateway_fee_rule_id')->nullable()->constrained('fee_rules');
            $table->foreignId('service_fee_rule_id')->nullable()->constrained('fee_rules');
            $table->char('currency', 3);
            $table->string('status', 16)->default('pending');
            $table->foreignId('ledger_transaction_id')->nullable()->constrained();
            $table->json('gateway_payload')->nullable();
            $table->string('failure_reason')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'status']);
            $table->index(['gateway', 'gateway_reference']);
        });

        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained();
            $table->foreignId('requested_by')->constrained('users');
            // amount leaves the wallet; net_amount is paid out.
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('gateway_charge')->default(0);
            $table->unsignedBigInteger('service_charge')->default(0);
            $table->unsignedBigInteger('net_amount');
            $table->foreignId('gateway_fee_rule_id')->nullable()->constrained('fee_rules');
            $table->foreignId('service_fee_rule_id')->nullable()->constrained('fee_rules');
            $table->char('currency', 3);
            $table->string('method', 32);
            // Bank or mobile-wallet details, encrypted by the model.
            $table->text('destination');
            $table->string('status', 16)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->dateTime('reviewed_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->string('payout_reference')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->foreignId('ledger_transaction_id')->nullable()->constrained();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['wallet_id', 'status']);
        });

        // Money set aside for a leading bid or a pending withdrawal.
        Schema::create('wallet_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained();
            $table->string('type', 16);
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('amount');
            $table->foreignId('auction_id')->nullable()->constrained();
            $table->foreignId('withdrawal_request_id')->nullable()->constrained();
            $table->dateTime('released_at')->nullable();
            $table->timestamps();

            // An auction has at most one active bid hold: its leader's.
            // The column is null for every other row, and unique indexes
            // ignore nulls, so the database enforces the rule.
            $table->unsignedBigInteger('active_bid_auction_id')
                ->virtualAs("if(`status` = 'active' and `type` = 'bid', `auction_id`, null)");
            $table->unique('active_bid_auction_id');

            $table->index(['wallet_id', 'status']);
        });

        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('plan_id')->constrained();
            $table->string('number', 32)->nullable()->unique();
            // Our reference at the gateway; replaced on every payment attempt.
            $table->string('tran_id', 64)->nullable()->unique();
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('billing_interval', 16)->default('monthly');
            $table->string('status', 16)->default('open');
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->dateTime('due_at');
            $table->dateTime('paid_at')->nullable();
            $table->string('gateway', 32)->nullable();
            $table->string('gateway_reference')->nullable();
            $table->foreignId('ledger_transaction_id')->nullable()->constrained();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['status', 'due_at']);
        });

        // Invoices and receipts, frozen when issued: the PDF is drawn from
        // this row, never from the live records. Numbers come from
        // invoice_sequences without gaps, one series per kind and year.
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);
            $table->string('number', 32)->unique();
            $table->morphs('subject');
            $table->foreignId('user_id')->nullable()->constrained();
            $table->foreignId('company_id')->nullable()->constrained();
            $table->string('billed_name');
            $table->string('billed_email')->nullable();
            $table->text('billed_address')->nullable();
            $table->char('currency', 3);
            // [{description, amount (signed minor units)}]
            $table->json('lines');
            $table->bigInteger('total');
            // Platform fees among the lines, and the VAT included in them.
            $table->unsignedBigInteger('taxable')->default(0);
            $table->unsignedInteger('tax_rate_bps')->default(0);
            $table->unsignedBigInteger('tax')->default(0);
            $table->foreignId('ledger_transaction_id')->nullable()->constrained();
            $table->dateTime('issued_at');
            $table->timestamps();

            $table->unique(['kind', 'subject_type', 'subject_id']);
            $table->index(['user_id', 'issued_at']);
            $table->index(['company_id', 'issued_at']);
            $table->index('issued_at');
        });

        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('series', 16);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);

            $table->unique(['series', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_sequences');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscription_invoices');
        Schema::dropIfExists('wallet_holds');
        Schema::dropIfExists('withdrawal_requests');
        Schema::dropIfExists('deposits');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_transactions');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('fee_rules');
    }
};
