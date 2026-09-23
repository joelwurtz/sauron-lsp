<?php

namespace Sauron;

use Sauron\Zed\Host;

/**
 * The manifest: one preferred language server per language.
 *
 * Rule of thumb: prefer a compiled single binary (Rust, Go, Zig, C) over anything
 * that boots a Node runtime. Servers still on Node carry a note saying why.
 */
final class Registry
{
    /**
     * Servers appended to every language.
     *
     * @return list<Server>
     */
    public static function globals(): array
    {
        return [
            new Server(
                id: 'typos',
                runtime: Runtime::Rust,
                extension: 'typos',
                process: 'typos-lsp',
                note: 'spell checker, all languages',
            ),
        ];
    }

    /**
     * Servers turned off on every language. Zed's "..." fallback starts its own
     * defaults behind ours, and some of them are expensive.
     *
     * @return array<string, string>
     */
    public static function globallyDisabled(): array
    {
        return [
            // Attaches to PHP, so every PHP project pays for a Node process,
            // Tailwind or not.
            'tailwindcss-language-server' => 'attaches to PHP and every web language',
            // Attaches to YAML from its own extension; docker-language-server
            // already covers Docker Compose, natively.
            'docker-compose' => 'superseded by docker-language-server',
        ];
    }

    /**
     * Extensions Zed should never install, keyed to why.
     *
     * @return array<string, string>
     */
    public static function unwanted(): array
    {
        return [
            'docker-compose' => 'its Node server duplicates docker-language-server',
        ];
    }

    /**
     * Top-level Zed settings required by servers below.
     *
     * @return array<string, mixed>
     */
    public static function extraSettings(): array
    {
        // Symfony Language Tools exposes reference counts on handlers, listeners
        // and Twig components through code lenses, which Zed hides by default.
        return ['code_lens' => 'on'];
    }

