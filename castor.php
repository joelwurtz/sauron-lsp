<?php

namespace Sauron;

use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Sauron\Process\RunningProcess;
use Sauron\Process\Scanner;
use Sauron\Symfony\Wrapper;
use Sauron\Zed\Generator;
use Sauron\Zed\Jsonc;

use function Castor\context;
use function Castor\fs;
use function Castor\guard_min_version;
use function Castor\import;
use function Castor\io;
use function Castor\run;

guard_min_version('1.7.0');

import(__DIR__ . '/src');

#[AsTask('servers', description: 'Show the preferred language server of every language')]
function servers(#[AsOption(description: 'Only show servers still running on Node')] bool $node = false): void
{
    $globals = Registry::globals();
    $rows = [];

    foreach (Registry::languages() as $language) {
        foreach ($language->servers as $i => $server) {
            if ($node && $server->runtime->isNative()) {
                continue;
            }

            $rows[] = [
                0 === $i ? $language->zedName : '',
                $server->id,
                $server->runtime->isNative() ? $server->runtime->label() : '<fg=yellow>' . $server->runtime->label() . '</>',
                source($server),
                $server->note,
            ];
        }
    }

    io()->table(['Language', 'Server', 'Runtime', 'Source', 'Note'], $rows);

    $servers = Registry::allServers();
    $node = array_filter($servers, static fn (Server $s) => !$s->runtime->isNative());

    io()->writeln(\sprintf(
        ' %d servers, %d native, <fg=yellow>%d on a runtime</> (%s)',
        \count($servers),
        \count($servers) - \count($node),
        \count($node),
        implode(', ', array_map(static fn (Server $s) => $s->id, $node)),
    ));

    foreach ($globals as $global) {
        io()->writeln(\sprintf(' + <info>%s</> on every language (%s)', $global->id, $global->note));
    }
}

#[AsTask('generate', namespace: 'zed', description: 'Write the generated config into Zed settings.json')]
function generate(
    #[AsOption(description: 'Show the changes without writing anything')] bool $dryRun = false,
    #[AsOption(description: 'Write somewhere else than the real Zed settings')] ?string $path = null,
): int {
    $path ??= Generator::settingsPath();
    $generator = new Generator();

    if (!is_file($path)) {
        io()->error(\sprintf('No Zed settings at %s.', $path));

        return 1;
    }

    $raw = file_get_contents($path);

    try {
        $current = Jsonc::decode($raw);
    } catch (\JsonException $e) {
        io()->error(\sprintf('Could not parse %s: %s', $path, $e->getMessage()));

        return 1;
    }

    $merged = $generator->merge($current);
    $changes = $generator->changes($current, $merged);

    if ([] === $changes) {
        io()->success('Zed settings already match the manifest.');

        return 0;
    }

    io()->table(['Setting', 'Before', 'After'], array_map(
        static fn (array $c) => [$c[0], truncate($c[1]), truncate($c[2])],
        $changes,
    ));

    foreach ($generator->stale($current) as $id) {
        io()->warning(\sprintf('lsp.%s is left over from a previous setup: that server is now disabled everywhere.', $id));
    }

    if ($dryRun) {
        io()->note(\sprintf('%d settings would change. Drop --dry-run to apply.', \count($changes)));

        return 0;
    }

    // A structure that does not survive its own encoding would silently corrupt
    // the file, which is how `{}` once became `[]` and broke Zed.
    $encoded = Jsonc::encode($merged);

    if (Jsonc::decode($encoded) != $merged) {
        io()->error('The generated settings do not round-trip; refusing to write.');

        return 1;
    }

    // Zed settings are JSONC; re-encoding drops the comments, so keep the original around.
    $backup = $path . '.bak.' . date('YmdHis');
    fs()->copy($path, $backup);
    fs()->dumpFile($path, $encoded);

    io()->success(\sprintf('%d settings written to %s', \count($changes), $path));
    io()->writeln(\sprintf(' Comments were dropped by the rewrite, previous file kept at <info>%s</>', $backup));

    return 0;
}

#[AsTask(description: 'Install the servers and extensions Zed cannot fetch on its own')]
function install(#[AsOption(description: 'Print the commands without running them')] bool $dryRun = false): int
{
    foreach (Registry::allServers() as $server) {
        if (null === $server->install || $server->isInstalled()) {
            continue;
        }

        io()->section($server->id);
        io()->writeln(' ' . $server->install);

        if (!$dryRun) {
            run($server->install, context()->withAllowFailure());
        }
    }

    foreach (Registry::configFiles() as $config) {
        if ($config->isUpToDate()) {
            continue;
        }

        io()->section($config->path);

        if ($dryRun) {
            io()->writeln($config->content);

            continue;
        }

        fs()->dumpFile($config->absolutePath(), $config->content . "\n");
        io()->writeln(' <info>written</>');
    }

    foreach (Registry::devExtensions() as $extension) {
        io()->section($extension->id . ' (dev extension)');
        io()->writeln(\sprintf(' <comment>%s</>', $extension->reason));

        foreach ($extension->prerequisites as $prerequisite) {
            if ($prerequisite->isSatisfied()) {
                continue;
            }

            io()->writeln(\sprintf(' missing %s: %s', $prerequisite->label, $prerequisite->command));

            if (!$dryRun) {
                run($prerequisite->command, context()->withAllowFailure());
            }
        }

        if (!$dryRun) {
            if ($extension->isCloned()) {
                run('git pull --ff-only', context()->withAllowFailure()->withWorkingDirectory($extension->checkoutPath()));
            } else {
                fs()->mkdir(\dirname($extension->checkoutPath()));
                run(\sprintf('git clone --depth 1 %s %s', $extension->repository, $extension->checkoutPath()), context()->withAllowFailure());
            }
        }

        io()->writeln(' In Zed: run <info>zed: install dev extension</> and select:');
        io()->writeln(' <info>' . $extension->extensionPath() . '</>');
    }

    return 0;
}

#[AsTask('wrapper', namespace: 'lsp:symfony', description: 'Install the launcher Zed starts instead of symfony-lsp')]
function symfony_wrapper(): int
{
    $binary = Wrapper::serverBinary();

    if (null === $binary) {
        io()->error('symfony-lsp is not installed yet. Open a Symfony project in Zed once so the extension downloads it.');

        return 1;
    }

    $path = Wrapper::launcherPath();
    fs()->mkdir(\dirname($path));
    fs()->dumpFile($path, Wrapper::launcher(__DIR__ . '/castor.php') . "\n");
    fs()->chmod($path, 0o755);

    io()->success(\sprintf('Launcher written to %s', short_path($path)));
    io()->writeln(\sprintf(' It will exec <info>%s</>', short_path($binary)));
    io()->writeln(' Run `castor zed:generate` so Zed starts it instead of the server directly.');

    return 0;
}

#[AsTask('wrap', namespace: 'lsp:symfony', description: 'Internal: configure the project, then become symfony-lsp')]
function symfony_wrap(
    #[AsOption(description: 'Worktree Zed started the server in')] string $project = '',
): int {
    // Everything here talks to stderr: stdout belongs to the LSP stream.
    $project = '' !== $project ? $project : getcwd();

    try {
        $result = Wrapper::configure($project);
        fwrite(\STDERR, \sprintf("[sauron] %s: %s\n", $project, $result['reason']));
    } catch (\Throwable $e) {
        fwrite(\STDERR, \sprintf("[sauron] could not configure %s: %s\n", $project, $e->getMessage()));
    }

    $binary = Wrapper::serverBinary();

    if (null === $binary) {
        fwrite(\STDERR, "[sauron] symfony-lsp not found, cannot start the language server\n");

        return 1;
    }

    // Replaces this process, so Zed keeps talking to the same file descriptors.
    pcntl_exec($binary, \array_slice($_SERVER['argv'], \array_search('--', $_SERVER['argv'], true) ?: \count($_SERVER['argv'])));

    fwrite(\STDERR, \sprintf("[sauron] could not exec %s\n", $binary));

    return 1;
}

#[AsTask('memory', description: 'Show how much memory every running language server uses')]
function memory(
    #[AsOption(description: 'List every process instead of grouping by server')] bool $processes = false,
): int {
    $running = Scanner::scan();

    if ([] === $running) {
        io()->warning('No language server is running. Open a project in Zed first.');

        return 0;
    }

    if ($processes) {
        io()->table(['PID', 'Server', 'Memory', 'RSS', 'Worktree'], array_map(
            static fn (RunningProcess $p) => [
                $p->pid,
                $p->serverId ?? '<fg=yellow>unmanaged</>',
                mib($p->pssKb),
                mib($p->rssKb),
                short_path($p->workingDirectory ?? basename($p->executable)),
            ],
            $running,
        ));

        return report_total($running);
    }

    $grouped = [];
    foreach ($running as $process) {
        $key = $process->serverId ?? 'unmanaged:' . basename($process->executable);
        $grouped[$key] ??= ['pss' => 0, 'rss' => 0, 'count' => 0];
        $grouped[$key]['pss'] += $process->pssKb;
        $grouped[$key]['rss'] += $process->rssKb;
        ++$grouped[$key]['count'];
    }

    uasort($grouped, static fn (array $a, array $b) => $b['pss'] <=> $a['pss']);

    $rows = [];
    foreach ($grouped as $key => $totals) {
        $unmanaged = str_starts_with($key, 'unmanaged:');
        $server = $unmanaged ? null : Scanner::serverOf($key);

        $rows[] = [
            $unmanaged ? '<fg=yellow>' . substr($key, 10) . '</>' : $key,
            null === $server ? '<fg=yellow>not in manifest</>' : $server->runtime->label(),
            $totals['count'],
            mib($totals['pss']),
            mib($totals['rss']),
        ];
    }

    io()->table(['Server', 'Runtime', 'Instances', 'Memory', 'RSS'], $rows);

    return report_total($running);
}

/** @param list<RunningProcess> $running */
function report_total(array $running): int
{
    $pss = array_sum(array_map(static fn (RunningProcess $p) => $p->pssKb, $running));
    $rss = array_sum(array_map(static fn (RunningProcess $p) => $p->rssKb, $running));
    $unmanaged = array_filter($running, static fn (RunningProcess $p) => null === $p->serverId);

    io()->writeln(\sprintf(
        ' <info>%s</> across %d processes (%s counting shared pages once per process)',
        mib($pss),
        \count($running),
        mib($rss),
    ));
    io()->writeln(' Memory is PSS: pages shared between processes are split between them.');

    if ([] !== $unmanaged) {
        io()->writeln(\sprintf(
            ' <comment>%s in %d process(es) belong to servers the manifest does not declare.</>',
            mib(array_sum(array_map(static fn (RunningProcess $p) => $p->pssKb, $unmanaged))),
            \count($unmanaged),
        ));
    }

    return 0;
}

function mib(int $kb): string
{
    return $kb >= 1024 * 1024
        ? \sprintf('%.1f GiB', $kb / 1024 / 1024)
        : \sprintf('%d MiB', (int) round($kb / 1024));
}

function short_path(string $path): string
{
    return str_replace($_SERVER['HOME'], '~', $path);
}

#[AsTask(description: 'Check the manifest against the machine and the Zed registry')]
function doctor(): int
{
    $problems = 0;

    io()->section('Binaries');
    foreach (Registry::allServers() as $server) {
        if (null === $server->binary) {
            continue;
        }

        if ($server->isInstalled()) {
            io()->writeln(\sprintf(' <info>OK</>   %-24s %s', $server->id, $server->resolveBinary()));

            continue;
        }

        ++$problems;
        io()->writeln(\sprintf(' <fg=red>MISS</> %-24s run `castor install`', $server->id));
    }

    io()->section('Zed extensions');
    foreach (Registry::allServers() as $server) {
        if (null === $server->extension) {
            continue;
        }

        $known = extension_exists($server->extension);
        if (!$known) {
            ++$problems;
        }

        io()->writeln(\sprintf(
            ' %s %-24s extension `%s`',
            $known ? '<info>OK</>  ' : '<fg=red>GONE</>',
            $server->id,
            $server->extension,
        ));
    }

    io()->section('Dev extensions');
    foreach (Registry::devExtensions() as $extension) {
        foreach ($extension->prerequisites as $prerequisite) {
            $satisfied = $prerequisite->isSatisfied();

            if (!$satisfied) {
                ++$problems;
            }

            io()->writeln(\sprintf(
                ' %s %-24s %s',
                $satisfied ? '<info>OK</>  ' : '<fg=red>MISS</>',
                $extension->id,
                $prerequisite->label,
            ));
        }

        $loaded = is_dir($_SERVER['HOME'] . '/.local/share/zed/extensions/installed/' . $extension->id);

        if (!$loaded) {
            ++$problems;
        }

        io()->writeln(\sprintf(
            ' %s %-24s %s',
            $loaded ? '<info>OK</>  ' : '<fg=red>MISS</>',
            $extension->id,
            $loaded ? 'loaded by Zed' : 'not loaded: run `zed: install dev extension` on ' . $extension->extensionPath(),
        ));
    }

    io()->section('Launchers');
    $launcher = Wrapper::launcherPath();

    if (!is_file($launcher)) {
        ++$problems;
        io()->writeln(' <fg=red>MISS</> symfony-lsp              run `castor lsp:symfony:wrapper`');
    } elseif (file_get_contents($launcher) !== Wrapper::launcher(__DIR__ . '/castor.php') . "\n") {
        ++$problems;
        io()->writeln(' <fg=red>OLD</>  symfony-lsp              stale, run `castor lsp:symfony:wrapper` again');
    } else {
        io()->writeln(\sprintf(' <info>OK</>   symfony-lsp              %s', short_path($launcher)));
    }

    io()->section('Config files');
    foreach (Registry::configFiles() as $config) {
        io()->writeln(\sprintf(
            ' %s %s',
            $config->isUpToDate() ? '<info>OK</>  ' : '<comment>STALE</>',
            $config->path,
        ));
    }

    if ($problems > 0) {
        io()->warning(\sprintf('%d problem(s) found.', $problems));

        return 1;
    }

    io()->success('Everything checks out.');

    return 0;
}

function source(Server $server): string
{
    if (null !== $server->devExtension) {
        return 'dev extension';
    }

    return null === $server->extension ? 'Zed core' : $server->extension;
}

function truncate(string $value, int $length = 60): string
{
    return \strlen($value) > $length ? substr($value, 0, $length - 1) . '…' : $value;
}

/** Zed's registry is the only source of truth for extension slugs. */
function extension_exists(string $slug): bool
{
    static $cache = [];

    if (isset($cache[$slug])) {
        return $cache[$slug];
    }

    $body = @file_get_contents(\sprintf('https://api.zed.dev/extensions?filter=%s&max_schema_version=9', urlencode($slug)));

    if (false === $body) {
        return $cache[$slug] = true;
    }

    $data = json_decode($body, true)['data'] ?? [];

    return $cache[$slug] = [] !== array_filter($data, static fn (array $e) => ($e['id'] ?? null) === $slug);
}
