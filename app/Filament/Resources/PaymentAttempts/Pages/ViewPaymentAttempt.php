<?php

namespace App\Filament\Resources\PaymentAttempts\Pages;

use App\Filament\Resources\PaymentAttempts\PaymentAttemptResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPaymentAttempt extends ViewRecord
{
    protected static string $resource = PaymentAttemptResource::class;

    public function getTitle(): string
    {
        return 'Pregled pokušaja naplate';
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [];
    }
}
