<?php

namespace Tests\Feature;

use App\Catalog\CategoryEditor;
use App\Catalog\ProductEditor;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Lunar\FieldTypes\Text;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Collection;
use Lunar\Models\CollectionGroup;
use Lunar\Models\Product;
use Lunar\Models\ProductType;
use Tests\TestCase;

class CategoryAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private CollectionGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.faker_locale' => 'en_US', 'oneshop.enabled_locales' => ['hr', 'en']]);
        $this->artisan('oneshop:initialize')->assertSuccessful();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->group = app(CategoryEditor::class)->createGroup(['name' => 'Glavni katalog', 'handle' => 'catalog']);
    }

    private function data(?Collection $parent = null): array
    {
        return [
            'collection_group_id' => $this->group->id,
            'parent_id' => $parent?->id,
            'translations' => ['hr' => ['name' => 'Odjeća', 'description' => 'Odjeća i dodaci'], 'en' => ['name' => 'Clothing', 'description' => 'Clothing and accessories']],
        ];
    }

    private function category(?Collection $parent = null): Collection
    {
        return app(CategoryEditor::class)->save($this->data($parent));
    }

    public function test_admin_creates_a_group_and_translated_category_in_filament(): void
    {
        $this->get('/admin/categories')->assertOk();
        $this->get('/admin/categories/create')->assertOk();
        Livewire::test(CreateCategory::class)
            ->callFormComponentAction('collection_group_id', 'createOption', data: ['name' => 'Drugi katalog', 'handle' => 'second-catalog'])
            ->assertHasNoFormComponentActionErrors();
        $this->assertSame(2, CollectionGroup::query()->count());
        Livewire::test(CreateCategory::class)->fillForm($this->data())->call('create')->assertHasNoFormErrors();
        $category = Collection::query()->sole();
        $this->assertSame('static', $category->type);
        $this->assertSame('custom', $category->sort);
        $this->assertSame('Clothing', $category->translateAttribute('name', 'en'));
        $this->get('/admin/categories/'.$category->id.'/edit')->assertOk();
    }

    public function test_category_edits_and_moves_keep_the_tree_consistent(): void
    {
        $root = $this->category();
        $child = $this->category($root);
        $grandchild = $this->category($child);
        Livewire::test(EditCategory::class, ['record' => $child->id])
            ->assertFormSet(['parent_id' => $root->id, 'translations.hr.name' => 'Odjeća'])
            ->fillForm(['parent_id' => null, 'translations.hr.name' => 'Samostalna kategorija'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertNull($child->fresh()->parent_id);
        $this->assertTrue($grandchild->fresh()->isDescendantOf($child->fresh()));
        $this->assertFalse($grandchild->fresh()->isDescendantOf($root->fresh()));
        $this->assertFalse(Collection::scoped(['collection_group_id' => $this->group->id])->isBroken());
    }

    public function test_self_and_descendant_parents_are_rejected_without_tree_changes(): void
    {
        $root = $this->category();
        $child = $this->category($root);
        foreach ([$root, $child] as $parent) {
            try {
                app(CategoryEditor::class)->save($this->data($parent), $root);
                $this->fail('A cyclic category tree was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('parent_id', $exception->errors());
            }
        }
        $this->assertNull($root->fresh()->parent_id);
        $this->assertSame($root->id, $child->fresh()->parent_id);
        $this->assertFalse(Collection::scoped(['collection_group_id' => $this->group->id])->isBroken());
    }

    public function test_parents_from_another_group_and_missing_parents_are_rejected(): void
    {
        $other = app(CategoryEditor::class)->createGroup(['name' => 'Drugo', 'handle' => 'other']);
        $foreign = app(CategoryEditor::class)->save([...$this->data(), 'collection_group_id' => $other->id]);
        foreach ([$foreign->id, 999999] as $id) {
            try {
                app(CategoryEditor::class)->save([...$this->data(), 'parent_id' => $id]);
                $this->fail('An invalid parent was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('parent_id', $exception->errors());
            }
        }
        $this->assertSame(1, Collection::query()->count());
    }

    public function test_an_existing_category_cannot_change_its_group(): void
    {
        $category = $this->category();
        $other = app(CategoryEditor::class)->createGroup(['name' => 'Drugo', 'handle' => 'other']);
        try {
            app(CategoryEditor::class)->save([...$this->data(), 'collection_group_id' => $other->id], $category);
            $this->fail('A category changed its tree scope.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('collection_group_id', $exception->errors());
        }
        $this->assertSame($this->group->id, $category->fresh()->collection_group_id);
    }

    public function test_category_translation_edits_preserve_unmanaged_data_and_existing_urls(): void
    {
        $category = $this->category();
        $url = $category->urls()->sole()->slug;
        $category->update(['attribute_data' => collect([
            'name' => new TranslatedText(['hr' => 'Odjeća', 'de' => 'Kleidung']),
            'external' => new Text('preserve'),
        ])]);
        app(CategoryEditor::class)->save($this->data(), $category);
        $fresh = $category->fresh();
        $this->assertSame('Kleidung', $fresh->translateAttribute('name', 'de'));
        $this->assertSame('preserve', $fresh->attribute_data->get('external')->getValue());
        $this->assertSame($url, $fresh->urls()->sole()->slug);
    }

    public function test_product_membership_updates_preserve_automatic_collections_and_manual_positions(): void
    {
        $first = $this->category();
        $second = $this->category();
        $automatic = $this->category();
        $automatic->update(['type' => 'automatic']);
        $product = app(ProductEditor::class)->save([
            'product_type_id' => ProductType::factory()->create()->id,
            'translations' => ['hr' => ['name' => 'Majica'], 'en' => ['name' => 'Shirt']],
            'collection_ids' => [$first->id],
        ]);
        $product->collections()->updateExistingPivot($first->id, ['position' => 7]);
        $product->collections()->attach($automatic->id, ['position' => 3]);
        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->assertFormSet(['collection_ids' => [$first->id]])
            ->fillForm(['collection_ids' => [$first->id, $second->id]])->call('save')->assertHasNoFormErrors();
        $this->assertEqualsCanonicalizing([$first->id, $second->id, $automatic->id], $product->collections()->get()->pluck('id')->all());
        $this->assertSame(7, $product->collections()->whereKey($first->id)->first()->pivot->position);
        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->fillForm(['collection_ids' => []])->call('save')->assertHasNoFormErrors();
        $this->assertSame([$automatic->id], $product->collections()->get()->pluck('id')->all());
        $this->assertSame(3, $product->collections()->sole()->pivot->position);
    }

    public function test_automatic_and_deleted_categories_cannot_be_assigned_as_manual_categories(): void
    {
        $automatic = $this->category();
        $automatic->update(['type' => 'automatic']);
        $deleted = $this->category();
        $deleted->delete();
        foreach ([$automatic->id, $deleted->id] as $id) {
            try {
                app(ProductEditor::class)->save([
                    'product_type_id' => ProductType::factory()->create()->id,
                    'translations' => ['hr' => ['name' => 'Majica'], 'en' => ['name' => 'Shirt']],
                    'collection_ids' => [$id],
                ]);
                $this->fail('An out-of-scope collection was assigned.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('collection_ids.0', $exception->errors());
            }
        }
        $this->assertSame(0, Product::query()->count());
    }

    public function test_required_primary_name_and_normalized_group_handle_are_validated(): void
    {
        Livewire::test(CreateCategory::class)->fillForm([...$this->data(), 'translations' => ['hr' => ['name' => ''], 'en' => ['name' => 'Clothing']]])
            ->call('create')->assertHasFormErrors(['translations.hr.name' => 'required']);
        try {
            app(CategoryEditor::class)->createGroup(['name' => 'Duplikat', 'handle' => ' CATALOG ']);
            $this->fail('A duplicate normalized group handle was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('handle', $exception->errors());
        }
        $this->assertSame(1, CollectionGroup::query()->count());
    }

    public function test_customer_cannot_manage_categories_or_create_groups(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/categories')->assertForbidden();
        try {
            app(CategoryEditor::class)->save($this->data());
            $this->fail('A customer created a category.');
        } catch (AuthorizationException) {
            $this->assertSame(0, Collection::query()->count());
        }
        $this->expectException(AuthorizationException::class);
        app(CategoryEditor::class)->createGroup(['name' => 'Napad', 'handle' => 'attack']);
    }
}
