<?php

namespace Sauron;

final class Server
{
    /**
     * @param string            $id           language server id as Zed knows it (not the extension slug)
     * @param string|null       $extension    Zed extension slug providing it, null when built into Zed
     * @param DevExtension|null $devExtension set when the extension is not in Zed's registry
     * @param string|null       $binary       executable to pin via lsp.<id>.binary.path, null lets Zed manage it
     * @param string|null       $install      shell command installing $binary, when Zed cannot fetch it itself
     * @param ConfigFile|null   $config       config the server reads from its own dotfile
     * @param list<string>          $arguments
     * @param array<string, mixed>  $initializationOptions
     * @param array<string, mixed>  $settings
     */
    public function __construct(
        public string $id,
        public Runtime $runtime,
        public ?string $extension = null,
        public ?DevExtension $devExtension = null,
        public ?string $binary = null,
        public ?string $install = null,
        public ?ConfigFile $config = null,
        public array $arguments = [],
        public array $initializationOptions = [],
        public array $settings = [],
        public array $env = [],
        public string $note = '',
    ) {
    }

    /** @return array<string, mixed> */
    public function lspSettings(): array
    {
        $entry = [];

        // Pinning a path we cannot resolve would stop Zed from fetching the
        // server itself, so an uninstalled binary is left unpinned on purpose.
        if ($this->isInstalled() && null !== $this->binary) {
            $entry['binary'] = array_filter([
                'path' => $this->resolveBinary(),
                'arguments' => $this->arguments,
                'env' => $this->env,
            ], static fn ($v) => [] !== $v && null !== $v);
        } elseif ([] !== $this->arguments || [] !== $this->env) {
            $entry['binary'] = array_filter([
                'arguments' => $this->arguments,
                'env' => $this->env,
            ], static fn ($v) => [] !== $v);
        }

        if ([] !== $this->initializationOptions) {
            $entry['initialization_options'] = $this->initializationOptions;
        }

        if ([] !== $this->settings) {
            $entry['settings'] = $this->settings;
        }

        return $entry;
    }

    public function resolveBinary(): ?string
    {
        if (null === $this->binary) {
            return null;
        }

        if (str_contains($this->binary, '/')) {
            return str_replace('~', $_SERVER['HOME'], $this->binary);
        }

        $found = trim((string) shell_exec(\sprintf('command -v %s 2>/dev/null', escapeshellarg($this->binary))));

        return '' === $found ? $this->binary : $found;
    }

    public function isInstalled(): bool
    {
        if (null === $this->binary) {
            return true;
        }

        $path = $this->resolveBinary();

        return null !== $path && is_executable($path);
    }
}
