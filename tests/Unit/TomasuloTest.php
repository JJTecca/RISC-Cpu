<?php

use App\Simulator\Core\Assembler;
use App\Simulator\Core\CpuState;
use App\Simulator\Core\TomasuloClock;

// Extension 1.4 - Tomasulo's algorithm (reservation stations + register renaming).
// Place at: tests/Unit/TomasuloTest.php
// Run with: php artisan test --group=simulator

function tm_run(string $source, int $base = 0x100, int $maxClocks = 300): CpuState
{
    $state = new CpuState();
    $state->config->scheduler = 'tomasulo';
    (new Assembler())->loadInto($state, $source, $base);

    $arr = $state->toArray();
    $guard = 0;
    while ($guard++ < $maxClocks) {
        $state = CpuState::fromArray($arr);
        if ($state->halted) {
            break;
        }
        $state = (new TomasuloClock())->step($state);
        $arr = $state->toArray();
    }

    return CpuState::fromArray($arr);
}

it('runs the Curs 12 example, renaming away the first write to R2', function () {
    // i1 ADD R2,R3,R4 then i2 ADD R2,R2,R1: RAW (i2 needs i1 via the CDB) and
    // WAW on R2 (i1's result never commits - R2's tag is rewritten to i2).
    $state = tm_run("
        ADD R3, R0, 10
        ADD R4, R0, 20
        ADD R1, R0, 5
        ADD R2, R3, R4
        ADD R2, R2, R1
    ");

    expect($state->halted)->toBeTrue()
        ->and($state->registers[2]->value)->toBe(35)
        ->and($state->registers[3]->value)->toBe(10)
        ->and($state->registers[1]->value)->toBe(5);
})->group('simulator');

it('eliminates WAW with renaming (no stall, last writer wins)', function () {
    $state = tm_run("MUL R1,R0,R0\nADD R1,R0,7");

    expect($state->registers[1]->value)->toBe(7);
})->group('simulator');

it('forwards RAW dependencies through the common data bus', function () {
    $state = tm_run("ADD R1,R0,2\nADD R2,R1,R1\nADD R3,R2,R1");

    expect($state->registers[1]->value)->toBe(2)
        ->and($state->registers[2]->value)->toBe(4)
        ->and($state->registers[3]->value)->toBe(6);
})->group('simulator');

it('runs a loop with memory and branches under Tomasulo', function () {
    $state = tm_run("
        ADD R1, R0, 5
        ADD R2, R0, R0
loop:   ADD R2, R2, R1
        SUB R1, R1, 1
        BNE R1, R0, loop
        ST  0[R0], R2
        LD  R3, 0[R0]
        MUL R5, R2, R2
    ");

    expect($state->registers[2]->value)->toBe(15)
        ->and($state->registers[3]->value)->toBe(15)
        ->and($state->registers[5]->value)->toBe(225);
})->group('simulator');

it('records reservation-station issue/exec/write in the log', function () {
    $state = tm_run("ADD R3,R0,4\nMUL R5,R3,R3");
    $mul = $state->tomasulo['log'][1];

    expect($mul['IS'])->not->toBeNull()
        ->and($mul['EX'])->not->toBeNull()
        ->and($mul['WB'])->not->toBeNull()
        ->and($state->registers[5]->value)->toBe(16);
})->group('simulator');