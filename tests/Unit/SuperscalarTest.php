<?php

use App\Simulator\Core\Assembler;
use App\Simulator\Core\CpuState;
use App\Simulator\Core\SuperscalarClock;

// Extension 1.2 - specialized units + unit-level superscalarity.
// Place at: tests/Unit/SuperscalarTest.php
// Run with: php artisan test --group=simulator

// Run superscalar mode with a session round-trip between every step.
function ss_run(string $source, int $base = 0x100, int $maxClocks = 500): CpuState
{
    $state = new CpuState();
    $state->config->superscalar = true;
    (new Assembler())->loadInto($state, $source, $base);

    $arr = $state->toArray();
    $guard = 0;
    while ($guard++ < $maxClocks) {
        $state = CpuState::fromArray($arr);
        if ($state->halted) {
            break;
        }
        $state = (new SuperscalarClock())->step($state);
        $arr = $state->toArray();
    }

    return CpuState::fromArray($arr);
}

it('runs a full program in superscalar mode (matches single-issue result)', function () {
    $state = ss_run("
        ADD R1, R0, 5
        ADD R2, R0, R0
loop:   ADD R2, R2, R1
        SUB R1, R1, 1
        BNE R1, R0, loop
        ST  0[R0], R2
        LD  R3, 0[R0]
        MUL R5, R2, R2
        JMP done
        ADD R6, R0, 999
done:   ADD R7, R0, 42
    ");

    expect($state->halted)->toBeTrue()
        ->and($state->registers[2]->value)->toBe(15)
        ->and($state->registers[3]->value)->toBe(15)
        ->and($state->registers[5]->value)->toBe(225)
        ->and($state->registers[6]->value)->toBe(0)
        ->and($state->registers[7]->value)->toBe(42)
        ->and($state->memory->read(0))->toBe(15);
})->group('simulator');

it('co-issues independent instructions to different units in one cycle', function () {
    $state = new CpuState();
    $state->config->superscalar = true;
    (new Assembler())->loadInto($state, "ADD R10,R0,1\nMUL R11,R0,R0\nLD R12,0[R0]", 0x100);

    $engine = new SuperscalarClock();
    $threeBusy = false;
    for ($c = 0; $c < 6 && ! $state->halted; $c++) {
        $state = $engine->step($state);
        $u = $state->superscalar['units'];
        if ($u['ADD'] !== null && $u['MUL'] !== null && $u['LDST'] !== null) {
            $threeBusy = true;
        }
    }

    expect($threeBusy)->toBeTrue();
})->group('simulator');

it('serializes a WAW dependency through the busy bit', function () {
    $state = ss_run("MUL R1,R0,R0\nADD R1,R0,7");

    expect($state->registers[1]->value)->toBe(7);
})->group('simulator');

it('serializes a RAW dependency chain', function () {
    $state = ss_run("ADD R1,R0,1\nADD R2,R1,R1\nADD R3,R2,R2");

    expect($state->registers[1]->value)->toBe(1)
        ->and($state->registers[2]->value)->toBe(2)
        ->and($state->registers[3]->value)->toBe(4);
})->group('simulator');

it('survives serialization round-trips between steps', function () {
    // ss_run already round-trips every step; a clean halt proves the engine
    // state (units, queue, busy bits) survives toArray/fromArray.
    $state = ss_run("ADD R1,R0,2\nMUL R2,R1,R1\nADD R3,R2,R1");

    expect($state->halted)->toBeTrue()
        ->and($state->registers[3]->value)->toBe(6); // (2*2) + 2
})->group('simulator');