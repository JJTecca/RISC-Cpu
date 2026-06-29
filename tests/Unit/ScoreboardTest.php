<?php

use App\Simulator\Core\Assembler;
use App\Simulator\Core\CpuState;
use App\Simulator\Core\ScoreboardClock;

// Extension 1.3 - scoreboard (tabela de marcaj).
// Place at: tests/Unit/ScoreboardTest.php
// Run with: php artisan test --group=simulator

function sb_run(string $source, array $mem = [], int $base = 0x100, int $maxClocks = 300): CpuState
{
    $state = new CpuState();
    $state->config->scheduler = 'scoreboard';
    (new Assembler())->loadInto($state, $source, $base);
    foreach ($mem as $addr => $val) {
        $state->memory->write($addr, $val);
    }

    $arr = $state->toArray();
    $guard = 0;
    while ($guard++ < $maxClocks) {
        $state = CpuState::fromArray($arr);
        if ($state->halted) {
            break;
        }
        $state = (new ScoreboardClock())->step($state);
        $arr = $state->toArray();
    }

    return CpuState::fromArray($arr);
}

it('runs the Curs 11 example, resolving RAW, WAR and WAW', function () {
    // MUL R5,R1,R2 must read R2 (=3) before ADD R2,R3,R4 overwrites it (WAR);
    // the two writes to R2 serialize (WAW); final R2 = (4+5) + (2*3) = 15.
    $state = sb_run("
        LD  R1, 0[R0]
        LD  R2, 4[R0]
        LD  R3, 8[R0]
        LD  R4, 12[R0]
        MUL R5, R1, R2
        ADD R2, R3, R4
        ADD R2, R2, R5
    ", [0 => 2, 4 => 3, 8 => 4, 12 => 5]);

    expect($state->halted)->toBeTrue()
        ->and($state->registers[1]->value)->toBe(2)
        ->and($state->registers[3]->value)->toBe(4)
        ->and($state->registers[4]->value)->toBe(5)
        ->and($state->registers[5]->value)->toBe(6)   // WAR respected
        ->and($state->registers[2]->value)->toBe(15); // WAW serialized
})->group('simulator');

it('populates the instruction-status table with all four stages', function () {
    $state = sb_run("
        LD  R1, 0[R0]
        MUL R5, R1, R1
    ", [0 => 3]);

    $log = $state->scoreboard['log'];
    $mul = $log[1];

    expect(count($log))->toBe(2)
        ->and($mul['IS'])->not->toBeNull()
        ->and($mul['RO'])->not->toBeNull()
        ->and($mul['EX'])->not->toBeNull()
        ->and($mul['WB'])->not->toBeNull()
        ->and($state->registers[5]->value)->toBe(9);
})->group('simulator');

it('executes independent instructions out of order across units', function () {
    // ADD R3 (fast) can finish before the long MUL R2 ahead of it.
    $state = sb_run("
        MUL R1, R0, R0
        ADD R3, R0, 7
    ");

    expect($state->registers[3]->value)->toBe(7)
        ->and($state->registers[1]->value)->toBe(0);
})->group('simulator');

it('runs a loop with branches under the scoreboard', function () {
    $state = sb_run("
        ADD R1, R0, 5
        ADD R2, R0, R0
loop:   ADD R2, R2, R1
        SUB R1, R1, 1
        BNE R1, R0, loop
        MUL R5, R2, R2
    ");

    expect($state->registers[2]->value)->toBe(15)
        ->and($state->registers[5]->value)->toBe(225);
})->group('simulator');