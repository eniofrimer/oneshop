<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digital_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained(config('lunar.database.table_prefix').'product_variants')->restrictOnDelete();
            $table->string('label');
            $table->string('original_name');
            $table->string('path')->unique();
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_assets');
    }
};
