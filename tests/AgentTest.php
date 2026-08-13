<?php

use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use MichalKortas\PingMeNow\Logging\LogSpool;
use MichalKortas\PingMeNow\Monitoring\SslCertificateCollector;

it('installs with URL and token passed in two explicit steps', function () {
    $environmentDirectory = sys_get_temp_dir().'/pingme-now-'.bin2hex(random_bytes(6));
    mkdir($environmentDirectory);
    file_put_contents($environmentDirectory.'/.env', "APP_NAME=Test\n");
    app()->useEnvironmentPath($environmentDirectory);

    Http::fake([
        'https://hub.pingme.test/api/v1/ping' => Http::response([
            'connected' => true,
            'application' => ['name' => 'Test application'],
        ]),
        'https://hub.pingme.test/api/v1/ingest/*' => Http::response(['accepted' => true], 202),
    ]);

    $this->artisan('pingme:install', [
        '--url' => 'https://hub.pingme.test/',
        '--token' => 'pm_generated_by_hub',
    ])->assertSuccessful();

    $environment = file_get_contents($environmentDirectory.'/.env');

    expect($environment)
        ->toContain('PINGME_ENABLED="true"')
        ->toContain('PINGME_URL="https://hub.pingme.test"')
        ->toContain('PINGME_TOKEN="pm_generated_by_hub"');

    Http::assertSent(fn ($request) => $request->url() === 'https://hub.pingme.test/api/v1/ping'
        && $request->method() === 'POST');

    unlink($environmentDirectory.'/.env');
    rmdir($environmentDirectory);
});

it('sends the current status using pingme now', function () {
    Http::fake([
        'https://pingme.test/api/v1/ingest/*' => Http::response(['accepted' => true], 202),
    ]);

    $this->artisan('pingme:now')->assertSuccessful();

    Http::assertSent(fn ($request) => $request->url() === 'https://pingme.test/api/v1/ingest/snapshot'
        && $request->hasHeader('Authorization', 'Bearer pm_test')
        && ! $request->offsetExists('ssl')
        && ! array_key_exists('ssl_days_remaining', $request['metrics']));
});

it('sends SSL validation performed from the application network', function () {
    config()->set('pingme-now.ssl_url', 'https://internal.example.test');
    $ssl = [
        'source' => 'agent',
        'days_remaining' => 99.5,
        'valid_to' => now()->addDays(99)->toIso8601String(),
        'issuer' => 'Test CA',
        'error' => null,
        'checked_at' => now()->toIso8601String(),
    ];
    $collector = Mockery::mock(SslCertificateCollector::class);
    $collector->shouldReceive('collect')
        ->once()
        ->with('https://internal.example.test')
        ->andReturn($ssl);
    app()->instance(SslCertificateCollector::class, $collector);

    Http::fake([
        'https://pingme.test/api/v1/ingest/*' => Http::response(['accepted' => true], 202),
    ]);

    $this->artisan('pingme:now')->assertSuccessful();

    Http::assertSent(function (Request $request) use ($ssl) {
        return $request->url() === 'https://pingme.test/api/v1/ingest/snapshot'
            && $request['metrics']['ssl_days_remaining'] === 99.5
            && $request['ssl'] === $ssl;
    });
});

it('does not fail the scheduler when the hub is unavailable', function () {
    Http::fake([
        'https://pingme.test/api/v1/ingest/*' => Http::response([], 503),
    ]);

    $this->artisan('pingme:now')
        ->expectsOutputToContain('Nie udało się wysłać statusu')
        ->assertSuccessful();
});

it('redacts secrets in buffered logs', function () {
    $spool = app(LogSpool::class);
    $spool->append(new MessageLogged('error', 'Failure', [
        'token' => 'secret-token',
        'safe' => 'visible',
    ]));

    $logs = $spool->take();

    expect($logs)->toHaveCount(1)
        ->and($logs[0]['context']['token'])->toBe('[REDACTED]')
        ->and($logs[0]['context']['safe'])->toBe('visible');
});

it('enrolls without exposing the integration token in command output', function () {
    $statePath = storage_path('app/private/pingme-enrollment.json');
    @unlink($statePath);
    $environmentDirectory = sys_get_temp_dir().'/pingme-enroll-'.bin2hex(random_bytes(6));
    mkdir($environmentDirectory);
    file_put_contents($environmentDirectory.'/.env', "APP_NAME=Test\n");
    app()->useEnvironmentPath($environmentDirectory);

    Http::fake([
        'https://hub.pingme.test/api/v1/enrollments' => Http::response(['status' => 'pending'], 201),
    ]);

    $pendingExitCode = Artisan::call('pingme:enroll', [
        '--url' => 'https://hub.pingme.test',
        '--name' => 'Test application',
        '--environment' => 'production',
    ]);
    $pendingOutput = Artisan::output();

    expect($pendingExitCode)->toBe(0)
        ->and($pendingOutput)->toContain('Zgłoszenie wysłane');

    $state = json_decode(file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR);
    $token = 'pm_test_enrollment_token';
    $encryptedToken = sodium_crypto_box_seal($token, base64_decode($state['public_key'], true));

    Http::fake(function (Request $request) use ($encryptedToken) {
        if ($request->method() === 'GET' && str_contains($request->url(), '/api/v1/enrollments/')) {
            return Http::response(['status' => 'approved', 'encrypted_token' => base64_encode($encryptedToken)]);
        }

        if ($request->method() === 'POST' && str_ends_with($request->url(), '/complete')) {
            return Http::response(['status' => 'completed']);
        }

        if (str_ends_with($request->url(), '/api/v1/ping')) {
            return Http::response(['connected' => true, 'application' => ['name' => 'Test application']]);
        }

        return Http::response(['accepted' => true], 202);
    });

    $completedExitCode = Artisan::call('pingme:enroll', ['--resume' => true]);
    $completedOutput = Artisan::output();

    expect($completedExitCode)->toBe(0)
        ->and($completedOutput)->toContain('Aplikacja została bezpiecznie połączona')
        ->and($pendingOutput)->not->toContain($token)
        ->and($completedOutput)->not->toContain($token)
        ->and(file_get_contents($environmentDirectory.'/.env'))->toContain('PINGME_TOKEN="'.$token.'"')
        ->and(is_file($statePath))->toBeFalse();

    unlink($environmentDirectory.'/.env');
    rmdir($environmentDirectory);
});
