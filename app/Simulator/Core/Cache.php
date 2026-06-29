<?php

namespace App\Simulator\Core;

// Group 2 - configurable cache (Curs 7). One class covers the whole group:
//   mapping:     direct (ways=1) or set-associative (ways>1)            [2.1 / 2.2]
//   replacement: 'random' / 'lru' (true recency) / 'aprox' (U-bit+ctr)  [2.2 / 2.4 / 2.5]
//   write:       'write-through' (+ write buffer) / 'write-back'        [2.3]
// Sits transparently between the CPU and Memory: data values stay correct;
// the cache adds hit/miss accounting and write-back behaviour. A data cache
// holds line data (for write-back); an instruction cache is metadata-only.
final class Cache
{
    public int $line;
    public int $sets;
    public int $ways;
    public string $repl;       // random | lru | aprox
    public string $wpolicy;    // write-through | write-back
    public bool $allocate;
    public int $scan;          // U-bit scan interval (aprox)
    public bool $holdsData;    // true for D-cache, false for I-cache

    /** @var array<int,array<int,int|null>> */ public array $tag = [];
    /** @var array<int,array<int,bool>> */ public array $valid = [];
    /** @var array<int,array<int,bool>> */ public array $dirty = [];
    /** @var array<int,array<int,array<int,int>>> */ public array $data = [];
    /** @var array<int,array<int,int>> */ public array $rec = [];
    /** @var array<int,array<int,int>> */ public array $ubit = [];
    /** @var array<int,array<int,int>> */ public array $ctr = [];

    public int $clock = 0;
    public int $accesses = 0;
    public int $hits = 0;
    public int $misses = 0;
    public int $writebacks = 0;
    public int $wbuf = 0;

    public static function make(int $line, int $sets, int $ways, string $repl, string $wpolicy, bool $allocate, int $scan, bool $holdsData): self
    {
        $c = new self();
        $c->line = max(1, $line);
        $c->sets = max(1, $sets);
        $c->ways = max(1, $ways);
        $c->repl = $repl;
        $c->wpolicy = $wpolicy;
        $c->allocate = $allocate;
        $c->scan = max(1, $scan);
        $c->holdsData = $holdsData;

        for ($i = 0; $i < $c->sets; $i++) {
            $c->tag[$i] = array_fill(0, $c->ways, null);
            $c->valid[$i] = array_fill(0, $c->ways, false);
            $c->dirty[$i] = array_fill(0, $c->ways, false);
            $c->rec[$i] = array_fill(0, $c->ways, 0);
            $c->ubit[$i] = array_fill(0, $c->ways, 0);
            $c->ctr[$i] = array_fill(0, $c->ways, 0);
            $c->data[$i] = array_fill(0, $c->ways, array_fill(0, $c->line, 0));
        }

        return $c;
    }

    /** @return array{0:int,1:int,2:int,3:int} [block, offset, index, tag] */
    private function parts(int $addr): array
    {
        $block = intdiv($addr, $this->line);
        $offset = $addr % $this->line;
        $index = $block % $this->sets;
        $tag = intdiv($block, $this->sets);

        return [$block, $offset, $index, $tag];
    }

    private function blockBase(int $index, int $tag): int
    {
        return ($tag * $this->sets + $index) * $this->line;
    }

    private function findWay(int $index, int $tag): ?int
    {
        for ($w = 0; $w < $this->ways; $w++) {
            if ($this->valid[$index][$w] && $this->tag[$index][$w] === $tag) {
                return $w;
            }
        }

        return null;
    }

    private function touch(int $index, int $w): void
    {
        $this->clock++;
        $this->rec[$index][$w] = $this->clock;
        $this->ubit[$index][$w] = 1;
    }

    private function maybeScan(): void
    {
        if ($this->repl !== 'aprox' || $this->accesses % $this->scan !== 0) {
            return;
        }
        for ($i = 0; $i < $this->sets; $i++) {
            for ($w = 0; $w < $this->ways; $w++) {
                if (! $this->valid[$i][$w]) {
                    continue;
                }
                if ($this->ubit[$i][$w] === 1) {
                    $this->ubit[$i][$w] = 0;
                    $this->ctr[$i][$w] = 0;
                } else {
                    $this->ctr[$i][$w]++;
                }
            }
        }
    }

