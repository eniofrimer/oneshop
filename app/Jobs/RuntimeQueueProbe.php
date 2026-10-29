<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class RuntimeQueueProbe implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(public string $token) {}

    public static function key(string $token): string
    {
        return 'oneshop:runtime-queue-probe:'.$token;
    }

    public function handle(): void
    {
        Cache::put(self::key($this->token), true, now()->addMinute());
    }
}
