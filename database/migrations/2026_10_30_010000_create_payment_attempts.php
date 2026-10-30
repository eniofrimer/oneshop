<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // This milestone permits one provider attempt per order. An unknown
            // outcome cannot be bypassed by creating another attempt.
            $table->foreignId('order_id')->unique()->constrained(config('lunar.database.table_prefix').'orders')->restrictOnDelete();
            $table->string('provider', 20);
            $table->string('provider_account', 96);
            $table->string('environment', 8);
            $table->unsignedInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->uuid('idempotency_key')->unique();
            $table->text('creation_parameters');
            $table->string('provider_object_id', 128)->nullable();
            $table->text('checkout_url')->nullable();
            $table->string('status', 16);
            $table->string('review_reason', 64)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_account', 'environment', 'provider_object_id'], 'payment_attempts_remote_unique');
            $table->index(['status', 'updated_at']);
        });
        DB::statement('ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_amount CHECK (amount_minor > 0 AND amount_minor <= 2147483647)');
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_state CHECK (status IN ('prepared', 'pending', 'unknown', 'paid', 'expired', 'review'))");
        // Live mode requires a later reviewed migration, not an environment typo.
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_scope CHECK (provider = 'stripe' AND environment = 'test')");

        Schema::create('payment_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('payment_attempt_id')->constrained()->restrictOnDelete();
            $table->string('provider', 20);
            $table->string('provider_account', 96);
            $table->string('environment', 8);
            $table->string('capture_reference', 128);
            $table->string('payment_reference', 128);
            // Actual provider currency is independent of the order currency.
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->string('allocation_status', 16);
            $table->string('review_reason', 64)->nullable();
            $table->foreignId('transaction_id')->nullable()->unique()->constrained(config('lunar.database.table_prefix').'transactions')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['provider', 'provider_account', 'environment', 'capture_reference'], 'payment_receipts_capture_unique');
        });
        DB::statement('ALTER TABLE payment_receipts ADD CONSTRAINT payment_receipts_amount CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE payment_receipts ADD CONSTRAINT payment_receipts_allocation CHECK (allocation_status IN ('pending', 'allocated', 'review'))");

        Schema::create('payment_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 20);
            $table->string('provider_account', 96);
            $table->string('environment', 8);
            $table->string('provider_event_id', 128);
            $table->string('provider_object_id', 128);
            $table->string('event_type', 80);
            $table->char('body_sha256', 64);
            $table->foreignUuid('payment_attempt_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('received');
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_account', 'environment', 'provider_event_id'], 'payment_events_delivery_unique');
            $table->index(['status', 'created_at']);
        });
        DB::statement("ALTER TABLE payment_events ADD CONSTRAINT payment_events_state CHECK (status IN ('received', 'processed', 'review'))");

        Schema::create('payment_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_key', 128)->unique();
            $table->string('topic', 64);
            $table->foreignId('payment_receipt_id')->constrained()->restrictOnDelete();
            $table->json('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['payment_attempts', 'payment_receipts', 'payment_events', 'payment_outbox'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Payment history must be reconciled before removing its schema.');
            }
        }
        Schema::dropIfExists('payment_outbox');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payment_receipts');
        Schema::dropIfExists('payment_attempts');
    }
};
