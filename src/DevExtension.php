<?php

namespace Sauron;

/**
 * A Zed extension not published in the registry, so auto_install_extensions
 * cannot reach it. It has to be cloned locally and loaded through
 * `zed: install dev extension`.
 */
final class DevExtension
{
    /** @param list<Prerequisite> $prerequisites */
    public function __construct(
        public string $id,
        public string $repository,
        public string $path = '.',
        public string $reason = '',
        public array $prerequisites = [],
    ) {
    }

    public function checkoutPath(): string
    {
        return $_SERVER['HOME'] . '/.local/share/sauron-lsp/extensions/' . $this->id;
    }

    /** The directory to hand to `zed: install dev extension`. */
    public function extensionPath(): string
    {
        return '.' === $this->path ? $this->checkoutPath() : $this->checkoutPath() . '/' . $this->path;
    }

    public function isCloned(): bool
    {
        return is_dir($this->extensionPath());
    }
}
