<?php

use App\Simulator\Core\Assembler;
use App\Simulator\Core\CpuState;
use App\Simulator\Core\OutOfOrderClock;

// Extension 1.5 - out-of-order issue + prefetch buffer.
// Place at: tests/Unit/OutOfOrderTest.php
// Run with: php artisan test --group=simulator

function ooo_run(string $source, int $base = 0x100, int $maxClocks = 400): CpuState
{
    $state = new CpuState();
    $state->config->scheduler = 'ooo';
    (new Assembler())->loadInto($state, $source, $base);

    $arr = $state->toArray();
    $guard = 0;
    while ($guard++ < $maxClocks) {
        $state = CpuState::fromArray($arr);
        if ($state->halted) {
            break;
        }
        $state = (new OutOfOrderClock())->step($state);
        $arr = $state->toArray();
    }

    return CpuState::fromArray($arr);
}

it('matches the in-order result on a full program', function () {
    $state = ooo_run("
        ADD R1, R0, 5
        ADD R2, R0, R0
loop:   ADD R2, R2, R1
        SUB R1, R1, 1
        BNE R1, R0, loop
        ST  0[R0], R2
        LD  R3, 0[R0]
        MUL R5, R2, R2
    ");

    expect($state->halted)->toBeTrue()
        ->and($state->registers[2]->value)->toBe(15)
        ->and($state->registers[3]->value)->toBe(15)
        ->and($state->registers[5]->value)->toBe(225);
})->group('simulator');

it('guards WAR: a later writer does not clobber a value an older read still needs', function () {
    $state = ooo_run("ADD R1,R0,5\nMUL R2,R1,R1\nADD R1,R0,9");

    expect($state->registers[1]->value)->toBe(9)
        ->and($state->registers[2]->value)->toBe(25); // MUL read R1=5, not 9
})->group('simulator');

it('issues a younger ready instruction before a stalled older one', function () {
    $state = new CpuState();
    $state->config->scheduler = 'ooo';
    (new Assembler())->loadInto($state, "MUL R1,R0,R0\nMUL R2,R1,R1\nADD R3,R0,7\nADD R4,R0,8", 0x100);

    $engine = new OutOfOrderClock();
    $guard = 0;
    while (! $state->halted && $guard++ < 200) {
        $state = $engine->step($state);
    }

    $order = array_map(fn ($l) => $l['raw'], $state->ooo['log']);
    $posAdd3 = array_search('ADD R3,R0,7', $order, true);
    $posMul2 = array_search('MUL R2,R1,R1', $order, true);

    expect($posAdd3)->not->toBeFalse()
        ->and($posMul2)->not->toBeFalse()
        ->and($posAdd3 < $posMul2)->toBeTrue()
        ->and($state->registers[3]->value)->toBe(7)
        ->and($state->registers[4]->value)->toBe(8);
})->group('simulator');

it('survives serialization round-trips between steps', function () {
    $state = ooo_run("ADD R1,R0,3\nMUL R2,R1,R1\nADD R3,R2,R1");

    expect($state->halted)->toBeTrue()
        ->and($state->registers[2]->value)->toBe(9)
        ->and($state->registers[3]->value)->toBe(12);
})->group('simulator');