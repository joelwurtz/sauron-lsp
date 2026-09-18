<?php

namespace Sauron\Zed;

use Sauron\Language;
use Sauron\Registry;

final class Generator
{
    /**
     * Top-level keys Sauron writes into. Anything else in settings.json is left
     * strictly alone, and even inside these we only own the sub-keys we emit.
     */
    public const OWNED = ['auto_install_extensions', 'languages', 'lsp', 'code_lens'];

    public static function settingsPath(): string
    {
        return $_SERVER['HOME'] . '/.config/zed/settings.json';
    }

    /**
     * The config Sauron wants, independent of what is already there.
     *
     * @return array<string, mixed>
     */
    public function patch(): array
    {
        $extensions = [];
        $languages = [];
        $lsp = [];
        $globals = Registry::globals();

        foreach ([...$globals, ...self::serversOf(Registry::languages())] as $server) {
            if (null !== $server->extension) {
                $extensions[$server->extension] = true;
            }

            $entry = $server->lspSettings();
            if ([] !== $entry) {
                $lsp[$server->id] = $entry;
            }
        }

        foreach (Registry::languages() as $language) {
            $languages[$language->zedName] = [
                'language_servers' => $language->languageServers($globals),
                ...$language->settings,
            ];
        }

        ksort($extensions);
        ksort($languages);
        ksort($lsp);

        return [
            ...Registry::extraSettings(),
            'auto_install_extensions' => $extensions,
            'languages' => $languages,
            'lsp' => $lsp,
        ];
    }

    /**
     * @param array<string, mixed> $current
     *
     * @return array<string, mixed>
     */
    public function merge(array $current): array
    {
        $patch = $this->patch();
        $merged = $current;

        $merged['auto_install_extensions'] = [
            ...($current['auto_install_extensions'] ?? []),
            ...$patch['auto_install_extensions'],
        ];

        foreach ($patch['languages'] as $name => $config) {
            $merged['languages'][$name] = [...($current['languages'][$name] ?? []), ...$config];
        }

        foreach ($patch['lsp'] as $id => $config) {
            $merged['lsp'][$id] = $config;
        }

        foreach (Registry::extraSettings() as $key => $value) {
            $merged[$key] = $value;
        }

        return $merged;
    }

    /**
     * lsp.* entries pinning a server we now disable everywhere: leftovers from a
     * previous setup that no longer do anything.
     *
     * @param array<string, mixed> $current
     *
     * @return list<string>
     */
    public function stale(array $current): array
    {
        $disabled = [];
        foreach (Registry::languages() as $language) {
            foreach ($language->disabled as $id) {
                $disabled[$id] = true;
            }
        }

        $stale = [];
        foreach (array_keys($current['lsp'] ?? []) as $id) {
            if (isset($disabled[$id])) {
                $stale[] = $id;
            }
        }

        return $stale;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     *
     * @return list<array{string, string, string}> path, old, new
     */
    public function changes(array $before, array $after): array
    {
        $flatBefore = self::flatten($before);
        $flatAfter = self::flatten($after);
        $changes = [];

        foreach ($flatAfter as $path => $value) {
            $old = $flatBefore[$path] ?? null;
            if ($old !== $value) {
                $changes[] = [$path, $old ?? '—', $value];
            }
        }

        return $changes;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix . '.' . $key;

            if (\is_array($value) && !array_is_list($value) && [] !== $value) {
                $flat = [...$flat, ...self::flatten($value, $path)];

                continue;
            }

            $flat[$path] = json_encode($value, \JSON_UNESCAPED_SLASHES);
        }

        return $flat;
    }

    /**
     * @param list<Language> $languages
     *
     * @return list<\Sauron\Server>
     */
    private static function serversOf(array $languages): array
    {
        $servers = [];
        foreach ($languages as $language) {
            foreach ($language->servers as $server) {
                $servers[$server->id] = $server;
            }
        }

        return array_values($servers);
    }
}
