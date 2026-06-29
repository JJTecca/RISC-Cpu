<?php

namespace App\Simulator\Core;

final class Memory
{
    /** @var array<int,int> */
    public array $cells = [];

    /** @var array<int,Instruction> */
    public array $instructions = [];

    // Group 2: optional caches. When set, accesses route through them (black box).
    public ?Cache $dCache = null;
    public ?Cache $iCache = null;

    // --- raw backing access (used by the caches; bypasses them) ---
    public function rawRead(int $address): int
    {
        return $this->cells[$address] ?? 0;
    }

    public function rawWrite(int $address, int $value): void
    {
        $this->cells[$address] = $value;
    }

    public function rawReadInstruction(int $address): ?Instruction
    {
        return $this->instructions[$address] ?? null;
    }

    // --- public access: transparent through the caches when present ---
    public function read(int $address): int
    {
        return $this->dCache !== null ? $this->dCache->read($this, $address) : $this->rawRead($address);
    }

    public function write(int $address, int $value): void
    {
        if ($this->dCache !== null) {
            $this->dCache->write($this, $address, $value);
        } else {
            $this->rawWrite($address, $value);
        }
    }

    public function readInstruction(int $address): ?Instruction
    {
        if ($this->iCache !== null && isset($this->instructions[$address])) {
            $this->iCache->fetch($address);
        }

        return $this->rawReadInstruction($address);
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
            'dCache' => $this->dCache?->toArray(),
            'iCache' => $this->iCache?->toArray(),
        ];
    }

    public static function fromArray(array $a): self
    {
        $memory = new self();
        $memory->cells = $a['cells'] ?? [];

        foreach ($a['instructions'] ?? [] as $address => $instruction) {
            $memory->instructions[(int) $address] = Instruction::fromArray($instruction);
        }

        $memory->dCache = isset($a['dCache']) && $a['dCache'] !== null ? Cache::fromArray($a['dCache']) : null;
        $memory->iCache = isset($a['iCache']) && $a['iCache'] !== null ? Cache::fromArray($a['iCache']) : null;

        return $memory;
    }
}