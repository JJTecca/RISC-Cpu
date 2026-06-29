<?php

use App\Simulator\Core\Assembler;
use App\Simulator\Core\Cache;
use App\Simulator\Core\Clock;
use App\Simulator\Core\CpuState;
use App\Simulator\Core\Memory;

// Group 2 - configurable I/D cache.
// Place at: tests/Unit/CacheTest.php
// Run with: php artisan test --group=simulator

it('takes conflict misses in a direct-mapped cache', function () {       // 2.1
    $m = new Memory();
    $c = Cache::make(1, 4, 1, 'lru', 'write-back', true, 8, true);
    foreach ([0, 4, 0, 4] as $a) {
        $c->read($m, $a); // 0 and 4 map to the same set -> evict each time
    }

    expect($c->misses)->toBe(4)->and($c->hits)->toBe(0);
})->group('simulator');

it('keeps both blocks in a 2-way set-associative cache', function () {    // 2.2
    $m = new Memory();
    $c = Cache::make(1, 4, 2, 'lru', 'write-back', true, 8, true);
    foreach ([0, 4, 0, 4] as $a) {
        $c->read($m, $a);
    }

    expect($c->hits)->toBe(2)->and($c->misses)->toBe(2);
})->group('simulator');

it('evicts under random replacement while keeping data correct', function () {   // 2.2
    mt_srand(7);
    $m = new Memory();
    foreach ([0, 1, 2] as $a) {
        $m->rawWrite($a, 100 + $a);
    }
    $c = Cache::make(1, 1, 2, 'random', 'write-back', true, 8, true); // 2 ways, random victim
    foreach ([0, 1, 2, 0, 1, 2, 0, 1, 2] as $a) {
        $c->read($m, $a); // 3 blocks contend for 2 ways -> random evictions
    }

    expect($c->misses)->toBeGreaterThan(3)          // evictions happened
        ->and($c->read($m, 0))->toBe(100)           // data still correct after eviction churn
        ->and($c->read($m, 2))->toBe(102);
})->group('simulator');

it('evicts the least-recently-used block under true LRU', function () {   // 2.4
    $m = new Memory();
    $c = Cache::make(1, 1, 2, 'lru', 'write-back', true, 8, true);
    foreach ([0, 1, 0, 2] as $a) {
        $c->read($m, $a); // block 1 is LRU, evicted when 2 loads
    }
    $before = $c->misses;
    $c->read($m, 1);

    expect($c->misses - $before)->toBe(1);
})->group('simulator');

it('writes a dirty block back to memory on eviction (write-back)', function () { // 2.3
    $m = new Memory();
    $c = Cache::make(1, 1, 1, 'lru', 'write-back', true, 8, true);
    $c->write($m, 0, 5);  // dirty in cache, memory still stale
    $c->write($m, 8, 9);  // evicts block 0 -> writeback

    expect($c->writebacks)->toBe(1)->and($m->rawRead(0))->toBe(5);
})->group('simulator');

it('uses a write buffer and stays clean under write-through', function () { // 2.3
    $m = new Memory();
    $c = Cache::make(1, 1, 1, 'lru', 'write-through', true, 8, true);
    $c->write($m, 0, 5);
    $c->write($m, 8, 9);

    expect($c->writebacks)->toBe(0)
        ->and($c->wbuf)->toBe(2)
        ->and($m->rawRead(0))->toBe(5);
})->group('simulator');

it('keeps the hot block under approximate LRU (U-bit + counter)', function () { // 2.5
    $m = new Memory();
    $c = Cache::make(1, 1, 2, 'aprox', 'write-back', true, 1, true);
    $c->read($m, 0);
    $c->read($m, 1);
    for ($i = 0; $i < 3; $i++) {
        $c->read($m, 0); // keep 0 hot; scans raise block 1's counter
    }
    $c->read($m, 2);     // miss -> evict cold block 1
    $hits = $c->hits;
    $c->read($m, 0);     // 0 still resident

    expect($c->hits)->toBe($hits + 1);
})->group('simulator');

it('is transparent: a program gives the same result with caches on', function () { // 2.1
    $src = "ADD R1,R0,5\nADD R2,R0,R0\nloop: ADD R2,R2,R1\nSUB R1,R1,1\nBNE R1,R0,loop\nST 0[R0],R2\nLD R3,0[R0]";
    $state = new CpuState();
    (new Assembler())->loadInto($state, $src, 0x100);
    $state->memory->dCache = Cache::make(1, 4, 2, 'lru', 'write-back', true, 8, true);
    $state->memory->iCache = Cache::make(1, 8, 2, 'lru', 'write-back', true, 8, false);

    $arr = $state->toArray();
    $g = 0;
    while ($g++ < 300) {
        $state = CpuState::fromArray($arr);
        if ($state->halted) {
            break;
        }
        $state = (new Clock())->step($state);
        $arr = $state->toArray();
    }
    $state = CpuState::fromArray($arr);

    expect($state->registers[2]->value)->toBe(15)            // same as no-cache
        ->and($state->registers[3]->value)->toBe(15)         // store -> load through D-cache
        ->and($state->memory->iCache->hits)->toBeGreaterThan(0); // loop refetches hit I-cache
})->group('simulator');