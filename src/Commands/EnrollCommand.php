<?php

namespace MichalKortas\PingMeNow\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use MichalKortas\PingMeNow\Support\EnvironmentFile;
use MichalKortas\PingMeNow\Transport\HubClient;
use Throwable;

class EnrollCommand extends Command
{
    protected $signature = 'pingme:enroll
        {--url= : Adres Huba}
        {--name= : Nazwa aplikacji}
        {--environment=production : Środowisko production lub demo}
        {--resume : Odbierz zatwierdzoną konfigurację}';

    protected $description = 'Bezpiecznie zgłoś aplikację do zatwierdzenia w PingMe bez przekazywania tokenu';

    public function handle(EnvironmentFile $environment, HubClient $hub): int
    {
        if (! extension_loaded('sodium')) {
            $this->error('Rozszerzenie PHP sodium jest wymagane.');

            return self::FAILURE;
        }

        return $this->option('resume')
            ? $this->resume($environment, $hub)
            : $this->requestEnrollment();
    }

    private function requestEnrollment(): int
    {
        $url = rtrim((string) ($this->option('url') ?: $this->ask('Adres Huba')), '/');
        $name = trim((string) ($this->option('name') ?: config('app.name', 'Laravel')));
        $environment = strtolower((string) $this->option('environment'));

        if (! filter_var($url, FILTER_VALIDATE_URL) || $name === '' || ! in_array($environment, ['production', 'demo'], true)) {
            $this->error('Nieprawidłowe dane zgłoszenia.');

            return self::FAILURE;
        }

        if (is_file($this->statePath())) {
            $this->error('Istnieje aktywne zgłoszenie. Użyj --resume albo usuń je po wygaśnięciu.');

            return self::FAILURE;
        }

        $keypair = sodium_crypto_box_keypair();
        $publicKey = sodium_crypto_box_publickey($keypair);
        $secretKey = sodium_crypto_box_secretkey($keypair);
        $claimSecret = bin2hex(random_bytes(48));
        $id = (string) Str::uuid();

        try {
            Http::acceptJson()->asJson()->timeout((int) config('pingme-now.timeout', 10))
                ->post($url.'/api/v1/enrollments', [
                    'id' => $id,
                    'name' => $name,
                    'environment' => $environment,
                    'primary_url' => filter_var(config('app.url'), FILTER_VALIDATE_URL) ? config('app.url') : null,
                    'public_key' => base64_encode($publicKey),
                    'claim_secret_hash' => hash('sha256', $claimSecret),
                    'metadata' => [
                        'framework' => 'laravel',
                        'laravel_version' => app()->version(),
                        'php_version' => PHP_VERSION,
                        'hostname' => gethostname() ?: null,
                    ],
                ])->throw();
        } catch (Throwable) {
            sodium_memzero($claimSecret);
            sodium_memzero($secretKey);
            $this->error('Hub odrzucił zgłoszenie lub jest niedostępny.');

            return self::FAILURE;
        }

        $this->writeState([
            'id' => $id,
            'url' => $url,
            'public_key' => base64_encode($publicKey),
            'secret_key' => base64_encode($secretKey),
            'claim_secret' => $claimSecret,
        ]);
        sodium_memzero($claimSecret);
        sodium_memzero($secretKey);

        $this->info('Zgłoszenie wysłane. Administrator musi je zatwierdzić w Hubie.');
        $this->line('Po zatwierdzeniu uruchom: php artisan pingme:enroll --resume');

        return self::SUCCESS;
    }

    private function resume(EnvironmentFile $environment, HubClient $hub): int
    {
        $state = $this->readState();
        if ($state === null) {
            $this->error('Brak aktywnego zgłoszenia.');

            return self::FAILURE;
        }

        try {
            $response = Http::acceptJson()->withHeaders([
                'X-PingMe-Enrollment-Secret' => $state['claim_secret'],
            ])->timeout((int) config('pingme-now.timeout', 10))
                ->get($state['url'].'/api/v1/enrollments/'.$state['id'])->throw()->json();
        } catch (Throwable) {
            $this->error('Nie udało się odczytać zgłoszenia.');

            return self::FAILURE;
        }

        if (($response['status'] ?? null) === 'pending') {
            $this->warn('Zgłoszenie nadal oczekuje na zatwierdzenie.');

            return self::FAILURE;
        }

        if (($response['status'] ?? null) !== 'approved' || empty($response['encrypted_token'])) {
            $this->removeState();
            $this->error('Zgłoszenie wygasło albo zostało odrzucone.');

            return self::FAILURE;
        }

        $publicKey = base64_decode($state['public_key'], true);
        $secretKey = base64_decode($state['secret_key'], true);
        $encryptedToken = base64_decode($response['encrypted_token'], true);
        $keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey($secretKey, $publicKey);
        $token = $encryptedToken === false ? false : sodium_crypto_box_seal_open($encryptedToken, $keypair);

        if (! is_string($token) || ! str_starts_with($token, 'pm_')) {
            $this->error('Nie udało się odszyfrować konfiguracji.');

            return self::FAILURE;
        }

        $environment->write('PINGME_ENABLED', 'true');
        $environment->write('PINGME_URL', $state['url']);
        $environment->write('PINGME_TOKEN', $token);

        if ($this->laravel->configurationIsCached()) {
            $this->callSilent('config:clear');
        }

        config(['pingme-now.enabled' => true, 'pingme-now.url' => $state['url'], 'pingme-now.token' => $token]);

        try {
            $hub->ping();
            Http::acceptJson()->withHeaders(['X-PingMe-Enrollment-Secret' => $state['claim_secret']])
                ->timeout((int) config('pingme-now.timeout', 10))
                ->post($state['url'].'/api/v1/enrollments/'.$state['id'].'/complete')->throw();
        } catch (Throwable) {
            $this->error('Konfiguracja została zapisana, ale weryfikacja połączenia nie powiodła się.');

            return self::FAILURE;
        } finally {
            sodium_memzero($token);
            sodium_memzero($secretKey);
        }

        $this->removeState();
        $this->call('vendor:publish', ['--tag' => 'pingme-now-scripts', '--force' => true]);
        $this->info('Aplikacja została bezpiecznie połączona z PingMe.');

        return $this->call('pingme:now');
    }

    private function statePath(): string
    {
        return storage_path('app/private/pingme-enrollment.json');
    }

    private function writeState(array $state): void
    {
        $directory = dirname($this->statePath());
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        file_put_contents($this->statePath(), json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($this->statePath(), 0600);
    }

    private function readState(): ?array
    {
        if (! is_file($this->statePath())) {
            return null;
        }

        try {
            $state = json_decode((string) file_get_contents($this->statePath()), true, flags: JSON_THROW_ON_ERROR);

            return is_array($state) ? $state : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function removeState(): void
    {
        if (is_file($this->statePath())) {
            unlink($this->statePath());
        }
    }
}
