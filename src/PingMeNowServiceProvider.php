<?php

namespace MichalKortas\PingMeNow;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use MichalKortas\PingMeNow\Commands\EnrollCommand;
use MichalKortas\PingMeNow\Commands\InstallCommand;
use MichalKortas\PingMeNow\Commands\NowCommand;
use MichalKortas\PingMeNow\Logging\LogSpool;

class PingMeNowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/pingme-now.php', 'pingme-now');
        $this->app->singleton(LogSpool::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/pingme-now.php' => config_path('pingme-now.php'),
        ], 'pingme-now-config');

        $this->publishes([
            __DIR__.'/../scripts/pingme-backup.sh' => base_path('scripts/pingme-backup.sh'),
            __DIR__.'/../scripts/pingme-backup.bat' => base_path('scripts/pingme-backup.bat'),
            __DIR__.'/../scripts/pingme-backup.ps1' => base_path('scripts/pingme-backup.ps1'),
        ], 'pingme-now-scripts');

        if ($this->app->runningInConsole()) {
            $this->commands([EnrollCommand::class, InstallCommand::class, NowCommand::class]);
        }

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            if (config('pingme-now.enabled')) {
                $this->app->make(LogSpool::class)->append($event);
            }
        });

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (config('pingme-now.enabled')) {
                $schedule->command('pingme:now --quiet')->everyMinute()->withoutOverlapping();
            }
        });
    }
}
