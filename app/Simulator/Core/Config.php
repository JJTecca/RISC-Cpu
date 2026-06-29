<?php

namespace App\Simulator\Core;

final class Config
{
    public function __construct(
        public bool $forwarding = true,
        public string $hazardMode = 'valid-bits',
        public bool $superscalar = false,
        public int $cacheLineSize = 16,
        public int $cacheWays = 2,
        public string $writePolicy = 'write-back',
        public string $replacement = 'lru',
        public bool $virtualMemory = false,
        public int $issueWidth = 4,   // 1.2 superscalar issue width
        public int $fetchWidth = 4,   // 1.2 superscalar fetch width
        public string $scheduler = 'inorder', // inorder | superscalar | scoreboard | tomasulo | ooo
    ) {}

    public function toArray(): array
    {
        return [
            'forwarding' => $this->forwarding,
            'hazardMode' => $this->hazardMode,
            'superscalar' => $this->superscalar,
            'cacheLineSize' => $this->cacheLineSize,
            'cacheWays' => $this->cacheWays,
            'writePolicy' => $this->writePolicy,
            'replacement' => $this->replacement,
            'virtualMemory' => $this->virtualMemory,
            'issueWidth' => $this->issueWidth,
            'fetchWidth' => $this->fetchWidth,
            'scheduler' => $this->scheduler,
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            forwarding: $a['forwarding'],
            hazardMode: $a['hazardMode'],
            superscalar: $a['superscalar'],
            cacheLineSize: $a['cacheLineSize'],
            cacheWays: $a['cacheWays'],
            writePolicy: $a['writePolicy'],
            replacement: $a['replacement'],
            virtualMemory: $a['virtualMemory'],
            issueWidth: $a['issueWidth'] ?? 4,
            fetchWidth: $a['fetchWidth'] ?? 4,
            scheduler: $a['scheduler'] ?? 'inorder',
        );
    }
}