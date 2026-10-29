<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_methods', function (Blueprint $table): void {
            $table->id();
            $table->uuid('code')->unique();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->json('countries');
            $table->unsignedInteger('price_minor');
            $table->foreignId('tax_class_id')->constrained(config('lunar.database.table_prefix').'tax_classes')->restrictOnDelete();
            $table->boolean('enabled')->default(false)->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_methods');
    }
};
