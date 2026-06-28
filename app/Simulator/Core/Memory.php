<?php

namespace App\Simulator\Core;

final class Memory
{
    /** @var array<int,int> */
    public array $cells = [];

    /** @var array<int,Instruction> */
    public array $instructions = [];

    public function read(int $address): int
    {
        return $this->cells[$address] ?? 0;
    }

    public function write(int $address, int $value): void
    {
        $this->cells[$address] = $value;
    }

    public function readInstruction(int $address): ?Instruction
    {
        return $this->instructions[$address] ?? null;
    }

    public function writeInstruction(int $address, Instruction $instruction): void
    {
        $this->instructions[$address] = $instruction;
    }

    public function toArray(): array
    {
        $instructions = [];
        foreach ($this->instructions as $address => $instruction) {
            $instructions[$address] = $instruction->toArray();
        }

        return [
            'cells' => $this->cells,
            'instructions' => $instructions,
        ];
    }

    public static function fromArray(array $a): self
    {
        $memory = new self();
        $memory->cells = $a['cells'] ?? [];

        foreach ($a['instructions'] ?? [] as $address => $instruction) {
            $memory->instructions[(int) $address] = Instruction::fromArray($instruction);
        }

        return $memory;
    }
}