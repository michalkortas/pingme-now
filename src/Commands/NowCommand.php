<?php

namespace MichalKortas\PingMeNow\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use MichalKortas\PingMeNow\Logging\LogSpool;
use MichalKortas\PingMeNow\Monitoring\DependencyCollector;
use MichalKortas\PingMeNow\Monitoring\StatusCollector;
use MichalKortas\PingMeNow\Transport\HubClient;
use Throwable;

class NowCommand extends Command
{
    protected $signature = 'pingme:now';

    protected $description = 'Send the current application status to PingMe';

    public function handle(StatusCollector $collector, DependencyCollector $dependencies, LogSpool $spool, HubClient $client): int
    {
        if (! config('pingme-now.enabled')) {
            $this->warn('PingMe jest wyłączony.');

            return self::SUCCESS;
        }

        $logs = [];

        try {
            $payload = $collector->collect();
            $client->snapshot($payload);

            $logs = $spool->take();

            if ($logs !== []) {
                $client->logs($logs);
            }

            $inventory = $dependencies->collect();
            $cacheKey = 'pingme-now:dependencies:'.sha1((string) config('pingme-now.url').'|'.(string) config('pingme-now.token'));

            if (Cache::get($cacheKey) !== $inventory['hash']) {
                $client->dependencies($inventory['hash'], $inventory['packages']);
                Cache::forever($cacheKey, $inventory['hash']);
            }

            $this->info('Status wysłany do PingMe.');
            $this->table(['Check', 'Wartość'], [
                ['Laravel', data_get($payload, 'platform.laravel')],
                ['PHP', data_get($payload, 'platform.php')],
                ['Queue age', data_get($payload, 'metrics.queue_age_minutes').' min'],
                ['Disk used', data_get($payload, 'metrics.disk_used_percent', '—').' %'],
                ['Failed jobs', data_get($payload, 'metrics.failed_jobs_24h')],
                ['Logs sent', count($logs)],
            ]);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($logs !== []) {
                $spool->restore($logs);
            }

            $this->error('Nie udało się wysłać statusu: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
