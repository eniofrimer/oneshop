<?php

namespace Tests\Feature;

use App\Digital\DigitalAssetEditor;
use App\Filament\Resources\DigitalAssets\Pages\CreateDigitalAsset;
use App\Filament\Resources\DigitalAssets\Pages\ListDigitalAssets;
use App\Models\DigitalAsset;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Lunar\Models\ProductVariant;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DigitalAssetsTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.faker_locale' => 'en_US']);
        $this->artisan('oneshop:initialize')->assertSuccessful();
        Storage::fake('private');
        Storage::fake('public');
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->variant = ProductVariant::factory()->create(['shippable' => false]);
    }

    private function upload(string $content = 'private content'): DigitalAsset
    {
        return app(DigitalAssetEditor::class)->upload($this->variant, UploadedFile::fake()->createWithContent('book.pdf', $content), 'Knjiga');
    }

    public function test_admin_uploads_a_private_asset_through_filament(): void
    {
        $this->get('/admin/digital-assets/create')->assertOk();
        Livewire::test(CreateDigitalAsset::class)->fillForm([
            'product_variant_id' => $this->variant->id, 'label' => 'Knjiga',
            'file' => UploadedFile::fake()->createWithContent('book.pdf', 'private book'),
        ])->call('create')->assertHasNoFormErrors();
        $asset = DigitalAsset::query()->sole();
        $this->assertSame($this->variant->id, $asset->product_variant_id);
        $this->assertSame(hash('sha256', 'private book'), $asset->sha256);
        $this->assertSame(12, $asset->size);
        Storage::disk('private')->assertExists($asset->path);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertArrayNotHasKey('path', $asset->toArray());
        $this->assertArrayNotHasKey('sha256', $asset->toArray());
        $this->get('/storage/'.$asset->path)->assertNotFound();
    }

    public function test_admin_download_is_an_attachment_and_executable_content_is_never_served_inline(): void
    {
        $asset = app(DigitalAssetEditor::class)->upload($this->variant, UploadedFile::fake()->createWithContent('example.php', '<?php echo "private";'), 'Primjer');
        $response = app(DigitalAssetEditor::class)->download($asset);
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        Livewire::test(ListDigitalAssets::class)->callTableAction('download', $asset)->assertFileDownloaded('example.php');
        $this->get('/storage/'.$asset->path)->assertNotFound();
    }

    public function test_new_uploads_do_not_overwrite_existing_assets_and_archiving_retains_bytes(): void
    {
        $first = $this->upload('first edition');
        $second = $this->upload('second edition');
        $this->assertNotSame($first->path, $second->path);
        $this->assertNotSame($first->sha256, $second->sha256);
        Livewire::test(ListDigitalAssets::class)->callTableAction('archive', $first)->assertHasNoTableActionErrors();
        $this->assertNotNull($first->fresh()->archived_at);
        $this->assertSame('first edition', Storage::disk('private')->get($first->path));
        Livewire::test(ListDigitalAssets::class)->callTableAction('restore', $first)->assertHasNoTableActionErrors()
            ->assertTableActionDoesNotExist(DeleteAction::class);
        $this->assertNull($first->fresh()->archived_at);
        $this->assertSame(2, DigitalAsset::query()->count());
    }

    public function test_upload_and_archive_are_audited_without_recording_private_storage_paths(): void
    {
        $asset = $this->upload();
        app(DigitalAssetEditor::class)->archive($asset, true);
        $activities = Activity::query()->where('log_name', 'digital-assets')->where('subject_id', $asset->id)->orderBy('id')->get();
        $this->assertSame(['created', 'updated'], $activities->pluck('event')->all());
        $this->assertSame([auth()->id()], $activities->pluck('causer_id')->unique()->all());
        $this->assertStringNotContainsString($asset->path, $activities->toJson());
        $this->assertStringNotContainsString($asset->sha256, $activities->toJson());
    }

    public function test_a_physical_sku_or_a_changed_shipping_flag_is_rejected_without_files(): void
    {
        $this->variant->update(['shippable' => true]);
        try {
            $this->upload();
            $this->fail('A physical SKU accepted a digital asset.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('product_variant_id', $exception->errors());
        }
        $this->assertSame(0, DigitalAsset::query()->count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_oversized_uploads_do_not_create_records_or_files(): void
    {
        try {
            app(DigitalAssetEditor::class)->upload($this->variant, UploadedFile::fake()->create('huge.zip', DigitalAssetEditor::MAX_KILOBYTES + 1), 'Preveliko');
            $this->fail('An oversized file was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }
        $this->assertSame(0, DigitalAsset::query()->count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_failed_database_insert_compensates_the_private_file_write(): void
    {
        $event = 'eloquent.creating: '.DigitalAsset::class;
        Event::listen($event, fn () => throw new \RuntimeException('simulated insert failure'));
        try {
            $this->upload();
            $this->fail('The insert failure was not raised.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('simulated insert failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame(0, DigitalAsset::query()->count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_stored_asset_identity_and_payload_metadata_cannot_be_reassigned(): void
    {
        $asset = $this->upload();
        try {
            $asset->forceFill(['path' => '../.env'])->save();
            $this->fail('An existing asset path was changed.');
        } catch (\LogicException) {
            $this->assertStringStartsWith('digital-assets/', $asset->fresh()->path);
        }
        $this->assertSame(hash('sha256', 'private content'), $asset->fresh()->sha256);
    }

    public function test_a_changed_payload_is_rejected_by_its_checksum(): void
    {
        $asset = $this->upload();
        Storage::disk('private')->put($asset->path, 'modified content');
        try {
            app(DigitalAssetEditor::class)->download($asset);
            $this->fail('A corrupted payload was downloaded.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    public function test_a_missing_asset_file_returns_not_found(): void
    {
        $asset = $this->upload();
        Storage::disk('private')->delete($asset->path);
        try {
            app(DigitalAssetEditor::class)->download($asset);
            $this->fail('A missing file was treated as downloadable.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_archived_variants_keep_their_stored_assets_available_to_authorized_admins(): void
    {
        $asset = $this->upload();
        $this->variant->delete();
        $this->assertNotNull($asset->fresh()->variant);
        $this->assertSame(200, app(DigitalAssetEditor::class)->download($asset)->getStatusCode());
    }

    public function test_customers_cannot_read_upload_archive_or_download_assets(): void
    {
        $asset = $this->upload();
        $this->actingAs(User::factory()->create())->get('/admin/digital-assets')->assertForbidden();
        $this->get('/admin/digital-assets/create')->assertForbidden();
        foreach ([fn () => $this->upload(), fn () => app(DigitalAssetEditor::class)->archive($asset, true), fn () => app(DigitalAssetEditor::class)->download($asset)] as $operation) {
            try {
                $operation();
                $this->fail('A customer performed a private asset operation.');
            } catch (AuthorizationException) {
                $this->assertSame(1, DigitalAsset::query()->count());
            }
        }
        $this->assertNull($asset->fresh()->archived_at);
    }
}
