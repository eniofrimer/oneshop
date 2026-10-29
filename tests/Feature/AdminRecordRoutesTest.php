<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRecordRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_malformed_or_overflowing_record_ids_are_404_across_all_numeric_resources(): void
    {
        $this->artisan('oneshop:initialize')->assertSuccessful();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true, 'is_super_admin' => true])->save();
        $this->actingAs($admin);
        foreach (['not-a-number', '0', '-1', '99999999999999999999999999999999'] as $id) {
            foreach (['products', 'options', 'categories', 'shipping-methods'] as $resource) {
                $this->get("/admin/$resource/$id/edit")->assertNotFound();
            }
            foreach (['orders', 'payments'] as $resource) {
                $this->get("/admin/$resource/$id")->assertNotFound();
            }
        }
    }
}
