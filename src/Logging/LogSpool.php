<?php

namespace MichalKortas\PingMeNow\Logging;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Str;

class LogSpool
{
    private const LEVELS = ['error' => 400, 'critical' => 500, 'alert' => 550, 'emergency' => 600];

    public function append(MessageLogged $event): void
    {
        $level = strtolower((string) $event->level);

        if (! isset(self::LEVELS[$level])) {
            return;
        }

        $path = $this->path();
        $this->ensureDirectory(dirname($path));
        $this->trimIfNeeded($path);

        $record = json_encode([
            'event_uuid' => (string) Str::uuid(),
            'level' => $level,
            'message' => Str::limit((string) $event->message, 65535, ''),
            'context' => $this->redact($event->context),
            'recorded_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($record !== false) {
            file_put_contents($path, $record.PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

    public function take(int $limit = 200): array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'c+');

        if ($handle === false || ! flock($handle, LOCK_EX)) {
            return [];
        }

        $contents = stream_get_contents($handle) ?: '';
        $lines = array_values(array_filter(explode("\n", $contents)));
        $taken = array_slice($lines, 0, $limit);
        $remaining = array_slice($lines, $limit);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $remaining === [] ? '' : implode(PHP_EOL, $remaining).PHP_EOL);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return array_values(array_filter(array_map(
            fn (string $line) => json_decode($line, true),
            $taken,
        ), 'is_array'));
    }

    public function restore(array $records): void
    {
        foreach ($records as $record) {
            $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($line !== false) {
                file_put_contents($this->path(), $line.PHP_EOL, FILE_APPEND | LOCK_EX);
            }
        }
    }

    private function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match('/password|token|secret|authorization|cookie|api[_-]?key/i', $key)) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $redacted = [];

            foreach ($value as $itemKey => $item) {
                $redacted[$itemKey] = $this->redact($item, (string) $itemKey);
            }

            return $redacted;
        }

        if (is_object($value)) {
            return $value instanceof \Throwable
                ? ['class' => $value::class, 'message' => $value->getMessage(), 'file' => $value->getFile(), 'line' => $value->getLine(), 'trace' => Str::limit($value->getTraceAsString(), 32768, '')]
                : ['class' => $value::class];
        }

        return is_string($value) ? Str::limit($value, 32768, '') : $value;
    }

    private function path(): string
    {
        return rtrim((string) config('pingme-now.spool_path'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'logs.ndjson';
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0750, true);
        }
    }

    private function trimIfNeeded(string $path): void
    {
        $max = (int) config('pingme-now.spool_max_bytes');

        if (is_file($path) && filesize($path) > $max) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            file_put_contents($path, implode(PHP_EOL, array_slice($lines, (int) floor(count($lines) / 2))).PHP_EOL, LOCK_EX);
        }
    }
}
