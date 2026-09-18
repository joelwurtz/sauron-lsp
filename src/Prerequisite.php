<?php

namespace Sauron;

/**
 * Something that must exist before an extension can be built, but that lives
 * outside any package manager we drive.
 */
final class Prerequisite
{
    public function __construct(
        public string $label,
        public string $check,
        public string $command,
    ) {
    }

    public function isSatisfied(): bool
    {
        exec($this->check . ' > /dev/null 2>&1', $output, $status);

        return 0 === $status;
    }
}
