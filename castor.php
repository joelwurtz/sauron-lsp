<?php

namespace Sauron;

use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
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

    // Zed settings are JSONC; re-encoding drops the comments, so keep the original around.
    $backup = $path . '.bak.' . date('YmdHis');
    fs()->copy($path, $backup);
    fs()->dumpFile($path, Jsonc::encode($merged));

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
        io()->writeln(\sprintf(
            ' %s %-24s %s',
            $extension->isCloned() ? '<info>OK</>  ' : '<comment>TODO</>',
            $extension->id,
            $extension->extensionPath(),
        ));
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
