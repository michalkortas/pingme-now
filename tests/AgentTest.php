<?php

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use MichalKortas\PingMeNow\Logging\LogSpool;

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
        && $request->hasHeader('Authorization', 'Bearer pm_test'));
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
