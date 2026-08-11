<?php

namespace MichalKortas\PingMeNow\Commands;

use Illuminate\Console\Command;
use MichalKortas\PingMeNow\Support\EnvironmentFile;
use MichalKortas\PingMeNow\Transport\HubClient;
use Throwable;

class InstallCommand extends Command
{
    protected $signature = 'pingme:install {--url=} {--token=}';

    protected $description = 'Configure the PingMe hub connection';

    public function handle(HubClient $client, EnvironmentFile $environment): int
    {
        $url = rtrim((string) ($this->option('url') ?: $this->ask('Adres Huba')), '/');
        $token = (string) ($this->option('token') ?: $this->secret('Token integracyjny'));

        if (! filter_var($url, FILTER_VALIDATE_URL) || $token === '') {
            $this->error('Nieprawidłowy adres lub token.');

            return self::FAILURE;
        }

        $environment->write('PINGME_ENABLED', 'true');
        $environment->write('PINGME_URL', $url);
        $environment->write('PINGME_TOKEN', $token);

        if ($this->laravel->configurationIsCached()) {
            $this->callSilent('config:clear');
        }

        config(['pingme-now.enabled' => true, 'pingme-now.url' => $url, 'pingme-now.token' => $token]);

        try {
            $response = $client->ping();
        } catch (Throwable $exception) {
            $this->error('Połączenie nieudane: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->call('vendor:publish', ['--tag' => 'pingme-now-scripts', '--force' => true]);
        $this->info('Połączono z aplikacją: '.data_get($response, 'application.name'));

        return $this->call('pingme:now');
    }
}
