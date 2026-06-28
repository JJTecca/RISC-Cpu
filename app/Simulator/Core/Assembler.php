<?php

namespace App\Simulator\Core;

use App\Simulator\CpuState;

class Assembler
{
    /**
     * Create a new class instance.
     */
    private const CLASSES = [
        'ADD' => InstrClass::ALU,
        'SUB' => InstrClass::ALU,
        'MUL' => InstrClass::ALU,
        'AND' => InstrClass::ALU,
        'OR' => InstrClass::ALU,
        'LD' => InstrClass::LOAD,
        'LOAD' => InstrClass::LOAD,
        'ST' => InstrClass::STORE,
        'STORE' => InstrClass::STORE,
        'JMP' => InstrClass::JMP,
        'JZ' => InstrClass::JMP,
        'JNZ' => InstrClass::JMP,
    ];

    public function assemble(string $source, int $baseAddress = 0): array
    {
        $instructions = [];
        $address = $baseAddress;

        foreach (explode("\n", $source) as $line) {
            $line = trim($this->stripComment($line));

            if ($line === '') {
                continue;
            }

            $instructions[$address] = $this->parseLine($line, $address);
            $address += 4;
        }

        return $instructions;
    }

    public function loadInto(CpuState $state, string $source, int $baseAddress = 0): void
    {
        foreach ($this->assemble($source, $baseAddress) as $address => $instruction) {
            $state->memory->writeInstruction($address, $instruction);
        }

        $state->pc = $baseAddress;
    }

    private function stripComment(string $line): string
    {
        $position = strpos($line, ';');

        return $position === false ? $line : substr($line, 0, $position);
    }

    private function parseLine(string $line, int $address): Instruction
    {
        $parts = preg_split('/[\s,]+/', $line);
        $opcode = strtoupper(array_shift($parts));
        $class = self::CLASSES[$opcode] ?? InstrClass::ALU;

        $operands = array_map(fn (string $operand) => $this->parseOperand($operand), $parts);

        return match ($class) {
            InstrClass::ALU => new Instruction(
                class: $class,
                opcode: $opcode,
                dest: $operands[0] ?? null,
                src1: $operands[1] ?? null,
                src2: $operands[2] ?? null,
                address: $address,
                raw: $line,
            ),
            InstrClass::LOAD => new Instruction(
                class: $class,
                opcode: $opcode,
                dest: $operands[0] ?? null,
                src1: $operands[1] ?? null,
                immediate: $operands[2] ?? 0,
                address: $address,
                raw: $line,
            ),
            InstrClass::STORE => new Instruction(
                class: $class,
                opcode: $opcode,
                src1: $operands[0] ?? null,
                src2: $operands[1] ?? null,
                immediate: $operands[2] ?? 0,
                address: $address,
                raw: $line,
            ),
            InstrClass::JMP => new Instruction(
                class: $class,
                opcode: $opcode,
                immediate: $operands[0] ?? 0,
                address: $address,
                raw: $line,
            ),
        };
    }

    private function parseOperand(string $operand): int
    {
        $operand = trim($operand, "[]");

        if (str_starts_with(strtoupper($operand), 'R')) {
            return (int) substr($operand, 1);
        }

        if (str_ends_with(strtolower($operand), 'h')) {
            return (int) hexdec(substr($operand, 0, -1));
        }

        return (int) $operand;
    }
}
