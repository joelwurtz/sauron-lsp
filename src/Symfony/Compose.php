<?php

namespace Sauron\Symfony;

use Symfony\Component\Filesystem\Path;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads compose files straight from disk. `docker compose config` would be more
 * faithful, but it resolves variables from the environment the stack is normally
 * started with, and returns an empty stack without them.
 */
final class Compose
{
    private const FILENAMES = ['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml'];

    /**
     * Services declaring a bind mount, keyed by service name.
     *
     * @return array<string, array{workingDir: ?string, mounts: array<string, string>, runsPhp: bool}>
     */
    public static function services(string $projectRoot): array
    {
        $services = [];

        foreach (self::files($projectRoot) as $file) {
            $parsed = self::parse($file);

            foreach ($parsed['services'] ?? [] as $name => $service) {
                if (!\is_array($service)) {
                    continue;
                }

                $mounts = self::mounts($service['volumes'] ?? [], $projectRoot, \dirname($file));

                if ([] === $mounts && !isset($services[$name])) {
                    continue;
                }

                $services[$name] = [
                    'workingDir' => \is_string($service['working_dir'] ?? null) ? $service['working_dir'] : ($services[$name]['workingDir'] ?? null),
                    'mounts' => [...($services[$name]['mounts'] ?? []), ...$mounts],
                    'runsPhp' => self::runsPhp($service) || ($services[$name]['runsPhp'] ?? false),
                ];
            }
        }

        return $services;
    }

    /**
     * Whether the image can run PHP at all. Only the leaf of a build path says
     * what an image is: the rest routinely runs through vendor directories
     * such as castor-php/.
     *
     * @param array<string, mixed> $service
     */
    private static function runsPhp(array $service): bool
    {
        $build = $service['build'] ?? [];
        $context = \is_string($build) ? $build : (\is_array($build) ? $build['context'] ?? null : null);
        $dockerfile = \is_array($build) && \is_string($build['dockerfile'] ?? null) ? $build['dockerfile'] : null;

        $haystack = strtolower(implode(' ', array_filter([
            \is_string($service['image'] ?? null) ? $service['image'] : null,
            \is_string($context) ? basename(Path::canonicalize($context)) : null,
            null !== $dockerfile ? basename(\dirname($dockerfile)) : null,
            null !== $dockerfile ? basename($dockerfile) : null,
        ])));

        return 1 === preg_match('/(?<![a-z0-9])php(?![a-z0-9])/', $haystack);
    }

    /**
     * Host directory => container directory, for bind mounts only.
     *
     * @return array<string, string>
     */
    private static function mounts(mixed $volumes, string $projectRoot, string $fileDir): array
    {
        $mounts = [];

        foreach (\is_array($volumes) ? $volumes : [] as $volume) {
            if (\is_string($volume)) {
                $parts = explode(':', $volume);

                if (\count($parts) < 2) {
                    continue;
                }

                [$source, $target] = $parts;
            } elseif (\is_array($volume) && 'bind' === ($volume['type'] ?? null)) {
                $source = $volume['source'] ?? null;
                $target = $volume['target'] ?? null;
            } else {
                continue;
            }

            if (!\is_string($source) || !\is_string($target) || !str_starts_with($target, '/')) {
                continue;
            }

            // A named volume, not a path on the host.
            if (!str_starts_with($source, '/') && !str_starts_with($source, '.')) {
                continue;
            }

            $host = Path::canonicalize(Path::makeAbsolute($source, $fileDir));

            if (!Path::isBasePath($host, $projectRoot) && !Path::isBasePath($projectRoot, $host)) {
                continue;
            }

            $mounts[$host] = rtrim($target, '/');
        }

        return $mounts;
    }

    /** @return list<string> */
    private static function files(string $projectRoot): array
    {
        $files = [];

        foreach (self::FILENAMES as $name) {
            $path = Path::join($projectRoot, $name);

            if (is_file($path)) {
                $files[] = $path;
                $files = [...$files, ...self::included($path)];
            }
        }

        return $files;
    }

    /** @return list<string> */
    private static function included(string $file): array
    {
        $parsed = self::parse($file);
        $files = [];

        foreach ($parsed['include'] ?? [] as $include) {
            $paths = \is_string($include) ? [$include] : ($include['path'] ?? []);

            foreach (\is_array($paths) ? $paths : [$paths] as $path) {
                if (!\is_string($path)) {
                    continue;
                }

                $resolved = Path::makeAbsolute($path, \dirname($file));

                if (is_file($resolved)) {
                    $files[] = $resolved;
                }
            }
        }

        return $files;
    }

    /** @return array<string, mixed> */
    private static function parse(string $file): array
    {
        try {
            $parsed = Yaml::parseFile($file, Yaml::PARSE_CUSTOM_TAGS);
        } catch (\Throwable) {
            return [];
        }

        return \is_array($parsed) ? $parsed : [];
    }
}
