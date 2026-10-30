<?php

use App\Jobs\RuntimeQueueProbe;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// This fixture executes real scheduled work. It is excluded from native releases.
if (PHP_SAPI !== 'cli' || getenv('ONESHOP_HOSTING_QUEUE_TEST') !== '1') {
    throw new RuntimeException('Explicitly enable this probe only in an isolated test installation.');
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
if ($app->configurationIsCached()) {
    throw new RuntimeException('The isolated scheduler probe requires uncached configuration.');
}
$app->make(Kernel::class)->bootstrap();
$database = DB::connection()->getDatabaseName();
if (! $app->environment('local', 'testing') || ! preg_match('/^(?:oneshop_test|oneshop_ui_\d{8}_\d{6})$/D', $database)) {
    throw new RuntimeException('Refusing work outside an isolated test database.');
}
if (config('queue.default') !== 'database' || config('cache.default') !== 'database' || ! config('oneshop.queue_via_scheduler')) {
    throw new RuntimeException('Enable the native database queue/cache and scheduled worker profile.');
}
$events = array_values(array_filter(app(Schedule::class)->events(), fn ($event) => str_contains((string) $event->command, 'queue:work')));
if (count($events) !== 1 || ! $events[0]->withoutOverlapping || $events[0]->expiresAt !== 5) {
    throw new RuntimeException('Expected one bounded scheduled worker with its overlap mutex.');
}
$event = $events[0];
if (Artisan::call('schedule:run', ['--no-interaction' => true]) !== 0 || $event->exitCode !== 0 || Queue::size() !== 0) {
    throw new RuntimeException('Initial scheduled queue drain failed.');
}
$failedBefore = DB::table('failed_jobs')->count();
$tokens = [Str::random(40), Str::random(40)];
$keys = array_map(RuntimeQueueProbe::key(...), $tokens);
foreach ($tokens as $token) {
    Queue::push(new RuntimeQueueProbe($token));
}
if (Queue::size() !== 2 || array_any($keys, fn ($key) => Cache::has($key))) {
    throw new RuntimeException('The probes must be queued without synchronous execution or another worker.');
}
$ownsMutex = false;
try {
    $ownsMutex = $event->mutex->create($event);
    if (! $ownsMutex) {
        throw new RuntimeException('The isolated worker mutex was unexpectedly held.');
    }
    Artisan::call('schedule:run', ['--no-interaction' => true]);
    if (Queue::size() !== 2 || array_any($keys, fn ($key) => Cache::has($key))) {
        throw new RuntimeException('A scheduled worker ran while the overlap mutex was held.');
    }
} finally {
    if ($ownsMutex) {
        $event->mutex->forget($event);
    }
}
if (Artisan::call('schedule:run', ['--no-interaction' => true]) !== 0 || $event->exitCode !== 0 || Queue::size() !== 0 || ! array_all($keys, fn ($key) => Cache::get($key) === true)) {
    throw new RuntimeException('A separate scheduled worker did not execute both queued probes.');
}
if ($event->mutex->exists($event) || DB::table('failed_jobs')->count() !== $failedBefore) {
    throw new RuntimeException('Scheduled work left its mutex or introduced a failed job.');
}
foreach ($keys as $key) {
    Cache::forget($key);
}
echo json_encode([
    'result' => 'passed', 'databaseDriver' => DB::connection()->getDriverName(),
    'queuedJobs' => 2, 'blockedWhileMutexHeld' => true, 'executedByScheduledWorker' => true,
    'releasedMutex' => true, 'newFailedJobs' => 0,
], JSON_THROW_ON_ERROR).PHP_EOL;
