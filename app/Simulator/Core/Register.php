<?php

namespace App\Simulator\Core;

class Register
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        public int $value = 0,
        public bool $valid = true,
    ) {}

    public function toArray(): array
    {
        return ['value' => $this->value, 'valid' => $this->valid];
    }

    public static function fromArray(array $a): self
    {
        return new self(value: $a['value'], valid: $a['valid']);
    }
}
