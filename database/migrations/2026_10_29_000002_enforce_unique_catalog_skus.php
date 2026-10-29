<?php

use Illuminate\Support\Facades\DB;
use Lunar\Base\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $table = DB::connection()->getQueryGrammar()->wrapTable($this->prefix.'product_variants');
        // Include archived variants: their SKU may still identify historical orders.
        // Existing duplicates fail the migration rather than silently rewriting stock identities.
        DB::statement("CREATE UNIQUE INDEX oneshop_variants_normalized_sku_unique ON {$table} (UPPER(TRIM(sku))) WHERE sku IS NOT NULL AND TRIM(sku) <> ''");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS oneshop_variants_normalized_sku_unique');
    }
};
