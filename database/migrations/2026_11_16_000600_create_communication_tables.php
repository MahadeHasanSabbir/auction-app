<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Either the chat between a buyer and a company about one order, or a
        // support ticket with platform staff.
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16);
            $table->foreignId('order_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            // The selling company of an order chat; any member may answer.
            $table->foreignId('company_id')->nullable()->constrained();
            $table->string('subject')->nullable();
            $table->string('status', 16)->default('open');
            $table->foreignId('opened_by')->nullable()->constrained('users');
            // A ticket from the public contact form has no account.
            $table->string('guest_name')->nullable();
            $table->string('guest_email')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users');
            $table->dateTime('last_message_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status', 'last_message_at']);
            $table->index(['company_id', 'last_message_at']);
        });

        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->dateTime('last_read_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            // Null for a guest or a system message.
            $table->foreignId('user_id')->nullable()->constrained();
            $table->text('body');
            $table->string('attachment_path')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->string('audience', 16)->default('all');
            $table->dateTime('published_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['audience', 'published_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notices');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
    }
};
