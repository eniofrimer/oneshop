<?php

use App\Catalog\ProductEditor;
use App\Catalog\ProductPublication;
use App\Catalog\VariantEditor;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lunar\Models\Channel;
use Lunar\Models\CustomerGroup;
use Lunar\Models\Order;
use Lunar\Models\Product;
use Lunar\Models\ProductType;
use Lunar\Models\ProductVariant;
use Lunar\Models\TaxClass;

if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('ONESHOP_INDEXING_FIXTURE') !== '1'
    || ! preg_match('/^oneshop-image-ci-[0-9]+-[0-9]+$/D', (string) getenv('ONESHOP_INDEXING_PROJECT'))) {
    throw new RuntimeException('Use only the explicitly acknowledged isolated production-image job.');
}
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
if (! $app->environment('production') || $connection->getConfig('host') !== 'database'
    || $connection->getDatabaseName() !== 'oneshop' || User::query()->exists()
    || Product::withTrashed()->exists() || ProductVariant::withTrashed()->exists() || Order::query()->exists()) {
    throw new RuntimeException('Refusing a non-empty or non-CI release installation.');
}

$product = DB::transaction(function (): Product {
    $owner = new User;
    $owner->forceFill(['name' => 'CI indexing fixture', 'email' => 'ci-indexing@example.test',
        'password' => Str::random(64), 'is_admin' => true, 'is_super_admin' => true])->save();
    Auth::setUser($owner);
    try {
        $type = ProductType::query()->create(['name' => 'CI indexing fixture']);
        $taxClass = TaxClass::query()->create(['name' => 'CI fixture without tax rates']);
        $product = app(ProductEditor::class)->save(['product_type_id' => $type->id,
            'translations' => ['hr' => ['name' => 'CI SEO public product', 'description' => 'Synthetic data for isolated Docker HTTP acceptance.']]]);
        app(VariantEditor::class)->save($product, ['sku' => 'CI-INDEXING-ONLY', 'tax_class_id' => $taxClass->id,
            'stock' => 3, 'purchasable' => 'in_stock', 'shippable' => true, 'price_eur' => '1.00', 'option_values' => []]);
        app(ProductPublication::class)->publish($product, ['channel_ids' => [Channel::where('default', true)->sole()->id],
            'customer_group_ids' => [CustomerGroup::where('default', true)->sole()->id]]);

        return $product;
    } finally {
        $owner->delete();
    }
});
if (User::query()->exists() || Order::query()->exists()) {
    throw new RuntimeException('The fixture must retain no administrator or order.');
}
echo json_encode(['product_path' => '/proizvodi/'.$product->id, 'owner_removed' => true,
    'orders_placed' => 0, 'scope' => 'One synthetic product in disposable CI database only.'], JSON_THROW_ON_ERROR).PHP_EOL;
