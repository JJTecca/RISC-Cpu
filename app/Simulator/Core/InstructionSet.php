<?php

namespace App\Simulator\Core;

// Extension 1.1 - the RISC instruction set from Curs 2, in one place.
// Both the assembler (operand shapes) and the clock (execution) read from here.
// Operand shapes: ALU3=Rd,Rs1,(Rs2|const)  LOAD=Rd,off[base]  STORE=off[base],Rs
//                 BRANCH=Ri,Rj,target       JMP=target | [base] | off[base]
final class InstructionSet
{
    /** @return array<string, array{0: InstrClass, 1: string}> opcode => [class, shape] */
    public static function definitions(): array
    {
        return [
            'ADD' => [InstrClass::ALU, 'ALU3'],
            'SUB' => [InstrClass::ALU, 'ALU3'],
            'MUL' => [InstrClass::ALU, 'ALU3'],
            'AND' => [InstrClass::ALU, 'ALU3'],
            'OR'  => [InstrClass::ALU, 'ALU3'],
            'XOR' => [InstrClass::ALU, 'ALU3'],
            'SLL' => [InstrClass::ALU, 'ALU3'],
            'SRL' => [InstrClass::ALU, 'ALU3'],

            'LD' => [InstrClass::LOAD,  'LOAD'],
            'ST' => [InstrClass::STORE, 'STORE'],
            'LW' => [InstrClass::LOAD,  'LOAD'],   // alias of LD
            'SW' => [InstrClass::STORE, 'STORE'],  // alias of ST

            'BEQ' => [InstrClass::JMP, 'BRANCH'],
            'BNE' => [InstrClass::JMP, 'BRANCH'],
            'BL'  => [InstrClass::JMP, 'BRANCH'],
            'BGE' => [InstrClass::JMP, 'BRANCH'],

            'JMP' => [InstrClass::JMP, 'JMP'],
            'J'   => [InstrClass::JMP, 'JMP'],     // alias of JMP
        ];
    }

    /** @return array{0: InstrClass, 1: string}|null */
    public static function lookup(string $opcode): ?array
    {
        return self::definitions()[strtoupper($opcode)] ?? null;
    }

    public static function classFor(string $opcode): InstrClass
    {
        return self::lookup($opcode)[0] ?? InstrClass::ALU;
    }

    public static function shapeFor(string $opcode): string
    {
        return self::lookup($opcode)[1] ?? 'ALU3';
    }

    public static function isKnown(string $opcode): bool
    {
        return self::lookup($opcode) !== null;
    }

    // $b is the second register value (R-R-R) or the immediate (R-R-I).
    public static function computeAlu(string $opcode, int $a, int $b): int
    {
        return match (strtoupper($opcode)) {
            'ADD' => $a + $b,
            'SUB' => $a - $b,
            'MUL' => $a * $b,
            'AND' => $a & $b,
            'OR'  => $a | $b,
            'XOR' => $a ^ $b,
            'SLL' => $a << ($b & 31),
            'SRL' => $a >> ($b & 31),
            default => 0,
        };
    }

    public static function branchTaken(string $opcode, int $a, int $b): bool
    {
        return match (strtoupper($opcode)) {
            'BEQ' => $a === $b,
            'BNE' => $a !== $b,
            'BL'  => $a < $b,
            'BGE' => $a >= $b,
            default => false,
        };
    }

    public static function isUnconditionalJump(string $opcode): bool
    {
        return in_array(strtoupper($opcode), ['JMP', 'J'], true);
    }

    // Reference data for the UI panel (single source of truth for the cheatsheet).
    /** @return list<array{opcode:string,class:string,syntax:string,effect:string}> */
    public static function describe(): array
    {
        $syntax = [
            'ALU3'   => 'OP Rd, Rs1, Rs2|const',
            'LOAD'   => 'OP Rd, off[Rb]',
            'STORE'  => 'OP off[Rb], Rs',
            'BRANCH' => 'OP Ri, Rj, target',
            'JMP'    => 'OP target | [Rb] | off[Rb]',
        ];
        $effect = [
            'ALU3'   => 'Rd = Rs1 op (Rs2 or const)',
            'LOAD'   => 'Rd = MEM[Rb + off]',
            'STORE'  => 'MEM[Rb + off] = Rs',
            'BRANCH' => 'branch to target if condition holds',
            'JMP'    => 'unconditional jump',
        ];

        $rows = [];
        foreach (self::definitions() as $opcode => [$class, $shape]) {
            $rows[] = [
                'opcode' => $opcode,
                'class'  => $class->value,
                'syntax' => str_replace('OP', $opcode, $syntax[$shape] ?? 'OP'),
                'effect' => $effect[$shape] ?? '',
            ];
        }

        return $rows;
    }
}