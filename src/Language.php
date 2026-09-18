<?php

namespace Sauron;

final class Language
{
    /**
     * @param string        $zedName  language name exactly as Zed spells it
     * @param list<Server>  $servers  ordered by priority, first one wins per capability
     * @param list<string>  $disabled server ids to explicitly turn off (rendered as "!id")
     * @param bool          $fallback append "..." so Zed keeps its defaults behind ours
     * @param array<string, mixed> $settings extra per-language Zed settings
     */
    public function __construct(
        public string $zedName,
        public array $servers,
        public array $disabled = [],
        public bool $fallback = true,
        public array $settings = [],
    ) {
    }

    /**
     * @param list<Server> $globals
     *
     * @return list<string>
     */
    public function languageServers(array $globals = []): array
    {
        $ids = array_map(static fn (Server $s) => $s->id, [...$this->servers, ...$globals]);
        $ids = [...$ids, ...array_map(static fn (string $id) => '!' . $id, $this->disabled)];

        if ($this->fallback) {
            $ids[] = '...';
        }

        return array_values(array_unique($ids));
    }
}