    private function victim(int $index): int
    {
        for ($w = 0; $w < $this->ways; $w++) {
            if (! $this->valid[$index][$w]) {
                return $w; // fill empties first
            }
        }
        if ($this->repl === 'random') {
            return mt_rand(0, $this->ways - 1);
        }
        if ($this->repl === 'aprox') {
            $best = 0;
            for ($w = 1; $w < $this->ways; $w++) {
                if ($this->ctr[$index][$w] > $this->ctr[$index][$best]) {
                    $best = $w; // max counter = least recently used
                }
            }

            return $best;
        }
        // true LRU: smallest recency stamp
        $best = 0;
        for ($w = 1; $w < $this->ways; $w++) {
            if ($this->rec[$index][$w] < $this->rec[$index][$best]) {
                $best = $w;
            }
        }

        return $best;
    }

    private function evictAndFill(Memory $mem, int $index, int $tag, int $w): void
    {
        if ($this->holdsData && $this->valid[$index][$w] && $this->dirty[$index][$w] && $this->wpolicy === 'write-back') {
            $base = $this->blockBase($index, $this->tag[$index][$w]);
            for ($o = 0; $o < $this->line; $o++) {
                $mem->rawWrite($base + $o, $this->data[$index][$w][$o]);
            }
            $this->writebacks++;
        }

        $this->tag[$index][$w] = $tag;
        $this->valid[$index][$w] = true;
        $this->dirty[$index][$w] = false;
        $this->ubit[$index][$w] = 1;
        $this->ctr[$index][$w] = 0;

        if ($this->holdsData) {
            $base = $this->blockBase($index, $tag);
            for ($o = 0; $o < $this->line; $o++) {
                $this->data[$index][$w][$o] = $mem->rawRead($base + $o);
            }
        }
    }

    public function read(Memory $mem, int $addr): int
    {
        $this->accesses++;
        $this->maybeScan();
        [, $off, $idx, $tag] = $this->parts($addr);

        $w = $this->findWay($idx, $tag);
        if ($w === null) {
            $this->misses++;
            $w = $this->victim($idx);
            $this->evictAndFill($mem, $idx, $tag, $w);
        } else {
            $this->hits++;
        }
        $this->touch($idx, $w);

        return $this->holdsData ? $this->data[$idx][$w][$off] : $mem->rawRead($addr);
    }

    public function write(Memory $mem, int $addr, int $val): void
    {
        $this->accesses++;
        $this->maybeScan();
        [, $off, $idx, $tag] = $this->parts($addr);

        $w = $this->findWay($idx, $tag);
        if ($w === null) {
            $this->misses++;
            if (! $this->allocate) {
                $mem->rawWrite($addr, $val);
                if ($this->wpolicy === 'write-through') {
                    $this->wbuf++;
                }

                return;
            }
            $w = $this->victim($idx);
            $this->evictAndFill($mem, $idx, $tag, $w);
        } else {
            $this->hits++;
        }
        $this->touch($idx, $w);

        if ($this->holdsData) {
            $this->data[$idx][$w][$off] = $val;
        }
        if ($this->wpolicy === 'write-through') {
            $mem->rawWrite($addr, $val);
            $this->wbuf++;
        } else {
            $this->dirty[$idx][$w] = true; // write-back: memory updated on eviction
        }
    }

    // Instruction cache: residency/hit-miss only, no data, never dirty.
    public function fetch(int $addr): void
    {
        $this->accesses++;
        $this->maybeScan();
        [, , $idx, $tag] = $this->parts($addr);

        $w = $this->findWay($idx, $tag);
        if ($w === null) {
            $this->misses++;
            $w = $this->victim($idx);
            $this->tag[$idx][$w] = $tag;
            $this->valid[$idx][$w] = true;
            $this->ubit[$idx][$w] = 1;
            $this->ctr[$idx][$w] = 0;
        } else {
            $this->hits++;
        }
        $this->touch($idx, $w);
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $a): self
    {
        $c = new self();
        foreach ($a as $k => $v) {
            $c->$k = $v;
        }

        return $c;
    }
}