<?php

namespace Sauron;

enum Runtime: string
{
    case Rust = 'rust';
    case Go = 'go';
    case Zig = 'zig';
    case C = 'c';
    case Dotnet = 'dotnet';
    case Node = 'node';
    case Php = 'php';

    /** Native = compiled, single binary, no interpreter to boot. */
    public function isNative(): bool
    {
        return self::Node !== $this && self::Php !== $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::Rust => 'Rust',
            self::Go => 'Go',
            self::Zig => 'Zig',
            self::C => 'C/C++',
            self::Dotnet => '.NET AOT',
            self::Node => 'Node',
            self::Php => 'PHP',
        };
    }
}
