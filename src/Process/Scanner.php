<?php

namespace Sauron\Process;

use Sauron\Registry;
use Sauron\Server;

final class Scanner
{
    /** Where Zed keeps the servers it downloads itself. */
    private const ZED_DIRS = [
        '/.local/share/zed/extensions/work/',
        '/.local/share/zed/remote_extensions/work/',
        '/.local/share/zed/languages/',
    ];

    /** Wrappers to look past when deciding which executable a process really is. */
    private const INTERPRETERS = ['node', 'php', 'python', 'python3', 'ruby', 'deno', 'bun'];

    /**
     * Every language server process running right now, whether or not the
     * manifest knows about it.
     *
     * @return list<RunningProcess>
     */
    public static function scan(): array
    {
        $patterns = [];
        foreach (Registry::allServers() as $server) {
            $patterns[$server->id] = $server->processPattern();
        }

        $found = [];

        foreach (glob('/proc/[0-9]*') ?: [] as $dir) {
            $pid = (int) basename($dir);
            $command = self::commandOf($pid);

            if (null === $command) {
                continue;
            }

            $executable = self::executableOf($command);
            $serverId = self::match($executable, $patterns);

            if (null === $serverId && !self::isZedManaged($executable)) {
                continue;
            }

            [$rss, $pss] = self::memoryOf($pid);

            if (0 === $rss) {
                continue;
            }

            $found[] = new RunningProcess(
                pid: $pid,
                command: $command,
                executable: $executable,
                rssKb: $rss,
                pssKb: $pss,
                workingDirectory: @readlink($dir . '/cwd') ?: null,
                serverId: $serverId,
            );
        }

        usort($found, static fn (RunningProcess $a, RunningProcess $b) => $b->pssKb <=> $a->pssKb);

        return $found;
    }

    private static function commandOf(int $pid): ?string
    {
        $raw = @file_get_contents('/proc/' . $pid . '/cmdline');

        if (false === $raw || '' === $raw) {
            return null;
        }

        return trim(str_replace("\0", ' ', $raw));
    }

    /**
     * The first argument that is not an interpreter, so a Node-hosted server is
     * identified by its script rather than by /usr/bin/node.
     */
    private static function executableOf(string $command): string
    {
        $parts = preg_split('/\s+/', $command) ?: [];

        foreach ($parts as $part) {
            if ('' === $part || str_starts_with($part, '-')) {
                continue;
            }

            if (\in_array(basename($part), self::INTERPRETERS, true)) {
                continue;
            }

            return $part;
        }

        return $parts[0] ?? $command;
    }

    /** @param array<string, string> $patterns */
    private static function match(string $executable, array $patterns): ?string
    {
        foreach ($patterns as $id => $pattern) {
            $regex = '/(?<![A-Za-z0-9_])' . preg_quote($pattern, '/') . '(?![A-Za-z0-9_])/';

            if (1 === preg_match($regex, $executable)) {
                return $id;
            }
        }

        return null;
    }

    private static function isZedManaged(string $executable): bool
    {
        foreach (self::ZED_DIRS as $dir) {
            if (str_contains($executable, $dir)) {
                return true;
            }
        }

        return false;
    }

    /**
     * PSS splits shared pages across the processes mapping them, so four Node
     * servers sharing a runtime are not counted four times over.
     *
     * @return array{int, int}
     */
    private static function memoryOf(int $pid): array
    {
        $rollup = @file_get_contents('/proc/' . $pid . '/smaps_rollup');
        $rss = 0;
        $pss = 0;

        if (false !== $rollup) {
            preg_match('/^Rss:\s+(\d+)/m', $rollup, $r);
            preg_match('/^Pss:\s+(\d+)/m', $rollup, $p);
            $rss = (int) ($r[1] ?? 0);
            $pss = (int) ($p[1] ?? 0);
        }

        if (0 === $rss) {
            $status = @file_get_contents('/proc/' . $pid . '/status');

            if (false !== $status && 1 === preg_match('/^VmRSS:\s+(\d+)/m', $status, $m)) {
                $rss = (int) $m[1];
                $pss = $rss;
            }
        }

        return [$rss, $pss];
    }

    public static function serverOf(string $id): ?Server
    {
        foreach (Registry::allServers() as $server) {
            if ($server->id === $id) {
                return $server;
            }
        }

        return null;
    }
}
