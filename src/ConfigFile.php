<?php

namespace Sauron;

/**
 * Config a server reads from its own dotfile rather than from Zed settings.
 */
final class ConfigFile
{
    public function __construct(
        public string $path,
        public string $content,
    ) {
    }

    public function absolutePath(): string
    {
        return str_replace('~', $_SERVER['HOME'], $this->path);
    }

    public function isUpToDate(): bool
    {
        $path = $this->absolutePath();

        return is_file($path) && trim(file_get_contents($path)) === trim($this->content);
    }
}
