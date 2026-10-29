<?php

namespace App\Digital;

use App\Models\DigitalAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DigitalAssetEditor
{
    public const MAX_KILOBYTES = 10240;

    private function product(ProductVariant $variant): Product
    {
        $product = $variant->getRelationValue('product');
        abort_unless($product instanceof Product, 404);

        return $product;
    }

    public function upload(ProductVariant $variant, UploadedFile $file, string $label): DigitalAsset
    {
        $product = $this->product($variant);
        Gate::authorize('update', $product);
        Validator::make(['file' => $file, 'label' => $label], [
            'file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES],
            'label' => ['required', 'string', 'max:255'],
        ])->validate();
        $path = null;
        try {
            return DB::transaction(function () use ($variant, $product, $file, $label, &$path): DigitalAsset {
                Product::query()->lockForUpdate()->findOrFail($product->id);
                $sku = ProductVariant::query()->where('product_id', $product->id)->lockForUpdate()->findOrFail($variant->id);
                if ($sku->shippable) {
                    throw ValidationException::withMessages(['product_variant_id' => 'Odaberite SKU koji ne zahtijeva fizičku dostavu.']);
                }
                $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
                $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: 'download';
                $checksum = hash_file('sha256', $file->getRealPath());
                $path = $file->storeAs('digital-assets/'.Str::uuid(), 'payload', 'private');
                if ($path === false || $checksum === false) {
                    throw new \RuntimeException('The private upload could not be stored.');
                }

                $asset = (new DigitalAsset)->forceFill([
                    'product_variant_id' => $sku->id,
                    'label' => trim($label),
                    'original_name' => Str::limit($name, 200, ''),
                    'path' => $path,
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'size' => $file->getSize(),
                    'sha256' => $checksum,
                ]);
                $asset->save();

                return $asset;
            });
        } catch (Throwable $exception) {
            if (is_string($path)) {
                try {
                    Storage::disk('private')->delete($path);
                } catch (Throwable $cleanupFailure) {
                    report($cleanupFailure);
                }
            }
            throw $exception;
        }
    }

    public function archive(DigitalAsset $asset, bool $archived): void
    {
        $asset = DigitalAsset::query()->findOrFail($asset->getKey());
        $variant = $asset->getRelationValue('variant');
        abort_unless($variant instanceof ProductVariant, 404);
        $product = $this->product($variant);
        Gate::authorize('update', $product);
        DB::transaction(function () use ($asset, $product, $archived): void {
            Product::query()->lockForUpdate()->findOrFail($product->id);
            DigitalAsset::query()->lockForUpdate()->findOrFail($asset->getKey())->forceFill(['archived_at' => $archived ? now() : null])->save();
        });
    }

    public function download(DigitalAsset $asset): StreamedResponse
    {
        $asset = DigitalAsset::query()->findOrFail($asset->getKey());
        $variant = $asset->getRelationValue('variant');
        abort_unless($variant instanceof ProductVariant, 404);
        Gate::authorize('view', $this->product($variant));
        abort_unless(preg_match('/^digital-assets\/[0-9a-f-]{36}\/payload$/D', $asset->path) && Storage::disk('private')->exists($asset->path), 404);
        $stream = Storage::disk('private')->readStream($asset->path);
        abort_unless(is_resource($stream), 404);
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            abort_unless(hash_equals($asset->sha256, hash_final($hash)), 409, 'Privatna datoteka nije prošla provjeru integriteta.');
        } finally {
            fclose($stream);
        }

        return Storage::disk('private')->download($asset->path, $asset->original_name, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
