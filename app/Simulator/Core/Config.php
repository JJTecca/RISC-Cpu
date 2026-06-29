<?php

namespace App\Simulator\Core;

final class Config
{
    public function __construct(
        public bool $forwarding = true,
        public string $hazardMode = 'valid-bits',
        public bool $superscalar = false,
        public bool $cache = false,        // 2.1 enable I/D caches
        public int $cacheSets = 4,
        public int $cacheLineSize = 1,
        public int $cacheWays = 2,
        public string $writePolicy = 'write-back',
        public string $replacement = 'lru', // lru | random | aprox
        public bool $writeAllocate = true,
        public int $scanInterval = 8,
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
            'cache' => $this->cache,
            'cacheSets' => $this->cacheSets,
            'cacheLineSize' => $this->cacheLineSize,
            'cacheWays' => $this->cacheWays,
            'writePolicy' => $this->writePolicy,
            'replacement' => $this->replacement,
            'writeAllocate' => $this->writeAllocate,
            'scanInterval' => $this->scanInterval,
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
            cache: $a['cache'] ?? false,
            cacheSets: $a['cacheSets'] ?? 4,
            cacheLineSize: $a['cacheLineSize'],
            cacheWays: $a['cacheWays'],
            writePolicy: $a['writePolicy'],
            replacement: $a['replacement'],
            writeAllocate: $a['writeAllocate'] ?? true,
            scanInterval: $a['scanInterval'] ?? 8,
            virtualMemory: $a['virtualMemory'],
            issueWidth: $a['issueWidth'] ?? 4,
            fetchWidth: $a['fetchWidth'] ?? 4,
            scheduler: $a['scheduler'] ?? 'inorder',
        );
    }
}