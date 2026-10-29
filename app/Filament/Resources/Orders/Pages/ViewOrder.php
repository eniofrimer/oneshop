<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Orders\OrderSummary;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Lunar\Models\Order;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('downloadSummary')->label('Preuzmi sažetak (PDF)')
            ->action(function () {
                $order = $this->getRecord();
                abort_unless($order instanceof Order, 404);

                return app(OrderSummary::class)->download($order);
            })];
    }
}
