<?php

namespace Tests\Feature;

use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Lunar\Models\Order;
use Lunar\Models\Product;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.faker_locale' => 'en_US']);
        $this->artisan('oneshop:demo-catalog')->assertSuccessful();
        $this->owner = User::factory()->create(['is_admin' => true, 'is_super_admin' => true, 'name' => 'Audit Reviewer']);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function event(string $type, int $id, string $event): Activity
    {
        return Activity::query()->create(['subject_type' => $type, 'subject_id' => $id, 'event' => $event,
            'causer_type' => $this->owner->getMorphClass(), 'causer_id' => $this->owner->id,
            'description' => 'NEVER_RENDER_AUDIT_DESCRIPTION',
            'properties' => ['path' => 'private/NEVER_RENDER_AUDIT_PATH', 'token' => 'NEVER_RENDER_AUDIT_TOKEN',
                'reservation_key' => 'NEVER_RENDER_RESERVATION_CAPABILITY']]);
    }

    public function test_supported_events_are_read_only_and_never_render_arbitrary_payloads(): void
    {
        $order = Order::factory()->create(['currency_code' => 'EUR', 'placed_at' => now(), 'reference' => 'AUDIT-ORDER']);
        $record = $this->event($order->getMorphClass(), $order->id, 'inventory-reserved');
        $before = $record->fresh()->getRawOriginal();
        $this->get('/admin/activities')->assertOk()->assertSee('Zaliha rezervirana')->assertSee('Audit Reviewer')
            ->assertSee('AUDIT-ORDER')->assertSee(OrderResource::getUrl('view', ['record' => $order]), false)
            ->assertDontSee('NEVER_RENDER_AUDIT_DESCRIPTION')->assertDontSee('NEVER_RENDER_AUDIT_PATH')
            ->assertDontSee('NEVER_RENDER_AUDIT_TOKEN')->assertDontSee('NEVER_RENDER_RESERVATION_CAPABILITY');
        Livewire::test(ListActivities::class)->assertCanSeeTableRecords([$record])
            ->assertTableActionDoesNotExist(CreateAction::class)->assertTableActionDoesNotExist(EditAction::class)
            ->assertTableActionDoesNotExist(DeleteAction::class);
        $this->assertSame($before, $record->fresh()->getRawOriginal());
        $projection = ActivityResource::getEloquentQuery()->findOrFail($record->id)->getAttributes();
        $this->assertArrayNotHasKey('properties', $projection);
        $this->assertArrayNotHasKey('description', $projection);
    }

    public function test_unknown_events_and_mismatched_subject_types_do_not_enter_the_public_admin_projection(): void
    {
        $product = Product::query()->firstOrFail();
        $shown = $this->event($product->getMorphClass(), $product->id, 'catalog-published');
        $unknown = $this->event($product->getMorphClass(), $product->id, 'unknown-private-event');
        $wrongSubject = $this->event($this->owner->getMorphClass(), $this->owner->id, 'inventory-reserved');
        Livewire::test(ListActivities::class)->assertCanSeeTableRecords([$shown])
            ->assertCanNotSeeTableRecords([$unknown, $wrongSubject])->filterTable('event', 'inventory-reserved')
            ->assertCanNotSeeTableRecords([$shown, $unknown, $wrongSubject]);
    }

    public function test_customer_unenrolled_and_revoked_administrators_cannot_read_activities(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/admin/activities')->assertForbidden();
        Livewire::test(ListActivities::class)->assertForbidden();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $this->get('/admin/activities')->assertRedirect();
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $page = Livewire::test(ListActivities::class)->assertOk();
        $admin->forceFill(['is_admin' => false])->save();
        $page->call('$refresh')->assertForbidden();
    }

    public function test_no_record_or_write_routes_are_exposed_even_to_the_owner(): void
    {
        $this->assertFalse(ActivityResource::canCreate());
        $this->assertFalse(ActivityResource::canDeleteAny());
        $this->get('/admin/activities/create')->assertNotFound();
        $this->get('/admin/activities/1')->assertNotFound();
        $this->get('/admin/activities/1/edit')->assertNotFound();
    }
}
