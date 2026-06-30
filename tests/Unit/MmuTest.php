<?php

use App\Simulator\Core\Assembler;
use App\Simulator\Core\Clock;
use App\Simulator\Core\CpuState;
use App\Simulator\Core\Memory;
use App\Simulator\Core\Mmu;


it('caz 1 — TLB hit la re-accesarea aceleiași pagini', function () {
    $m = Mmu::make(16, 4, 16, 8, 'memory');
    $m->translate(0);         
    $m->translate(1);          

    expect($m->lastCase)->toBe(1);
})->group('simulator');

it('caz 2 — TLB miss, tabelă în cache, pagină prezentă', function () {
    $m = Mmu::make(16, 1, 16, 8, 'cache'); 
    $m->translate(0);          
    $m->translate(16);        
    $m->translate(0);       

    expect($m->lastCase)->toBe(2);
})->group('simulator');

it('caz 3 — TLB miss, tabelă în memorie, pagină prezentă', function () {
    $m = Mmu::make(16, 1, 16, 8, 'memory');
    $m->translate(0);
    $m->translate(16);
    $m->translate(0);

    expect($m->lastCase)->toBe(3);
})->group('simulator');

it('caz 4 — page fault cu cadru liber, tabelă în cache', function () {
    $m = Mmu::make(16, 4, 16, 8, 'cache');
    $m->translate(0);         

    expect($m->lastCase)->toBe(4);
})->group('simulator');

it('caz 5 — page fault cu cadru liber, tabelă în memorie', function () {
    $m = Mmu::make(16, 4, 16, 8, 'memory');
    $m->translate(0);

    expect($m->lastCase)->toBe(5);
})->group('simulator');

it('caz 6 — page fault cu înlocuire de pagină (memorie plină)', function () {
    $m = Mmu::make(16, 2, 16, 2, 'memory'); 
    $m->translate(0);         
    $m->translate(16);       
    $m->translate(32);        

    expect($m->lastCase)->toBe(6)
        ->and($m->pageEvictions)->toBe(1);
})->group('simulator');

it('ține corect contoarele (hit/miss/fault)', function () {
    $m = Mmu::make(16, 4, 16, 8, 'memory');
    foreach ([0, 1, 16, 0] as $a) {   
        $m->translate($a);
    }

    expect($m->accesses)->toBe(4)
        ->and($m->pageFaults)->toBe(2)
        ->and($m->tlbHits)->toBe(2)
        ->and($m->tlbMisses)->toBe(2);
})->group('simulator');

it('este transparentă: datele rămân corecte după traducere', function () {
    $m = new Memory();
    $m->mmu = Mmu::make(16, 4, 16, 8, 'memory');
    $m->write(20, 99);                

    expect($m->read(20))->toBe(99)    
        ->and($m->mmu->lastPaddr)->toBe(4); 
})->group('simulator');

it('un program store→load dă același rezultat cu memorie virtuală activă', function () {
    $src = "ADD R1,R0,7\nST 0[R0],R1\nLD R2,0[R0]";
    $state = new CpuState();
    (new Assembler())->loadInto($state, $src, 0x100);
    $state->memory->mmu = Mmu::make(16, 4, 16, 8, 'memory');

    $arr = $state->toArray();
    $g = 0;
    while ($g++ < 200) {
        $state = CpuState::fromArray($arr);
        if ($state->halted) {
            break;
        }
        $state = (new Clock())->step($state);
        $arr = $state->toArray();
    }
    $state = CpuState::fromArray($arr);

    expect($state->registers[2]->value)->toBe(7)        
        ->and($state->memory->mmu->pageFaults)->toBeGreaterThan(0);
})->group('simulator');