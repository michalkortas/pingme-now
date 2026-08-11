<?php

namespace MichalKortas\PingMeNow\Monitoring;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MichalKortas\PingMeNow\Jobs\QueueCanary;
use Throwable;

class StatusCollector
{
    public function collect(): array
    {
        $queueLastSeen = Cache::get('pingme-now:queue-canary-at');
        $disks = $this->disks();
        $checks = $this->checks();

        $payload = [
            'event_uuid' => (string) Str::uuid(),
            'recorded_at' => now()->toIso8601String(),
            'agent' => ['name' => 'pingme-now', 'version' => $this->agentVersion()],
            'platform' => [
                'laravel' => app()->version(),
                'php' => PHP_VERSION,
                'environment' => app()->environment(),
                'hostname' => gethostname() ?: null,
            ],
            'metrics' => [
                'scheduler_age_minutes' => 0,
                'queue_age_minutes' => $queueLastSeen ? now()->diffInSeconds($queueLastSeen) / 60 : 999,
                'failed_jobs_24h' => $this->failedJobs(),
                'disk_used_percent' => $disks === [] ? null : max(array_column($disks, 'used_percent')),
            ],
            'checks' => $checks,
            'disks' => $disks,
        ];

        if ((bool) config('pingme-now.queue.enabled')) {
            $job = new QueueCanary;

            if (config('pingme-now.queue.connection')) {
                $job->onConnection(config('pingme-now.queue.connection'));
            }

            $job->onQueue((string) config('pingme-now.queue.name', 'default'));
            dispatch($job);
        }

        return $payload;
    }

    private function checks(): array
    {
        $checks = ['database' => ['ok' => false], 'cache' => ['ok' => false]];

        try {
            DB::connection()->getPdo();
            $checks['database'] = ['ok' => true];
        } catch (Throwable $exception) {
            $checks['database']['message'] = $exception->getMessage();
        }

        try {
            Cache::put('pingme-now:cache-check', now()->timestamp, 60);
            $checks['cache'] = ['ok' => Cache::has('pingme-now:cache-check')];
        } catch (Throwable $exception) {
            $checks['cache']['message'] = $exception->getMessage();
        }

        return $checks;
    }

    private function failedJobs(): int
    {
        try {
            if (! Schema::hasTable('failed_jobs')) {
                return 0;
            }

            return DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        } catch (Throwable) {
            return 0;
        }
    }

    private function disks(): array
    {
        $paths = config('pingme-now.disk_paths') ?: [storage_path()];
        $disks = [];

        foreach ($paths as $path) {
            $realPath = realpath($path);

            if ($realPath === false) {
                continue;
            }

            $total = @disk_total_space($realPath);
            $free = @disk_free_space($realPath);

            if ($total === false || $free === false || $total <= 0) {
                continue;
            }

            $disks[] = [
                'path' => $path,
                'total_bytes' => (int) $total,
                'free_bytes' => (int) $free,
                'used_bytes' => (int) ($total - $free),
                'used_percent' => round((($total - $free) / $total) * 100, 2),
            ];
        }

        return $disks;
    }

    private function agentVersion(): string
    {
        if (class_exists(InstalledVersions::class)) {
            return InstalledVersions::getPrettyVersion('michalkortas/pingme-now') ?? 'dev';
        }

        return 'dev';
    }
}
