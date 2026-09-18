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
castor symfony:config <path>   # build the .symfony-lsp.json a Docker project needs
```

`sauron:memory` reports PSS rather than RSS: several servers of the same kind
share pages, and RSS bills those pages to each of them. It also surfaces servers
running outside the manifest — the `"..."` fallback lets Zed start its own
defaults behind ours, which is how phpactor once held a gigabyte.

`zed:generate` only owns `auto_install_extensions`, `languages.*.language_servers`,
`lsp.*` and `code_lens`. Everything else in `settings.json` is merged through
untouched — but because Zed writes JSONC and PHP cannot re-emit comments, the
rewrite drops them. The previous file is always kept as `settings.json.bak.<date>`.

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
`~/.local/share/sauron-lsp/extensions/`, then you finish in Zed with
`zed: install dev extension` pointed at the `editor/zed/` directory.
`sauron:doctor` reports it as `MISS` until Zed has actually loaded it.

It also stays silent on purpose outside a full-stack Symfony application: it
discovers projects from their `composer.json` and provides no features when a
worktree has none.

When PHP runs in a container, it needs `phpCommand` and `containerProjectRoot`
in a `.symfony-lsp.json` at the project root. That file is per-project, so it
lives in the project rather than here, and `symfony:config` builds it: it finds
the Symfony applications the same way the server does, reads the compose files
to see which service can run PHP and where it mounts the tree, and prints the
result. Pass `--write` to save it.

Picking the service takes two signals, because neither is enough alone. The
image or build path leaf says which container has PHP at all — read from the
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
src/Zed/Jsonc.php      string-aware JSONC reader, because Zed's settings have comments
```

Adding a language means adding one entry to `Registry::languages()`.
`sauron:doctor` validates every extension slug against `api.zed.dev`, so a
renamed or unpublished extension shows up as `GONE` instead of silently doing
nothing.
