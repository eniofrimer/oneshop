<?php

namespace App\Catalog;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lunar\Models\Collection;
use Lunar\Models\CollectionGroup;

class CategoryEditor
{
    /** @param array<string, mixed> $data */
    public function createGroup(array $data): CollectionGroup
    {
        Gate::authorize('create', CollectionGroup::class);
        $data['handle'] = is_string($data['handle'] ?? null) ? strtolower(trim($data['handle'])) : null;
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'handle' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique(CollectionGroup::class, 'handle')],
        ])->validate();
        try {
            return CollectionGroup::query()->create($validated);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['handle' => 'Ova oznaka grupe već postoji.']);
        }
    }

    /** @param array<string, mixed> $data */
    public function save(array $data, ?Collection $category = null): Collection
    {
        Gate::authorize($category ? 'update' : 'create', $category ?? Collection::class);
        $validated = Validator::make($data, [
            ...CatalogTranslations::rules(),
            'collection_group_id' => ['required', 'integer', Rule::exists(CollectionGroup::class, 'id')],
            'parent_id' => ['nullable', 'integer'],
        ])->validate();

        return DB::transaction(function () use ($validated, $category): Collection {
            // Serialize changes to each scoped nested set before locking its nodes.
            $group = CollectionGroup::query()->lockForUpdate()->findOrFail($validated['collection_group_id']);
            $record = $category ? Collection::query()->where('type', 'static')->lockForUpdate()->findOrFail($category->id) : new Collection;
            if ($record->exists && $record->collection_group_id !== $group->id) {
                throw ValidationException::withMessages(['collection_group_id' => 'Postojeća kategorija ostaje u svojoj grupi.']);
            }
            $parent = isset($validated['parent_id']) ? Collection::query()->where('collection_group_id', $group->id)
                ->where('type', 'static')->find($validated['parent_id']) : null;
            if (isset($validated['parent_id']) && (! $parent || $parent->id === $record->id || ($record->exists && $parent->isDescendantOf($record)))) {
                throw ValidationException::withMessages(['parent_id' => 'Odaberite kategoriju iz iste grupe koja nije ova kategorija ili njezin potomak.']);
            }
            $record->fill([
                'collection_group_id' => $group->id,
                'attribute_data' => CatalogTranslations::merge($record, $validated['translations']),
            ]);
            if (! $record->exists) {
                $record->type = 'static';
                $record->sort = 'custom';
            }
            if (! $record->exists || $record->parent_id !== $parent?->id) {
                $parent ? $record->appendToNode($parent) : $record->makeRoot();
            }
            $record->save();

            return $record;
        });
    }
}
