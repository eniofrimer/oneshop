<?php

namespace Tests\Feature;

use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\User;
use App\Orders\OrderSummary;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Lunar\Models\Order;
use Lunar\Models\OrderAddress;
use Lunar\Models\OrderLine;
use Tests\TestCase;

class OrderSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.faker_locale' => 'en_US']);
        $this->artisan('oneshop:initialize')->assertSuccessful();
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true, 'is_super_admin' => true])->save();
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->order = Order::factory()->create(['reference' => 'PDF-HISTORY', 'currency_code' => 'EUR', 'placed_at' => now(),
            'status' => 'payment-received', 'sub_total' => 2400, 'shipping_total' => 500, 'tax_total' => 700, 'total' => 3500,
            'notes' => 'DO_NOT_RENDER_PRIVATE_NOTES', 'meta' => ['oneshop_mode' => 'demo', 'secret' => 'DO_NOT_RENDER_PRIVATE_METADATA']]);
        OrderAddress::factory()->create(['order_id' => $this->order->id, 'type' => 'billing', 'first_name' => 'Ana', 'last_name' => 'Povijesna',
            'line_one' => 'Izvorna adresa 1', 'contact_email' => 'history@example.test']);
        OrderLine::factory()->create(['order_id' => $this->order->id, 'description' => 'Povijesna majica', 'identifier' => 'OLD-SKU',
            'option' => 'Maslinasta / M', 'quantity' => 2, 'unit_price' => 1200, 'sub_total' => 2400, 'tax_total' => 600, 'total' => 3000]);
    }

    public function test_summary_renders_historical_snapshots_and_explicit_test_labels_without_private_metadata(): void
    {
        $before = $this->order->fresh()->getRawOriginal();
        $html = app(OrderSummary::class)->html($this->order);
        $this->assertStringContainsString('TESTNA NARUDŽBA', $html);
        $this->assertStringContainsString('Testna uplata potvrđena', $html);
        $this->assertStringContainsString('Izvorna adresa 1', $html);
        $this->assertStringContainsString('Povijesna majica', $html);
        $this->assertStringContainsString('35,00 EUR', $html);
        $this->assertStringContainsString('30,00 EUR', $html);
        $this->assertStringContainsString('Nije račun', $html);
        $this->assertStringNotContainsString('DO_NOT_RENDER_PRIVATE_METADATA', $html);
        $this->assertStringNotContainsString('DO_NOT_RENDER_PRIVATE_NOTES', $html);
        $this->assertSame($before, $this->order->fresh()->getRawOriginal());
    }

    public function test_pdf_bytes_and_filament_attachment_are_generated_with_read_only_order_data(): void
    {
        $bytes = app(OrderSummary::class)->pdf($this->order);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringContainsString('%%EOF', $bytes);
        $this->assertGreaterThan(1000, strlen($bytes));
        $response = app(OrderSummary::class)->download($this->order);
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        Livewire::test(ViewOrder::class, ['record' => $this->order->id])->callAction('downloadSummary')
            ->assertFileDownloaded('order-summary-'.$this->order->id.'.pdf');
        $this->assertSame(3500, (int) $this->order->fresh()->getRawOriginal('total'));
    }

    public function test_untrusted_text_is_escaped_before_pdf_conversion(): void
    {
        $line = $this->order->lines()->sole();
        $line->update(['description' => '<img src="http://example.test/private">', 'option' => '<script>alert(1)</script>']);
        $html = app(OrderSummary::class)->html($this->order);
        $this->assertStringNotContainsString('<img src=', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_summary_keeps_the_zero_variant_label_and_product_sku_without_internal_shipping_identifiers(): void
    {
        $this->order->lines()->sole()->update(['option' => '0']);
        $this->order->billingAddress->update(['line_two' => '0']);
        OrderLine::factory()->create(['order_id' => $this->order->id, 'type' => 'shipping',
            'description' => 'Spremljena testna dostava', 'identifier' => 'oneshop-shipping:internal-identifier',
            'option' => null, 'quantity' => 1, 'unit_price' => 400, 'sub_total' => 400, 'tax_total' => 100, 'total' => 500]);
        $before = $this->order->lines()->orderBy('id')->get()->map->getRawOriginal()->all();

        $html = app(OrderSummary::class)->html($this->order);
        $document = new \DOMDocument;
        $document->loadHTML($html);
        $descriptions = (new \DOMXPath($document))->query('//table[@class="lines"]/tbody/tr/td[1]');
        $this->assertNotFalse($descriptions);
        $this->assertSame(2, $descriptions->length);
        $this->assertStringContainsString('0', $descriptions->item(0)->textContent);
        $this->assertStringContainsString('OLD-SKU', $descriptions->item(0)->textContent);
        $this->assertStringContainsString('Spremljena testna dostava', $descriptions->item(1)->textContent);
        $this->assertStringNotContainsString('oneshop-shipping:internal-identifier', $html);
        $addressParts = (new \DOMXPath($document))->query('//table[@class="addresses"]/tr/td[1]/p/text()');
        $this->assertNotFalse($addressParts);
        $this->assertContains('0', array_map(fn (\DOMNode $part): string => trim($part->textContent), iterator_to_array($addressParts)));
        $this->assertStringContainsString('35,00 EUR', $html);
        $this->assertSame($before, $this->order->lines()->orderBy('id')->get()->map->getRawOriginal()->all());
    }

    public function test_customers_and_revoked_admins_cannot_download_and_drafts_are_not_exported(): void
    {
        $component = Livewire::test(ViewOrder::class, ['record' => $this->order->id]);
        $this->admin->forceFill(['is_admin' => false, 'is_super_admin' => false])->save();
        $component->call('mountAction', 'downloadSummary')->assertForbidden();
        try {
            app(OrderSummary::class)->pdf($this->order);
            $this->fail('An unauthorized PDF was generated.');
        } catch (AuthorizationException) {
            $this->assertSame(3500, (int) $this->order->fresh()->getRawOriginal('total'));
        }
        $this->admin->forceFill(['is_admin' => true, 'is_super_admin' => true])->save();
        $draft = Order::factory()->create(['placed_at' => null, 'currency_code' => 'EUR']);
        $this->expectException(ModelNotFoundException::class);
        app(OrderSummary::class)->pdf($draft);
    }
}
