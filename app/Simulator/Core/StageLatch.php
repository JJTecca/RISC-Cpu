<?php

namespace App\Simulator\Core;

final class StageLatch
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        public Instruction $instruction,
        public ?int $operand1 = null,
        public ?int $operand2 = null,
        public ?int $result = null,
        public ?int $memoryAddress = null,
        public bool $stalled = false,
    ) {}

    public function toArray(): array
    {
        return [
            'instruction' => $this->instruction->toArray(),
            'operand1' => $this->operand1,
            'operand2' => $this->operand2,
            'result' => $this->result,
            'memoryAddress' => $this->memoryAddress,
            'stalled' => $this->stalled,
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            instruction: Instruction::fromArray($a['instruction']),
            operand1: $a['operand1'],
            operand2: $a['operand2'],
            result: $a['result'],
            memoryAddress: $a['memoryAddress'],
            stalled: $a['stalled'],
        );
    }
}
