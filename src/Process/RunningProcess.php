<?php

namespace Sauron\Process;

final class RunningProcess
{
    public function __construct(
        public int $pid,
        public string $command,
        public string $executable,
        public int $rssKb,
        public int $pssKb,
        public ?string $workingDirectory,
        public ?string $serverId = null,
    ) {
    }
}
