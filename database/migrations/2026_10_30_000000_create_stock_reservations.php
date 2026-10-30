<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reservation_key');
            $table->foreignId('order_id')->constrained(config('lunar.database.table_prefix').'orders')->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained(config('lunar.database.table_prefix').'product_variants')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('state', 16);
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason', 40)->nullable();
            $table->timestamps();
            $table->unique(['reservation_key', 'product_variant_id'], 'stock_reservations_key_variant_unique');
            $table->index(['product_variant_id', 'state'], 'stock_reservations_variant_state_index');
            $table->index(['order_id', 'state']);
        });
        DB::statement('ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_positive_quantity CHECK (quantity > 0 AND quantity <= 2147483647)');
        DB::statement("ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_valid_state CHECK (state IN ('held', 'consumed', 'released'))");
        Schema::table('development_checkouts', function (Blueprint $table): void {
            // Existing pending simulators retain their original, unreserved path.
            $table->boolean('uses_stock_reservations')->default(false);
        });
    }

    public function down(): void
    {
        if (DB::table('stock_reservations')->exists()) {
            throw new RuntimeException('Reservation history must be reconciled before removing its schema.');
        }
        Schema::table('development_checkouts', fn (Blueprint $table) => $table->dropColumn('uses_stock_reservations'));
        Schema::dropIfExists('stock_reservations');
    }
};
