<?php

namespace App\Catalog;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lunar\Models\Product;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ProductImages
{
    public const MAX_FILES = 10;

    public const MAX_KILOBYTES = 5120;

    public static function alternativeText(?Media $image, string $fallback): string
    {
        $text = $image ? trim((string) $image->getCustomProperty('oneshop.alt.'.config('oneshop.primary_locale'), '')) : '';

        return $text !== '' ? $text : $fallback;
    }

    /** @return array{original: string, preview: string, thumbnail: string, srcset: ?string} */
    public static function storefrontSources(Media $image): array
    {
        if ($image->disk !== 'public') {
            throw new \InvalidArgumentException('Storefront images must use the public disk.');
        }
        $original = $image->getUrl();
        // Imported media may keep conversions on a separate private disk.
        if (! in_array($image->conversions_disk, [null, '', 'public'], true)) {
            return ['original' => $original, 'preview' => $original, 'thumbnail' => $original, 'srcset' => null];
        }
        $sources = [];
        foreach (['medium' => 500, 'large' => 800] as $conversion => $width) {
            if ($image->hasGeneratedConversion($conversion)) {
                $sources[] = $image->getUrl($conversion).' '.$width.'w';
            }
        }

        return ['original' => $original, 'preview' => $image->getAvailableUrl(['medium', 'large', 'small']),
            'thumbnail' => $image->getAvailableUrl(['small', 'medium', 'large']),
            'srcset' => $sources === [] ? null : implode(', ', $sources)];
    }

    /** @return Builder<Media> */
    public static function query(Product $product): Builder
    {
        return Media::query()->whereMorphedTo('model', $product)
            ->where('collection_name', config('lunar.media.collection'))
            ->where('disk', 'public')->orderBy('order_column')->orderBy('id');
    }

    /** @return array<string, mixed> */
    public static function formData(Media $image): array
    {
        $data = ['title' => $image->name, 'alt' => []];
        foreach (ProductEditor::locales() as $locale) {
            $data['alt'][$locale] = $image->getCustomProperty('oneshop.alt.'.$locale, '');
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private static function metadataRules(): array
    {
        $rules = ['title' => ['required', 'string', 'max:255'], 'alt' => ['required', 'array:'.implode(',', ProductEditor::locales())]];
        foreach (ProductEditor::locales() as $locale) {
            $rules['alt.'.$locale] = ['nullable', 'string', 'max:255'];
        }

        return $rules;
    }

    /** @param array<string, mixed> $data */
    public function add(Product $product, UploadedFile $file, array $data): Media
    {
        Gate::authorize('update', $product);
        $validated = Validator::make([...$data, 'image' => $file], [
            ...self::metadataRules(),
            'image' => ['required', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.self::MAX_KILOBYTES, Rule::dimensions()->maxWidth(4096)->maxHeight(4096)],
        ])->validate();

        return DB::transaction(function () use ($product, $file, $validated): Media {
            $parent = Product::query()->lockForUpdate()->findOrFail($product->id);
            if (self::query($parent)->count() >= self::MAX_FILES) {
                throw ValidationException::withMessages(['image' => 'Galerija podržava najviše '.self::MAX_FILES.' fotografija.']);
            }
            $image = $parent->addMedia($file)
                ->usingFileName(Str::uuid().'.'.$file->guessExtension())
                ->usingName($validated['title'])
                ->toMediaCollection(config('lunar.media.collection'), 'public');
            $this->saveMetadata($image, $validated);
            $this->normalize($parent);

            return $image->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Product $product, Media $image, array $data): Media
    {
        Gate::authorize('update', $product);
        $validated = Validator::make($data, self::metadataRules())->validate();

        return DB::transaction(function () use ($product, $image, $validated): Media {
            Product::query()->lockForUpdate()->findOrFail($product->id);
            $record = self::query($product)->findOrFail($image->id);
            $this->saveMetadata($record, $validated);

            return $record;
        });
    }

    public function remove(Product $product, Media $image): void
    {
        Gate::authorize('update', $product);
        DB::transaction(function () use ($product, $image): void {
            Product::query()->lockForUpdate()->findOrFail($product->id);
            self::query($product)->findOrFail($image->id)->delete();
            $this->normalize($product);
        });
    }

    /** @param array<int|string> $order */
    public function reorder(Product $product, array $order): void
    {
        Gate::authorize('update', $product);
        Validator::make(['images' => $order], ['images' => ['present', 'array', 'list'], 'images.*' => ['required', 'integer', 'distinct']])->validate();
        DB::transaction(function () use ($product, $order): void {
            Product::query()->lockForUpdate()->findOrFail($product->id);
            $images = self::query($product)->get();
            $ids = array_map(intval(...), $order);
            $current = $images->modelKeys();
            sort($current);
            $sorted = $ids;
            sort($sorted);
            if ($current !== $sorted) {
                throw ValidationException::withMessages(['images' => 'Galerija je promijenjena. Osvježite popis prije promjene redoslijeda.']);
            }
            foreach ($ids as $position => $id) {
                $images->find($id)->forceFill(['order_column' => $position + 1])->save();
            }
            $this->normalize($product);
        });
    }

    /** @param array<string, mixed> $data */
    private function saveMetadata(Media $image, array $data): void
    {
        $image->name = $data['title'];
        foreach (ProductEditor::locales() as $locale) {
            $image->setCustomProperty('oneshop.alt.'.$locale, trim($data['alt'][$locale] ?? ''));
        }
        $image->save();
    }

    private function normalize(Product $product): void
    {
        foreach (self::query($product)->get() as $position => $image) {
            $image->order_column = $position + 1;
            $image->setCustomProperty('primary', $position === 0);
            $image->save();
        }
    }
}
