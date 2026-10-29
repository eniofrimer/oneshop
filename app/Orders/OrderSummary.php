<?php

namespace App\Orders;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderSummary
{
    public function html(Order $order): string
    {
        Gate::authorize('view', $order);
        $record = Order::query()->whereNotNull('placed_at')->with(['currency', 'billingAddress.country', 'shippingAddress.country'])->findOrFail($order->id);
        $lines = OrderLine::query()->where('order_id', $record->id)->orderBy('id')->get();
        foreach ($lines as $line) {
            $line->setRelation('order', $record);
        }

        return view('documents.order-summary', ['order' => $record, 'lines' => $lines])->render();
    }

    public function pdf(Order $order): string
    {
        $html = $this->html($order);
        $work = storage_path('framework/cache/dompdf');
        File::ensureDirectoryExists($work, 0750);

        return Pdf::loadHTML($html)->setPaper('a4')->setWarnings(false)->setOption([
            'fontDir' => $work, 'fontCache' => $work, 'tempDir' => $work,
            'defaultFont' => 'DejaVu Sans',
            'chroot' => [base_path('vendor/dompdf/dompdf/lib/fonts'), $work],
            'logOutputFile' => null, 'isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false,
        ])->output();
    }

    public function download(Order $order): StreamedResponse
    {
        $bytes = $this->pdf($order);

        return response()->streamDownload(function () use ($bytes): void {
            echo $bytes;
        }, 'order-summary-'.$order->id.'.pdf', ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
