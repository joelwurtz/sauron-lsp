# sauron-lsp

One manifest to rule my language servers.

For each language I use, this repo pins the language server I want, and generates
the matching Zed configuration. The manifest is the source of truth; Zed's
`settings.json` is a build artifact.

## How an editor talks to a language server

Not over the network. The editor spawns the server as a child process and speaks
JSON-RPC 2.0 over stdin/stdout, each message framed by a `Content-Length` header.
One process per (workspace root × server). Zed extensions are WebAssembly, but
they do not implement the protocol: they only tell Zed which binary to run.

This matters here because Zed refuses to start a language server it does not know
by name. `settings.json` can override the *binary* of a known server, never
declare a new one ([zed#52653](https://github.com/zed-industries/zed/issues/52653),
closed as not planned). So every server below is either built into Zed core,
provided by a registry extension, or loaded as a dev extension.

## Usage

```bash
castor sauron:servers          # what is pinned, and which servers still need a runtime
castor zed:generate --dry-run  # show what would change in ~/.config/zed/settings.json
castor zed:generate            # apply it (keeps a timestamped backup)
castor sauron:install          # install what Zed cannot fetch on its own
castor sauron:doctor           # check binaries, config files, and extension slugs
castor sauron:memory           # what every running server costs, --processes for detail
castor lsp:symfony:wrapper     # (re)install the launcher Zed starts instead of symfony-lsp
```

Servers Zed would otherwise start behind ours are listed in
`Registry::globallyDisabled()`, with the reason. Tailwind is the expensive one:
its Zed adapter attaches to PHP alongside every web language, so every PHP
project paid for a Node process whether or not it used Tailwind.

`sauron:memory` reports PSS rather than RSS: several servers of the same kind
share pages, and RSS bills those pages to each of them. It also surfaces servers
running outside the manifest — the `"..."` fallback lets Zed start its own
defaults behind ours, which is how phpactor once held a gigabyte.

`zed:generate` only owns `auto_install_extensions`, `languages.*.language_servers`,
`lsp.*` and `code_lens`. Everything else in `settings.json` is merged through
untouched — but because Zed writes JSONC and PHP cannot re-emit comments, the
rewrite drops them. The previous file is always kept as `settings.json.bak.<date>`.

## Zed on Windows, servers in WSL

When Zed runs on Windows and opens projects in WSL, the editor stays on Windows
but a `zed-remote-server` inside WSL spawns the language servers. Run every task
from WSL: binaries, launchers and config files belong there.

Settings then come from two files. The editor pushes its own
`%APPDATA%\Zed\settings.json` to every remote it opens, and the remote server
layers `~/.config/zed/settings.json` on top. `zed:generate` detects this setup
(`WSL_DISTRO_NAME`, plus a Zed settings file on Windows) and splits its output:

- `languages` and `lsp` go to the WSL file, created if missing. Pushed from
  Windows, their Linux paths would also reach SSH remotes, where they do not exist.
- `auto_install_extensions` and `code_lens` go to the Windows file: only the
  editor reads them.

Extensions are installed by the editor, then copied to
`~/.local/share/zed/remote_extensions/`, and what they download lands in
`remote_extensions/work/`. The tasks look there as well as in
`~/.local/share/zed/extensions/`.

The Symfony dev extension is built by the editor, on Windows: it needs
`wasm32-wasip2` in the Windows `rustup`, and its sources cannot sit in WSL —
building from `\\wsl.localhost\…` fails, cargo cannot lock its incremental
compilation directory on that filesystem. `sauron:install` clones it to
`%LOCALAPPDATA%\sauron-lsp\extensions\` instead, and prints the Windows path
to hand to `zed: install dev extension`.

## Selection rule

Prefer a compiled single binary — Rust, Go, Zig, C — over anything that boots a
Node runtime. `castor sauron:servers --node` lists the ones that still do, and
each carries a note saying why no native replacement was picked.

Four remain: `yaml-language-server` (kept for JSON-schema validation),
`twiggy-language-server` (the only maintained Twig server), `bash-language-server`,
and `symfony-language-tools` (PHP, but shipped as a self-contained binary).

## Notable choices

**Rust — `rust-glancer`, not `rust-analyzer`.** Two orders of magnitude less RAM,
at the cost of proc-macro and build-script expansion. rust-analyzer is explicitly
disabled: the two are not meant to run side by side.

**PHP — `phpantom`.** The `php` extension declares a `phpantom` server id, so the
old trick of pointing `lsp.intelephense.binary.path` at another binary is no
longer needed. Pinned to the `feat/include-paths` fork, which adds the
`indexing.include` option that `~/.config/phpantom_lsp/.phpantom.toml` uses to
index `.castor/` and `.castor.stub.php` — those live outside any Composer
autoload path, so the indexer has to be told about them.

**Symfony — `symfony-language-tools`.** The official LSP, running alongside
phpantom rather than replacing it, and also claiming Twig, YAML, JSON, XML and
JS/TS. It boots the application kernel, hence `workspaceTrust`.

It is the one server that cannot be installed hands-free: it is not in Zed's
registry, so `auto_install_extensions` has no reach. `sauron:install` adds the
`wasm32-wasip2` Rust target it builds against and clones it to
`~/.local/share/sauron-lsp/extensions/` (on Windows when Zed runs there, see
above), then you finish in Zed with `zed: install dev extension` pointed at the
`editor/zed/` directory.
`sauron:doctor` reports it as `MISS` until Zed has actually loaded it.

It also stays silent on purpose outside a full-stack Symfony application: it
discovers projects from their `composer.json` and provides no features when a
worktree has none.

When PHP runs in a container, the server needs `phpCommand` and
`containerProjectRoot` from a `.symfony-lsp.json` at the project root. Rather
than maintaining that file by hand in every repository, Zed starts a launcher
instead of the server: `lsp.symfony-language-tools.binary.path` points at
`~/.local/share/sauron-lsp/bin/symfony-lsp`, which writes the configuration for
whatever project it was started in and then becomes the real binary through
`pcntl_exec`, keeping Zed on the same file descriptors.

It works because Zed starts a language server in the worktree it serves, so the
launcher reads the project from its own `$PWD`. The file it writes is added to
`.git/info/exclude`, which is local to the checkout and shared by its worktrees:
the configuration is yours, not the team's. A file already tracked by git is
left alone.

Two things the launcher has to be careful about. Nothing may reach stdout, which
is the LSP stream, so every diagnostic goes to stderr prefixed with `[sauron]`.
That includes castor's own: since 1.8 it prints a deprecation on stdout unless
`CASTOR_USE_CHDIR` is defined, which is why `castor.php` defines it.
And castor runs from this repository rather than from the project: castor loads
the local plugins of the current directory even when `--castor-file` points
elsewhere, so running it inside a project that builds its stack with
`castor-php/docker` regenerates that project's compose files from definitions
that were never loaded, emptying them.

An application whose Composer dependencies are not installed cannot boot, and
the server would raise an error on every start. The launcher sets
`runtimeIndexing: false` for it, keeping source-only features; the next start
after `composer install` turns it back on. A vendor directory kept in a Docker
volume cannot be inspected from the host, so it is trusted.

The command is `docker compose run --rm --no-deps`, not `exec`. The server boots
the application to load routes and services, and `exec` needs the stack already
running — an editor opens a project long before that, and the bridge then fails
with `status 1`. A disposable container costs about 0.4s and works either way,
and `--no-deps` keeps it from starting the rest of the stack.

Picking the compose service takes two signals, because neither is enough alone.
The image or build path leaf says which container has PHP at all — read from the
leaf only, since paths routinely run through vendor directories like
`castor-php/`. Among those, a working directory holding a `composer.json` marks
the one that actually runs an application; workers, builders and test variants
are ranked below their base service. `docker compose config` would be more
faithful than reading the YAML, but it resolves variables from the environment
the stack is usually started with and returns nothing without them.

**TypeScript — `typescript-ls` from the `tsgo` extension.** The Go-native
TypeScript 7 compiler, with `biome` for lint and format. `vtsls`,
`typescript-language-server` and `eslint` are disabled.

## Layout

```
castor.php          tasks
src/Registry.php    the manifest — the only file worth editing day to day
src/Server.php      one language server: runtime, source, binary, config
src/Language.php    one Zed language: ordered servers, disabled servers
src/Zed/Generator.php  builds and merges the settings patch
src/Zed/Host.php       where Zed keeps settings and extensions: here, or on Windows
src/Zed/Jsonc.php      string-aware JSONC reader, because Zed's settings have comments
```

Adding a language means adding one entry to `Registry::languages()`.
`sauron:doctor` validates every extension slug against `api.zed.dev`, so a
renamed or unpublished extension shows up as `GONE` instead of silently doing
nothing.
