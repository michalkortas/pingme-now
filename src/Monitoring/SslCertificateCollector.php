<?php

namespace MichalKortas\PingMeNow\Monitoring;

use Illuminate\Support\Carbon;
use Throwable;

class SslCertificateCollector
{
    public function collect(?string $url): ?array
    {
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;
        $scheme = is_string($url) ? parse_url($url, PHP_URL_SCHEME) : null;
        $port = is_string($url) ? parse_url($url, PHP_URL_PORT) : null;

        if (strtolower((string) $scheme) !== 'https' || ! is_string($host) || $host === '') {
            return null;
        }

        $port = is_int($port) ? $port : 443;
        $socketHost = str_contains($host, ':') ? "[{$host}]" : $host;

        try {
            $context = stream_context_create(['ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
            ]]);
            $client = @stream_socket_client(
                "ssl://{$socketHost}:{$port}",
                $errorCode,
                $errorMessage,
                (int) config('pingme-now.timeout', 10),
                STREAM_CLIENT_CONNECT,
                $context,
            );

            if ($client === false) {
                return $this->failure($errorMessage ?: 'Nie udało się nawiązać połączenia TLS.');
            }

            try {
                $params = stream_context_get_params($client);
                $certificate = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
            } finally {
                fclose($client);
            }

            if (! is_array($certificate) || ! isset($certificate['validTo_time_t'])) {
                return $this->failure('Nie udało się odczytać certyfikatu TLS.');
            }

            $checkedAt = now();
            $validTo = Carbon::createFromTimestamp($certificate['validTo_time_t']);
            $issuer = data_get($certificate, 'issuer.O');

            if (is_array($issuer)) {
                $issuer = implode(', ', array_filter($issuer, 'is_string'));
            }

            return [
                'source' => 'agent',
                'days_remaining' => $checkedAt->diffInSeconds($validTo, false) / 86400,
                'valid_to' => $validTo->toIso8601String(),
                'issuer' => is_string($issuer) && $issuer !== '' ? $issuer : null,
                'error' => null,
                'checked_at' => $checkedAt->toIso8601String(),
            ];
        } catch (Throwable $exception) {
            return $this->failure($exception->getMessage());
        }
    }

    private function failure(string $message): array
    {
        return [
            'source' => 'agent',
            'days_remaining' => 0,
            'valid_to' => null,
            'issuer' => null,
            'error' => $message,
            'checked_at' => now()->toIso8601String(),
        ];
    }
}
