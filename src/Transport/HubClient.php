<?php

namespace MichalKortas\PingMeNow\Transport;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HubClient
{
    public function verify(): array
    {
        return $this->request()->get($this->url('api/v1/agent'))->throw()->json();
    }

    public function ping(): array
    {
        return $this->request()->post($this->url('api/v1/ping'))->throw()->json();
    }

    public function snapshot(array $payload): Response
    {
        return $this->request()->post($this->url('api/v1/ingest/snapshot'), $payload)->throw();
    }

    public function logs(array $logs): Response
    {
        return $this->request()->post($this->url('api/v1/ingest/logs'), ['logs' => $logs])->throw();
    }

    public function dependencies(string $hash, array $packages): Response
    {
        return $this->request()->post($this->url('api/v1/ingest/dependencies'), [
            'lock_hash' => $hash,
            'packages' => $packages,
        ])->throw();
    }

    private function request(): PendingRequest
    {
        $token = config('pingme-now.token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('PINGME_TOKEN is not configured.');
        }

        return Http::acceptJson()
            ->asJson()
            ->withToken($token)
            ->timeout((int) config('pingme-now.timeout', 10));
    }

    private function url(string $path): string
    {
        $url = config('pingme-now.url');

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('PINGME_URL is not configured.');
        }

        return rtrim($url, '/').'/'.ltrim($path, '/');
    }
}
