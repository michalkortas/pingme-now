<?php

namespace MichalKortas\PingMeNow\Monitoring;

class DependencyCollector
{
    public function collect(): array
    {
        $packages = [...$this->composerPackages(), ...$this->npmPackages()];
        $hashParts = [];

        foreach ([base_path('composer.lock'), base_path('package-lock.json')] as $path) {
            if (is_file($path)) {
                $hashParts[] = hash_file('sha256', $path);
            }
        }

        return ['hash' => hash('sha256', implode('|', $hashParts)), 'packages' => $packages];
    }

    private function composerPackages(): array
    {
        $lock = $this->json(base_path('composer.lock'));
        $manifest = $this->json(base_path('composer.json'));
        $direct = array_fill_keys(array_keys([
            ...($manifest['require'] ?? []),
            ...($manifest['require-dev'] ?? []),
        ]), true);

        return array_map(fn (array $package) => [
            'ecosystem' => 'composer',
            'name' => $package['name'],
            'version' => ltrim((string) ($package['version'] ?? ''), 'v'),
            'direct' => isset($direct[$package['name']]),
        ], [...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])]);
    }

    private function npmPackages(): array
    {
        $lock = $this->json(base_path('package-lock.json'));
        $manifest = $this->json(base_path('package.json'));
        $direct = array_fill_keys(array_keys([
            ...($manifest['dependencies'] ?? []),
            ...($manifest['devDependencies'] ?? []),
            ...($manifest['optionalDependencies'] ?? []),
        ]), true);
        $packages = [];

        if (isset($lock['packages']) && is_array($lock['packages'])) {
            foreach ($lock['packages'] as $path => $package) {
                if ($path === '' || ! isset($package['version'])) {
                    continue;
                }

                $name = preg_replace('#^.*node_modules/#', '', (string) $path);
                $packages[$name] = [
                    'ecosystem' => 'npm',
                    'name' => $name,
                    'version' => (string) $package['version'],
                    'direct' => isset($direct[$name]),
                ];
            }

            return array_values($packages);
        }

        foreach ($lock['dependencies'] ?? [] as $name => $package) {
            $packages[$name] = [
                'ecosystem' => 'npm',
                'name' => $name,
                'version' => (string) ($package['version'] ?? ''),
                'direct' => isset($direct[$name]),
            ];
        }

        return array_values($packages);
    }

    private function json(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        return json_decode((string) file_get_contents($path), true) ?: [];
    }
}
