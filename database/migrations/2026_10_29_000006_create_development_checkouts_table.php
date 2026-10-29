<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('development_checkouts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('cart_id')->unique()->constrained(config('lunar.database.table_prefix').'carts')->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained(config('lunar.database.table_prefix').'orders')->restrictOnDelete();
            $table->unsignedInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('expires_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('development_checkouts');
    }
};