    /** @return list<Language> */
    public static function languages(): array
    {
        $tsgo = new Server(
            id: 'typescript-ls',
            runtime: Runtime::Go,
            extension: 'tsgo',
            process: 'tsgo',
            note: 'TypeScript 7 native compiler (Go), replaces the tsserver Node process',
        );

        $biome = new Server(
            id: 'biome',
            runtime: Runtime::Rust,
            extension: 'biome',
            note: 'lint + format for JS/TS/JSON/CSS, replaces eslint + prettier',
        );

        // Framework-aware, so it runs *alongside* the general-purpose server of
        // each language it claims rather than replacing it.
        $symfony = new Server(
            id: 'symfony-language-tools',
            runtime: Runtime::Php,
            devExtension: new DevExtension(
                id: 'symfony-language-tools',
                repository: 'https://github.com/symfony/language-tools.git',
                path: 'editor/zed',
                reason: 'not published in Zed\'s registry yet',
                // Zed builds dev extensions with the toolchain of the machine
                // it runs on, which is Windows when this is a WSL remote.
                prerequisites: [
                    Host::isWindowsEditor()
                        ? new Prerequisite(
                            label: 'Rust target wasm32-wasip2 (Windows)',
                            check: 'rustup.exe target list --installed | tr -d "\\r" | grep -qx wasm32-wasip2',
                            command: 'rustup.exe target add wasm32-wasip2',
                        )
                        : new Prerequisite(
                            label: 'Rust target wasm32-wasip2',
                            check: 'rustup target list --installed | grep -qx wasm32-wasip2',
                            command: 'rustup target add wasm32-wasip2',
                        ),
                ],
            ),
            // Zed starts a server in the worktree it serves, so the launcher can
            // write that project's Docker configuration before exec'ing the
            // real binary.
            binary: '~/.local/share/sauron-lsp/bin/symfony-lsp',
            install: 'castor lsp:symfony:wrapper',
            initializationOptions: [
                'workspaceTrust' => true,
            ],
            process: 'symfony-lsp',
            note: 'official Symfony LSP, self-contained binary; boots the kernel, so it only trusts workspaces you approve',
        );

        $phpantom = new Server(
            id: 'phpantom',
            runtime: Runtime::Rust,
            extension: 'php',
            binary: 'phpantom_lsp',
            install: 'cargo install --git https://github.com/joelwurtz/phpantom_lsp --branch feat/include-paths phpantom_lsp',
            config: new ConfigFile(
                path: '~/.config/phpantom_lsp/.phpantom.toml',
                // castor.php and .castor/ sit outside any Composer autoload path,
                // so the indexer has to be told about them explicitly.
                content: <<<'TOML'
                    [indexing]
                    include = [".castor", ".castor.stub.php"]
                    TOML,
            ),
            note: 'joelwurtz fork, branch feat/include-paths: adds indexing.include for castor files',
        );

        return [
            new Language('Rust', [
                new Server(
                    id: 'rust-glancer',
                    runtime: Runtime::Rust,
                    extension: 'rust-glancer',
                    note: 'two orders of magnitude less RAM than rust-analyzer; no proc-macro or build-script expansion',
                ),
            ], disabled: ['rust-analyzer']),

            new Language('PHP', [$phpantom, $symfony], disabled: ['phpactor', 'intelephense', 'phptools']),

            ...array_map(
                static fn (string $lang) => new Language($lang, [$tsgo, $biome], disabled: ['vtsls', 'typescript-language-server', 'eslint']),
                ['TSX', 'JSX'],
            ),
            new Language('TypeScript', [$tsgo, $biome, $symfony], disabled: ['vtsls', 'typescript-language-server', 'eslint']),
            new Language('JavaScript', [$tsgo, $biome, $symfony], disabled: ['vtsls', 'typescript-language-server', 'eslint']),

            new Language('JSON', [$biome, $symfony]),
            new Language('JSONC', [$biome]),
            new Language('XML', [$symfony]),

            new Language('TOML', [
                new Server(
                    id: 'tombi',
                    runtime: Runtime::Rust,
                    extension: 'tombi',
                    note: 'native TOML toolkit, ships its own grammar',
                ),
            ]),

            new Language('YAML', [
                new Server(
                    id: 'yaml-language-server',
                    runtime: Runtime::Node,
                    note: 'no native alternative yet; kept for its JSON-schema validation',
                ),
                $symfony,
            ]),

            new Language('Python', [
                new Server(
                    id: 'ty',
                    runtime: Runtime::Rust,
                    note: 'Astral type checker, built into Zed core; still beta',
                ),
                new Server(
                    id: 'ruff',
                    runtime: Runtime::Rust,
                    note: 'lint + format, built into Zed core',
                ),
            ], disabled: ['pyright', 'basedpyright', 'pylsp']),

            new Language('Go', [
                new Server(id: 'gopls', runtime: Runtime::Go, binary: 'gopls', install: 'go install golang.org/x/tools/gopls@latest'),
            ]),

            new Language('Nix', [
                new Server(id: 'nil', runtime: Runtime::Rust, extension: 'nix'),
            ], disabled: ['nixd']),

            new Language('C', [new Server(id: 'clangd', runtime: Runtime::C)]),
            new Language('C++', [new Server(id: 'clangd', runtime: Runtime::C)]),

            new Language('Zig', [
                new Server(id: 'zls', runtime: Runtime::Zig, extension: 'zig'),
            ]),

            new Language('Lua', [
                new Server(id: 'lua-language-server', runtime: Runtime::C, extension: 'lua'),
            ]),

            new Language('Markdown', [
                new Server(id: 'marksman', runtime: Runtime::Dotnet, extension: 'marksman', note: 'ahead-of-time compiled, single binary'),
            ]),

            new Language('Terraform', [
                new Server(id: 'terraform-ls', runtime: Runtime::Go, extension: 'terraform'),
            ]),

            new Language('Dockerfile', [
                new Server(id: 'docker-language-server', runtime: Runtime::Go, extension: 'dockerfile'),
            ], disabled: ['dockerfile-language-server']),

            new Language('Docker Compose', [
                new Server(id: 'docker-language-server', runtime: Runtime::Go, extension: 'dockerfile'),
            ], disabled: ['dockerfile-language-server']),

            new Language('HTML', [
                new Server(id: 'superhtml', runtime: Runtime::Zig, extension: 'superhtml'),
            ]),

            new Language('CSS', [$biome]),
            new Language('SCSS', [$biome]),

            new Language('Twig', [
                new Server(
                    id: 'twiggy-language-server',
                    runtime: Runtime::Node,
                    extension: 'twig',
                    note: 'no native Twig server exists; twiggy is the only maintained one',
                    settings: ['twiggy' => ['framework' => 'symfony']],
                ),
                $symfony,
            ]),

            new Language('Just', [
                new Server(id: 'just-lsp', runtime: Runtime::Rust, extension: 'just'),
            ]),

            new Language('Shell Script', [
                new Server(
                    id: 'bash-language-server',
                    runtime: Runtime::Node,
                    note: 'no native alternative; pairs with shellcheck which is the actual analyzer',
                ),
            ]),
        ];
    }

    /** @return list<Server> */
    public static function allServers(): array
    {
        $servers = self::globals();

        foreach (self::languages() as $language) {
            foreach ($language->servers as $server) {
                $servers[] = $server;
            }
        }

        $unique = [];
        foreach ($servers as $server) {
            $unique[$server->id] = $server;
        }

        return array_values($unique);
    }

    /** @return list<DevExtension> */
    public static function devExtensions(): array
    {
        $extensions = [];

        foreach (self::allServers() as $server) {
            if (null !== $server->devExtension) {
                $extensions[$server->devExtension->id] = $server->devExtension;
            }
        }

        return array_values($extensions);
    }

    /** @return list<ConfigFile> */
    public static function configFiles(): array
    {
        $files = [];

        foreach (self::allServers() as $server) {
            if (null !== $server->config) {
                $files[$server->config->path] = $server->config;
            }
        }

        return array_values($files);
    }
}
