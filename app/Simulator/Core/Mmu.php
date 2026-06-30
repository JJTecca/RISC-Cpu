<?php

namespace App\Simulator\Core;

final class Mmu
{
    public int $pageSize = 16;
    public int $tlbEntries = 4;
    public int $virtualPages = 16;
    public int $physicalFrames = 8;
    public string $ptLoc = 'memory'; // 'memory' | 'cache'

    /** @var array<int,array{vpn:?int,frame:?int,valid:bool,rec:int}> */
    public array $tlb = [];
    /** @var array<int,array{present:bool,frame:?int}> */
    public array $pageTable = [];
    /** @var array<int,array{vpn:?int,rec:int}> */
    public array $frames = [];

    public int $clock = 0;
    public int $accesses = 0;
    public int $tlbHits = 0;
    public int $tlbMisses = 0;
    public int $pageFaults = 0;
    public int $tlbEvictions = 0;
    public int $pageEvictions = 0;

    public ?int $lastVaddr = null;
    public ?int $lastVpn = null;
    public ?int $lastOffset = null;
    public ?int $lastFrame = null;
    public ?int $lastPaddr = null;
    public ?int $lastCase = null;
    public ?string $lastLabel = null;

    public const LABELS = [
        1 => 'TLB hit',
        2 => 'TLB miss → tabelă în cache, pagină prezentă',
        3 => 'TLB miss → tabelă în memorie, pagină prezentă',
        4 => 'Page fault (tabelă în cache, cadru liber)',
        5 => 'Page fault (tabelă în memorie, cadru liber)',
        6 => 'Page fault cu înlocuire de pagină',
    ];

    public static function make(int $pageSize, int $tlbEntries, int $virtualPages, int $physicalFrames, string $ptLoc): self
    {
        $m = new self();
        $m->pageSize = max(1, $pageSize);
        $m->tlbEntries = max(1, $tlbEntries);
        $m->virtualPages = max(1, $virtualPages);
        $m->physicalFrames = max(1, $physicalFrames);
        $m->ptLoc = $ptLoc === 'cache' ? 'cache' : 'memory';

        for ($i = 0; $i < $m->tlbEntries; $i++) {
            $m->tlb[$i] = ['vpn' => null, 'frame' => null, 'valid' => false, 'rec' => 0];
        }
        for ($v = 0; $v < $m->virtualPages; $v++) {
            $m->pageTable[$v] = ['present' => false, 'frame' => null];
        }
        for ($f = 0; $f < $m->physicalFrames; $f++) {
            $m->frames[$f] = ['vpn' => null, 'rec' => 0];
        }

        return $m;
    }

    private function findTlb(int $vpn): int
    {
        for ($i = 0; $i < $this->tlbEntries; $i++) {
            if ($this->tlb[$i]['valid'] && $this->tlb[$i]['vpn'] === $vpn) {
                return $i;
            }
        }

        return -1;
    }

    private function freeTlb(): int
    {
        for ($i = 0; $i < $this->tlbEntries; $i++) {
            if (! $this->tlb[$i]['valid']) {
                return $i;
            }
        }

        return -1;
    }

    private function lruTlb(): int
    {
        $best = 0;
        for ($i = 1; $i < $this->tlbEntries; $i++) {
            if ($this->tlb[$i]['rec'] < $this->tlb[$best]['rec']) {
                $best = $i;
            }
        }

        return $best;
    }

    private function freeFrame(): int
    {
        for ($f = 0; $f < $this->physicalFrames; $f++) {
            if ($this->frames[$f]['vpn'] === null) {
                return $f;
            }
        }

        return -1;
    }

    private function lruFrame(): int
    {
        $best = 0;
        for ($f = 1; $f < $this->physicalFrames; $f++) {
            if ($this->frames[$f]['rec'] < $this->frames[$best]['rec']) {
                $best = $f;
            }
        }

        return $best;
    }

    private function loadTlb(int $vpn, int $frame): bool
    {
        $i = $this->freeTlb();
        $evicted = false;
        if ($i < 0) {
            $i = $this->lruTlb();
            $evicted = $this->tlb[$i]['valid'];
            if ($evicted) {
                $this->tlbEvictions++;
            }
        }
        $this->tlb[$i] = ['vpn' => $vpn, 'frame' => $frame, 'valid' => true, 'rec' => ++$this->clock];

        return $evicted;
    }

    public function translate(int $vaddr): int
    {
        $this->accesses++;
        $vpn = intdiv($vaddr, $this->pageSize);
        $off = $vaddr % $this->pageSize;
        $case = 0;

        $ti = $this->findTlb($vpn);
        if ($ti >= 0) {
            $this->tlbHits++;
            $frame = $this->tlb[$ti]['frame'];
            $this->tlb[$ti]['rec'] = ++$this->clock;
            $this->frames[$frame]['rec'] = $this->clock;
            $case = 1;
        } else {
            $this->tlbMisses++;
            $pte = $this->pageTable[$vpn] ?? ['present' => false, 'frame' => null];

            if ($pte['present']) {
                $frame = $pte['frame'];
                $this->frames[$frame]['rec'] = ++$this->clock;
                $this->loadTlb($vpn, $frame);
                $case = $this->ptLoc === 'cache' ? 2 : 3;
            } else {
                $this->pageFaults++;
                $f = $this->freeFrame();
                if ($f < 0) {
                    $f = $this->lruFrame();
                    $victim = $this->frames[$f]['vpn'];
                    if ($victim !== null) {
                        $this->pageTable[$victim] = ['present' => false, 'frame' => null];
                        $vi = $this->findTlb($victim);
                        if ($vi >= 0) {
                            $this->tlb[$vi]['valid'] = false;
                        }
                    }
                    $this->pageEvictions++;
                    $case = 6;
                } else {
                    $case = $this->ptLoc === 'cache' ? 4 : 5;
                }
                $this->frames[$f] = ['vpn' => $vpn, 'rec' => ++$this->clock];
                $this->pageTable[$vpn] = ['present' => true, 'frame' => $f];
                $frame = $f;
                $this->loadTlb($vpn, $frame);
            }
        }

        $paddr = $frame * $this->pageSize + $off;

        $this->lastVaddr = $vaddr;
        $this->lastVpn = $vpn;
        $this->lastOffset = $off;
        $this->lastFrame = $frame;
        $this->lastPaddr = $paddr;
        $this->lastCase = $case;
        $this->lastLabel = self::LABELS[$case] ?? null;

        return $paddr;
    }

    public function toArray(): array
    {
        $a = get_object_vars($this);
        $a['labels'] = self::LABELS;

        return $a;
    }

    public static function fromArray(array $a): self
    {
        $m = new self();
        foreach ($a as $k => $v) {
            if ($k === 'labels') {
                continue; 
            }
            $m->$k = $v;
        }

        return $m;
    }
}