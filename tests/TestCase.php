<?php

namespace MichalKortas\PingMeNow\Tests;

use MichalKortas\PingMeNow\PingMeNowServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [PingMeNowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $app['config']->set('pingme-now.enabled', true);
        $app['config']->set('pingme-now.url', 'https://pingme.test');
        $app['config']->set('pingme-now.token', 'pm_test');
        $app['config']->set('pingme-now.spool_path', storage_path('framework/pingme-test'));
    }
}
