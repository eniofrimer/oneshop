<?php

namespace App\Console\Commands;

use App\Jobs\RuntimeQueueProbe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class ProbeQueue extends Command
{
    protected $signature = 'oneshop:probe-queue {--wait=30 : Seconds to wait for an actual background worker, 1-120} {--queue=default : Queue consumed by the configured worker}';

    protected $description = 'Explicitly dispatch a harmless job and verify that a separate worker can write to the shared cache';

    public function handle(): int
    {
        $wait = filter_var($this->option('wait'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 120]]);
        $queue = $this->option('queue');
        $connection = config('queue.default');
        if ($wait === false || ! is_string($queue) || ! preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', $queue)) {
            $this->error('Use --wait=1..120 and a single queue name.');

            return self::FAILURE;
        }
        if (! is_string($connection) || ! in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis'], true)) {
            $this->error('This probe requires an asynchronous database or Redis connection. Sync execution does not verify a background worker.');

            return self::FAILURE;
        }
        $token = Str::random(40);
        $key = RuntimeQueueProbe::key($token);
        Queue::connection($connection)->push(new RuntimeQueueProbe($token), queue: $queue);
        $deadline = microtime(true) + $wait;
        do {
            if (Cache::get($key) === true) {
                Cache::forget($key);
                $this->info('Queue probe passed: a background worker executed the job using the shared cache.');

                return self::SUCCESS;
            }
            usleep(200000);
        } while (microtime(true) < $deadline);
        Cache::forget($key);
        $this->error('Queue probe timed out. Check the selected queue, worker/cron and shared cache. The probe job contains no merchant data and its cache marker expires after one minute.');

        return self::FAILURE;
    }
}
