<?php

namespace App\Simulator\Core;

final class Instruction
{
    public function __construct(
        public InstrClass $class,
        public string $opcode,
        public ?int $dest = null,
        public ?int $src1 = null,
        public ?int $src2 = null,
        public ?int $immediate = null,
        public int $address = 0,
        public string $raw = '',
    ) {}

    public function toArray(): array
    {
        return [
            'class' => $this->class->value,
            'opcode' => $this->opcode,
            'dest' => $this->dest,
            'src1' => $this->src1,
            'src2' => $this->src2,
            'immediate' => $this->immediate,
            'address' => $this->address,
            'raw' => $this->raw,
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            class: InstrClass::from($a['class']),
            opcode: $a['opcode'],
            dest: $a['dest'],
            src1: $a['src1'],
            src2: $a['src2'],
            immediate: $a['immediate'],
            address: $a['address'],
            raw: $a['raw'],
        );
    }
}