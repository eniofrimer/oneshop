<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lunar\Base\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table($this->prefix.'product_variants', function (Blueprint $table) {
                $table->string('oneshop_normalized_sku', 255)->nullable()->collation('utf8mb4_bin')
                    ->storedAs("NULLIF(UPPER(TRIM(sku)), '')")
                    ->unique('oneshop_variants_normalized_sku_unique');
            });

            return;
        }

        $table = DB::connection()->getQueryGrammar()->wrapTable($this->prefix.'product_variants');
        // Include archived variants: their SKU may still identify historical orders.
        // Existing duplicates fail the migration rather than silently rewriting stock identities.
        DB::statement("CREATE UNIQUE INDEX oneshop_variants_normalized_sku_unique ON {$table} (UPPER(TRIM(sku))) WHERE sku IS NOT NULL AND TRIM(sku) <> ''");
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table($this->prefix.'product_variants', function (Blueprint $table) {
                $table->dropUnique('oneshop_variants_normalized_sku_unique');
                $table->dropColumn('oneshop_normalized_sku');
            });

            return;
        }

        DB::statement('DROP INDEX IF EXISTS oneshop_variants_normalized_sku_unique');
    }
};
