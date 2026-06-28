<?php

namespace App\Simulator\Core;

final class Pipeline
{
    public function __construct(
        public ?StageLatch $if = null,
        public ?StageLatch $of = null,
        public ?StageLatch $ex = null,
        public ?StageLatch $mem = null,
        public ?StageLatch $wb = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->if === null
            && $this->of === null
            && $this->ex === null
            && $this->mem === null
            && $this->wb === null;
    }

    public function toArray(): array
    {
        return [
            'if' => $this->if?->toArray(),
            'of' => $this->of?->toArray(),
            'ex' => $this->ex?->toArray(),
            'mem' => $this->mem?->toArray(),
            'wb' => $this->wb?->toArray(),
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            if: isset($a['if']) ? StageLatch::fromArray($a['if']) : null,
            of: isset($a['of']) ? StageLatch::fromArray($a['of']) : null,
            ex: isset($a['ex']) ? StageLatch::fromArray($a['ex']) : null,
            mem: isset($a['mem']) ? StageLatch::fromArray($a['mem']) : null,
            wb: isset($a['wb']) ? StageLatch::fromArray($a['wb']) : null,
        );
    }
}