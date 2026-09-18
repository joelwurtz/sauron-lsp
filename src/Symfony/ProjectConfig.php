<?php

namespace Sauron\Symfony;

use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;

/**
 * Builds the .symfony-lsp.json a project needs when its PHP runs in a container.
 */
final class ProjectConfig
{
    /** Mirrors the language server's own discovery, see Project/ProjectDiscovery.php upstream. */
    private const EXCLUDED = ['.git', 'node_modules', 'var', 'vendor'];

    /**
     * Symfony application roots, relative to the project.
     *
     * @return list<string>
     */
    public static function discover(string $projectRoot): array
    {
        $finder = (new Finder())
            ->files()
            ->name('composer.json')
            ->in($projectRoot)
            ->exclude(self::EXCLUDED)
            ->ignoreUnreadableDirs();

        $roots = [];

        foreach ($finder as $file) {
            $directory = $file->getPath();

            if (!self::isSymfonyApplication($directory)) {
                continue;
            }

            $relative = Path::makeRelative($directory, $projectRoot);
            $roots[] = '' === $relative ? '.' : $relative;
        }

        sort($roots);

        return $roots;
    }

    private static function isSymfonyApplication(string $directory): bool
    {
        $composer = self::json(Path::join($directory, 'composer.json'));

        if (null === $composer) {
            return false;
        }

        $required = \is_string($composer['require']['symfony/framework-bundle'] ?? null);

        if (!$required) {
            $lock = self::json(Path::join($directory, 'composer.lock'));

            foreach ($lock['packages'] ?? [] as $package) {
                if ('symfony/framework-bundle' === ($package['name'] ?? null)) {
                    $required = true;

                    break;
                }
            }
        }

        if (!$required) {
            return false;
        }

        return 'project' === ($composer['type'] ?? null) || is_file(Path::join($directory, 'bin/console'));
    }

    /**
     * The compose service whose container sees a given application, if any.
     *
     * @return array{service: string, containerRoot: string}|null
     */
    public static function resolve(string $projectRoot, string $applicationRoot): ?array
    {
        $absolute = Path::canonicalize(Path::join($projectRoot, $applicationRoot));
        $candidates = [];

        foreach (Compose::services($projectRoot) as $name => $service) {
            if (!$service['runsPhp']) {
                continue;
            }

            foreach ($service['mounts'] as $host => $target) {
                if (!Path::isBasePath($host, $absolute)) {
                    continue;
                }

                $relative = Path::makeRelative($absolute, $host);
                $containerRoot = '' === $relative ? $target : $target . '/' . $relative;

                // A service already working inside the application is the one
                // built to run it; workers and builders are variants of it.
                $score = $service['workingDir'] === $containerRoot ? 2 : 0;
                // Same signal the language server uses: a working directory
                // holding a composer.json belongs to a PHP application.
                $score += 0 === $score && self::worksInsideAnApplication($service) ? 1 : 0;
                $score -= 1 === preg_match('/-(test|worker|builder)/', $name) ? 1 : 0;

                $candidates[] = [
                    'service' => $name,
                    'containerRoot' => $containerRoot,
                    'score' => $score,
                ];
            }
        }

        if ([] === $candidates) {
            return null;
        }

        usort(
            $candidates,
            static fn (array $a, array $b) => [$b['score'], \strlen($a['service']), $a['service']]
                <=> [$a['score'], \strlen($b['service']), $b['service']],
        );

        return ['service' => $candidates[0]['service'], 'containerRoot' => $candidates[0]['containerRoot']];
    }

    /** @param array{workingDir: ?string, mounts: array<string, string>, runsPhp: bool} $service */
    private static function worksInsideAnApplication(array $service): bool
    {
        $hostWorkingDir = self::hostPath($service, $service['workingDir']);

        return null !== $hostWorkingDir && is_file(Path::join($hostWorkingDir, 'composer.json'));
    }

    /** @param array{workingDir: ?string, mounts: array<string, string>, runsPhp: bool} $service */
    private static function hostPath(array $service, ?string $containerPath): ?string
    {
        if (null === $containerPath) {
            return null;
        }

        foreach ($service['mounts'] as $host => $target) {
            if ($target === $containerPath) {
                return $host;
            }

            if (Path::isBasePath($target, $containerPath)) {
                return Path::join($host, Path::makeRelative($containerPath, $target));
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(string $projectRoot): array
    {
        $roots = self::discover($projectRoot);
        $projects = [];

        foreach ($roots as $root) {
            $resolved = self::resolve($projectRoot, $root);

            if (null === $resolved) {
                continue;
            }

            $projects[$root] = [
                // `exec` needs the stack already up, and an editor opens a project
                // long before that. A disposable container costs ~0.4s and works
                // either way; --no-deps keeps it from starting the whole stack.
                'phpCommand' => ['docker', 'compose', 'run', '--rm', '--no-deps', '-T', $resolved['service'], 'php'],
                'containerProjectRoot' => $resolved['containerRoot'],
            ];
        }

        $config = ['version' => 1];

        if ([] !== $roots) {
            $config['projectRoots'] = $roots;
        }

        if ([] !== $projects) {
            $config['projects'] = $projects;
        }

        return $config;
    }

    /** @return array<string, mixed>|null */
    private static function json(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }
}
