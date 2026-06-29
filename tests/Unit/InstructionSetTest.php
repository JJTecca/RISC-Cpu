<?php

use App\Simulator\Core\Assembler;
use App\Simulator\Core\Clock;
use App\Simulator\Core\CpuState;
use App\Simulator\Core\InstrClass;

/**
 * Extension 1.1 — full course instruction set.
 * Place at: tests/Unit/InstructionSetTest.php
 * Run with: php artisan test --group=simulator
 */

/** Assemble, load at $base, and step the pipeline until it halts. */
function sim_run(string $source, int $base = 0x100, int $maxClocks = 500): CpuState
{
    $state = new CpuState();
    (new Assembler())->loadInto($state, $source, $base);

    $clock = new Clock();
    $guard = 0;
    while (! $state->halted && $guard++ < $maxClocks) {
        $clock->step($state);
    }

    return $state;
}

// ----------------------------------------------------------------------------
// Parsing — operand shapes from Curs 2
// ----------------------------------------------------------------------------

it('parses an R-R-R ALU instruction (grade-5 backward compat)', function () {
    $i = (new Assembler())->assemble('ADD R9,R8,R7', 0)[0];

    expect($i->class)->toBe(InstrClass::ALU)
        ->and($i->opcode)->toBe('ADD')
        ->and($i->dest)->toBe(9)
        ->and($i->src1)->toBe(8)
        ->and($i->src2)->toBe(7);
})->group('simulator');

it('parses an R-R-I ALU instruction (immediate, same mnemonic)', function () {
    $i = (new Assembler())->assemble('ADD R4,R5,64', 0)[0];

    expect($i->class)->toBe(InstrClass::ALU)
        ->and($i->dest)->toBe(4)
        ->and($i->src1)->toBe(5)
        ->and($i->src2)->toBeNull()
        ->and($i->immediate)->toBe(64);
})->group('simulator');

it('parses a load with offset[base] addressing', function () {
    $i = (new Assembler())->assemble('LD R1,200[R8]', 0)[0];

    expect($i->class)->toBe(InstrClass::LOAD)
        ->and($i->dest)->toBe(1)
        ->and($i->src1)->toBe(8)        // base register
        ->and($i->immediate)->toBe(200); // offset
})->group('simulator');

it('parses a store with the memory operand first', function () {
    $i = (new Assembler())->assemble('ST 16[R3],R4', 0)[0];

    expect($i->class)->toBe(InstrClass::STORE)
        ->and($i->src1)->toBe(4)        // data register
        ->and($i->src2)->toBe(3)        // base register
        ->and($i->immediate)->toBe(16); // offset
})->group('simulator');

it('parses a conditional branch and resolves the label', function () {
    $program = (new Assembler())->assemble("loop: ADD R1,R1,R1\n BEQ R1,R2,loop", 0x100);
    $branch = $program[0x104];

    expect($branch->class)->toBe(InstrClass::JMP)
        ->and($branch->src1)->toBe(1)
        ->and($branch->src2)->toBe(2)
        ->and($branch->immediate)->toBe(0x100);
})->group('simulator');

it('parses the three jump forms', function () {
    $asm = new Assembler();

    $direct = $asm->assemble('JMP 256', 0)[0];
    expect($direct->immediate)->toBe(256)->and($direct->src1)->toBeNull();

    $indirect = $asm->assemble('JMP [R7]', 0)[0];
    expect($indirect->src1)->toBe(7);

    $indexed = $asm->assemble('JMP 134[R2]', 0)[0];
    expect($indexed->src1)->toBe(2)->and($indexed->immediate)->toBe(134);
})->group('simulator');

it('parses a hex literal with trailing h', function () {
    $i = (new Assembler())->assemble('ADD R1,R0,1F4h', 0)[0];

    expect($i->immediate)->toBe(500);
})->group('simulator');

// ----------------------------------------------------------------------------
// Execution — through the 5-stage pipeline
// ----------------------------------------------------------------------------

it('executes every instruction class end to end', function () {
    $state = sim_run("
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
        ->and($state->registers[1]->value)->toBe(0)
        ->and($state->registers[2]->value)->toBe(15)   // 5+4+3+2+1
        ->and($state->registers[3]->value)->toBe(15)   // loaded back from MEM[0]
        ->and($state->registers[5]->value)->toBe(225)  // 15*15
        ->and($state->registers[6]->value)->toBe(0)    // skipped by JMP
        ->and($state->registers[7]->value)->toBe(42)
        ->and($state->memory->read(0))->toBe(15);
})->group('simulator');

it('takes BL and BGE branches correctly', function () {
    $state = sim_run("
        ADD R1, R0, 7
        ADD R2, R0, 3
        BL  R2, R1, less
        ADD R5, R0, 100
less:   ADD R5, R0, 1
        BGE R1, R2, ge
        ADD R6, R0, 100
ge:     ADD R6, R0, 2
    ");

    expect($state->registers[5]->value)->toBe(1)
        ->and($state->registers[6]->value)->toBe(2);
})->group('simulator');

it('keeps R0 hardwired to zero', function () {
    $state = sim_run("
        ADD R0, R0, 999
        ADD R1, R0, 5
    ");

    expect($state->registers[0]->value)->toBe(0)
        ->and($state->registers[1]->value)->toBe(5);
})->group('simulator');

it('forwards results to back-to-back dependent instructions', function () {
    $state = sim_run("
        ADD R1, R0, 10
        ADD R2, R1, R1
        ADD R3, R2, R1
    ");

    expect($state->registers[1]->value)->toBe(10)
        ->and($state->registers[2]->value)->toBe(20)
        ->and($state->registers[3]->value)->toBe(30);
})->group('simulator');

it('flushes the shadow instruction after a taken jump', function () {
    $state = sim_run("
        ADD R1, R0, 1
        JMP skip
        ADD R1, R0, 99
skip:   ADD R2, R0, 7
    ");

    expect($state->registers[1]->value)->toBe(1)
        ->and($state->registers[2]->value)->toBe(7);
})->group('simulator');