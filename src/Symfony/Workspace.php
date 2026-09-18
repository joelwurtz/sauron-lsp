<?php

namespace Sauron\Symfony;

use Symfony\Component\Filesystem\Path;

final class Workspace
{
    /**
     * Git checkouts under a directory, worktrees included since each one is a
     * separate tree the language server indexes on its own.
     *
     * @return list<string>
     */
    public static function repositories(string $root, int $maxDepth = 4): array
    {
        $found = [];
        self::walk($root, $maxDepth, $found);
        sort($found);

        return $found;
    }

    /** @param list<string> $found */
    private static function walk(string $directory, int $depth, array &$found): void
    {
        if ($depth < 0 || !is_dir($directory)) {
            return;
        }

        if (file_exists($directory . '/.git')) {
            $found[] = $directory;

            // A repository may still hold worktrees of other checkouts below it.
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry || '.git' === $entry || 'vendor' === $entry || 'node_modules' === $entry) {
                continue;
            }

            $child = $directory . '/' . $entry;

            if (is_dir($child) && !is_link($child)) {
                self::walk($child, $depth - 1, $found);
            }
        }
    }

    /** Where git reads local, unshared ignore rules for this checkout. */
    public static function excludeFile(string $repository): ?string
    {
        $common = self::git($repository, 'rev-parse --git-common-dir');

        if (null === $common) {
            return null;
        }

        return Path::join(Path::makeAbsolute($common, $repository), 'info/exclude');
    }

    public static function isTracked(string $repository, string $file): bool
    {
        return null !== self::git($repository, \sprintf('ls-files --error-unmatch %s', escapeshellarg($file)));
    }

    public static function isIgnored(string $repository, string $file): bool
    {
        return null !== self::git($repository, \sprintf('check-ignore %s', escapeshellarg($file)));
    }

    private static function git(string $repository, string $arguments): ?string
    {
        $command = \sprintf('git -C %s %s 2>/dev/null', escapeshellarg($repository), $arguments);
        exec($command, $output, $status);

        return 0 === $status ? trim(implode("\n", $output)) : null;
    }
}
