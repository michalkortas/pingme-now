<?php

namespace MichalKortas\PingMeNow\Support;

class EnvironmentFile
{
    public function write(string $key, string $value): void
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
