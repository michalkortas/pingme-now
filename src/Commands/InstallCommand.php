<?php

namespace MichalKortas\PingMeNow\Commands;

use Illuminate\Console\Command;
use MichalKortas\PingMeNow\Transport\HubClient;
use Throwable;

class InstallCommand extends Command
{
    protected $signature = 'pingme:install {--url=} {--token=}';

    protected $description = 'Configure the PingMe hub connection';

    public function handle(HubClient $client): int
    {
        $url = rtrim((string) ($this->option('url') ?: $this->ask('Adres Huba')), '/');
        $token = (string) ($this->option('token') ?: $this->secret('Token integracyjny'));

        if (! filter_var($url, FILTER_VALIDATE_URL) || $token === '') {
            $this->error('Nieprawidłowy adres lub token.');

            return self::FAILURE;
        }

        $this->writeEnvironment('PINGME_ENABLED', 'true');
        $this->writeEnvironment('PINGME_URL', $url);
        $this->writeEnvironment('PINGME_TOKEN', $token);

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

    private function writeEnvironment(string $key, string $value): void
    {
        $path = app()->environmentFilePath();
        $contents = is_file($path) ? (string) file_get_contents($path) : '';
        $line = $key.'="'.addcslashes($value, '"\\').'"';

        if (preg_match('/^'.preg_quote($key, '/').'=.*/m', $contents)) {
            $contents = (string) preg_replace('/^'.preg_quote($key, '/').'=.*/m', $line, $contents);
        } else {
            $contents = rtrim($contents).PHP_EOL.$line.PHP_EOL;
        }

        file_put_contents($path, $contents, LOCK_EX);
    }
}
