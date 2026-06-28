<?php

namespace App\Simulator\Core;


class Clock
{
    /**
     * Create a new class instance.
     */
    public function step(CpuState $state)
    {
        if ($state->halted) {
            return $state;
        }

        $this->writeBack($state);
        $this->memoryAccess($state);
        $this->execute($state);
        $this->operandFetch($state);
        $this->instructionFetch($state);

        $state->clock++;

        return $state;
    }

    private function writeBack(CpuState $state): void
    {
        $latch = $state->pipeline->wb;
        $state->pipeline->wb = null;

        if ($latch === null) {
            return;
        }

        $instruction = $latch->instruction;

        if ($this->writesRegister($instruction) && $instruction->dest !== null) {
            $state->registers[$instruction->dest]->value = $latch->result ?? 0;
            $state->registers[$instruction->dest]->valid = true;
        }
    }

    private function memoryAccess(CpuState $state): void
    {
        $latch = $state->pipeline->mem;
        $state->pipeline->mem = null;

        if ($latch === null) {
            return;
        }

        $instruction = $latch->instruction;

        if ($instruction->class === InstrClass::LOAD && $latch->memoryAddress !== null) {
            $latch->result = $state->memory->read($latch->memoryAddress);
        }

        if ($instruction->class === InstrClass::STORE && $latch->memoryAddress !== null) {
            $state->memory->write($latch->memoryAddress, $latch->operand1 ?? 0);
        }

        $state->pipeline->wb = $latch;
    }

    private function execute(CpuState $state): void
    {
        $latch = $state->pipeline->ex;
        $state->pipeline->ex = null;

        if ($latch === null) {
            return;
        }

        $instruction = $latch->instruction;

        switch ($instruction->class) {
            case InstrClass::ALU:
                $latch->result = $this->alu($instruction->opcode, $latch->operand1 ?? 0, $latch->operand2 ?? 0);
                break;
            case InstrClass::LOAD:
            case InstrClass::STORE:
                $latch->memoryAddress = ($latch->operand2 ?? 0) + ($instruction->immediate ?? 0);
                break;
            case InstrClass::JMP:
                $state->pc = $instruction->immediate ?? $state->pc;
                break;
        }

        $state->pipeline->mem = $latch;
    }

    private function tryForward(int $regIndex, CpuState $state): ?int
    {
        $exLatch = $state->pipeline->ex;
        if ($exLatch !== null && $exLatch->result !== null
            && $exLatch->instruction->dest === $regIndex
            && $this->writesRegister($exLatch->instruction)) {
            return $exLatch->result;
        }

        $memLatch = $state->pipeline->mem;
        if ($memLatch !== null && $memLatch->result !== null
            && $memLatch->instruction->dest === $regIndex
            && $this->writesRegister($memLatch->instruction)) {
            return $memLatch->result;
        }

        return null;
    }

    private function operandFetch(CpuState $state)
    {
        $latch = $state->pipeline->of;
        if ($latch === null) return;

        $instruction = $latch->instruction;

        $forwardedSrc1 = $instruction->src1 !== null ? $this->tryForward($instruction->src1, $state) : null;
        $forwardedSrc2 = $instruction->src2 !== null ? $this->tryForward($instruction->src2, $state) : null;

        $hazard = false;
        if ($instruction->src1 !== null && $forwardedSrc1 === null && !$state->registers[$instruction->src1]->valid) {
            $hazard = true;
        }
        if ($instruction->src2 !== null && $forwardedSrc2 === null && !$state->registers[$instruction->src2]->valid) {
            $hazard = true;
        }

        if ($hazard) {
            $latch->stalled = true;
            return; // leave latch in OF, freeze
        }

        $state->pipeline->of = null;
        $latch->stalled = false;

        if ($instruction->src1 !== null) {
            $latch->operand1 = $forwardedSrc1 ?? $state->registers[$instruction->src1]->value;
        }
        if ($instruction->src2 !== null) {
            $latch->operand2 = $forwardedSrc2 ?? $state->registers[$instruction->src2]->value;
        }

        if ($this->writesRegister($instruction) && $instruction->dest !== null) {
            $state->registers[$instruction->dest]->valid = false;
        }

        $state->pipeline->ex = $latch;
    }

    private function instructionFetch(CpuState $state): void
    {
        if ($state->pipeline->of !== null && $state->pipeline->of->stalled) {
            return; // freeze front-end
        }

        $latch = $state->pipeline->if;
        $state->pipeline->if = null;

        if ($latch !== null) {
            $state->pipeline->of = $latch;
        }

        $state->mar = $state->pc;
        $instruction = $state->memory->readInstruction($state->pc);

        if ($instruction === null) {
            $state->ir = null;
            if ($this->pipelineDrained($state)) {
                $state->halted = true;
            }
            return;
        }

        $state->ir = $instruction;
        $state->pipeline->if = new StageLatch(instruction: $instruction);
        $state->pc += 4;
    }

    private function alu(string $opcode, int $a, int $b): int
    {
        return match ($opcode) {
            'ADD' => $a + $b,
            'SUB' => $a - $b,
            'MUL' => $a * $b,
            'AND' => $a & $b,
            'OR' => $a | $b,
            default => 0,
        };
    }

    private function writesRegister(Instruction $instruction): bool
    {
        return $instruction->class === InstrClass::ALU
            || $instruction->class === InstrClass::LOAD;
    }

    private function pipelineDrained(CpuState $state): bool
    {
        return $state->pipeline->of === null
            && $state->pipeline->ex === null
            && $state->pipeline->mem === null
            && $state->pipeline->wb === null;
    }
}
