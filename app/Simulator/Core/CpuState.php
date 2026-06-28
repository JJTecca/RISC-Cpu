<?php

namespace App\Simulator;

use App\Simulator\Core\Instruction;
use App\Simulator\Core\Memory;
use App\Simulator\Core\Pipeline;
use App\Simulator\Core\Register;
use PSpell\Config;

class CpuState
{
    /**
     * Create a new class instance.
     */
    public int $clock = 0;
    public int $pc = 0;
    public bool $halted = false;

    public ?int $mar = null;
    public ?int $mdr = null;
    public ?Instruction $ir = null;

    /** @var Register[] */
    public array $registers = [];

    public Memory $memory;
    public Pipeline $pipeline;
    public Config $config;

    public ?array $scoreboard = null;
    public ?array $tomasulo = null;
    public ?array $iCache = null;
    public ?array $dCache = null;
    public ?array $tlb = null;
    public ?array $pageTable = null;
    
    public function __construct(int $registerCount = 32)
    {
        for ($i = 0; $i < $registerCount; $i++) {
            $this->registers[$i] = new Register();
        }

        $this->memory = new Memory();
        $this->pipeline = new Pipeline();
        $this->config = new Config();
    }

    public static function fresh(int $registerCount = 32): self
    {
        return new self($registerCount);
    }

    public function toArray(): array
    {
        $registers = [];
        foreach ($this->registers as $index => $register) {
            $registers[$index] = $register->toArray();
        }

        return [
            'clock' => $this->clock,
            'pc' => $this->pc,
            'halted' => $this->halted,
            'mar' => $this->mar,
            'mdr' => $this->mdr,
            'ir' => $this->ir?->toArray(),
            'registers' => $registers,
            'memory' => $this->memory->toArray(),
            'pipeline' => $this->pipeline->toArray(),
            'config' => $this->config->toArray(),
            'scoreboard' => $this->scoreboard,
            'tomasulo' => $this->tomasulo,
            'iCache' => $this->iCache,
            'dCache' => $this->dCache,
            'tlb' => $this->tlb,
            'pageTable' => $this->pageTable,
        ];
    }

    public static function fromArray(array $a): self
    {
        $state = new self(count($a['registers']));

        $state->clock = $a['clock'];
        $state->pc = $a['pc'];
        $state->halted = $a['halted'];
        $state->mar = $a['mar'];
        $state->mdr = $a['mdr'];
        $state->ir = isset($a['ir']) ? Instruction::fromArray($a['ir']) : null;

        foreach ($a['registers'] as $index => $register) {
            $state->registers[(int) $index] = Register::fromArray($register);
        }

        $state->memory = Memory::fromArray($a['memory']);
        $state->pipeline = Pipeline::fromArray($a['pipeline']);
        $state->config = Config::fromArray($a['config']);

        $state->scoreboard = $a['scoreboard'] ?? null;
        $state->tomasulo = $a['tomasulo'] ?? null;
        $state->iCache = $a['iCache'] ?? null;
        $state->dCache = $a['dCache'] ?? null;
        $state->tlb = $a['tlb'] ?? null;
        $state->pageTable = $a['pageTable'] ?? null;

        return $state;
    }
}
