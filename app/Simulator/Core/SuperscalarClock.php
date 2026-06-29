<?php

namespace App\Simulator\Core;

// Extension 1.2 - in-order superscalar engine with specialized units (Curs 12).
// Units: ADD (integer), MUL (multi-cycle), LDST (memory), JMP (control).
// Each cycle: retire finished units, then issue in program order to free units
// (at most one per unit), then fetch. RAW/WAW are guarded by the register busy
// bit (Register::valid). Used when Config::superscalar is on.
class SuperscalarClock
{
    private const LATENCY = ['ADD' => 1, 'MUL' => 3, 'LDST' => 2, 'JMP' => 1];

    public function step(CpuState $state): CpuState
    {
        if ($state->halted) {
            return $state;
        }

        $this->ensureState($state);
        $this->complete($state);
        $this->issue($state);
        $this->fetch($state);

        $ss = $state->superscalar;
        if ($this->unitsIdle($ss) && empty($ss['queue'])
            && $state->memory->readInstruction($state->pc) === null
            && ! $ss['branchWait']) {
            $state->halted = true;
        }

        $state->clock++;

        return $state;
    }

    private function ensureState(CpuState $state): void
    {
        if ($state->superscalar === null) {
            $state->superscalar = [
                'units' => ['ADD' => null, 'MUL' => null, 'LDST' => null, 'JMP' => null],
                'queue' => [],
                'branchWait' => false,
            ];
        }
    }

    private function unitOf(array $i): string
    {
        if ($i['class'] === 'ALU') {
            return strtoupper($i['opcode']) === 'MUL' ? 'MUL' : 'ADD';
        }
        if ($i['class'] === 'LOAD' || $i['class'] === 'STORE') {
            return 'LDST';
        }

        return 'JMP';
    }

    private function writes(array $i): bool
    {
        return $i['class'] === 'ALU' || $i['class'] === 'LOAD';
    }

    private function complete(CpuState $state): void
    {
        $ss = &$state->superscalar;

        foreach ($ss['units'] as &$occ) {
            if ($occ === null) {
                continue;
            }

            $occ['rem']--;
            if ($occ['rem'] > 0) {
                continue;
            }

            $i = $occ['ins'];

            if ($i['class'] === 'LOAD') {
                $occ['result'] = $state->memory->read($occ['address']);
            }
            if ($i['class'] === 'STORE') {
                $state->memory->write($occ['address'], $occ['data'] ?? 0);
            }
            if ($this->writes($i) && $i['dest'] !== null && $i['dest'] !== 0) {
                $state->registers[$i['dest']]->value = $occ['result'] ?? 0;
                $state->registers[$i['dest']]->valid = true; // clear busy
            }
            if ($i['class'] === 'JMP') {
                if ($occ['take']) {
                    $state->pc = $occ['target'];
                    $ss['queue'] = []; // discard fall-through instructions
                }
                $ss['branchWait'] = false;
            }

            $occ = null; // free the unit
        }
        unset($occ);
    }

    private function ready(CpuState $state, ?int $idx): bool
    {
        return $idx === null || $state->registers[$idx]->valid;
    }

    private function issue(CpuState $state): void
    {
        $ss = &$state->superscalar;
        if ($ss['branchWait']) {
            return; // don't issue past an unresolved branch
        }

        $width = max(1, $state->config->issueWidth);
        $issued = 0;

        while ($issued < $width && ! empty($ss['queue'])) {
            $i = $ss['queue'][0];
            $unit = $this->unitOf($i);

            if ($ss['units'][$unit] !== null) {
                break; // structural: unit busy
            }
            if (! $this->ready($state, $i['src1']) || ! $this->ready($state, $i['src2'])) {
                break; // RAW
            }
            if ($this->writes($i) && $i['dest'] !== null && $i['dest'] !== 0
                && ! $state->registers[$i['dest']]->valid) {
                break; // WAW
            }

            $a = $i['src1'] !== null ? $state->registers[$i['src1']]->value : 0;
            $b = $i['src2'] !== null ? $state->registers[$i['src2']]->value : ($i['immediate'] ?? 0);

            $occ = [
                'ins' => $i, 'rem' => self::LATENCY[$unit],
                'result' => null, 'address' => null, 'data' => null,
                'take' => false, 'target' => null,
            ];

            switch ($i['class']) {
                case 'ALU':
                    $occ['result'] = InstructionSet::computeAlu($i['opcode'], $a, $b);
                    break;
                case 'LOAD':
                    $occ['address'] = ($i['src1'] !== null ? $state->registers[$i['src1']]->value : 0) + ($i['immediate'] ?? 0);
                    break;
                case 'STORE':
                    $occ['address'] = ($i['src2'] !== null ? $state->registers[$i['src2']]->value : 0) + ($i['immediate'] ?? 0);
                    $occ['data'] = $a;
                    break;
                case 'JMP':
                    if (InstructionSet::isUnconditionalJump($i['opcode'])) {
                        $occ['take'] = true;
                        $occ['target'] = $i['src1'] !== null ? $a + ($i['immediate'] ?? 0) : ($i['immediate'] ?? $state->pc);
                    } else {
                        $occ['take'] = InstructionSet::branchTaken($i['opcode'], $a, $b);
                        $occ['target'] = $i['immediate'] ?? $state->pc;
                    }
                    break;
            }

            if ($this->writes($i) && $i['dest'] !== null && $i['dest'] !== 0) {
                $state->registers[$i['dest']]->valid = false; // set busy
            }

            $ss['units'][$unit] = $occ;
            array_shift($ss['queue']);
            $issued++;

            if ($i['class'] === 'JMP') {
                $ss['branchWait'] = true;
                break;
            }
        }
    }

    private function fetch(CpuState $state): void
    {
        $ss = &$state->superscalar;
        if ($ss['branchWait']) {
            return;
        }

        $width = max(1, $state->config->fetchWidth);
        for ($k = 0; $k < $width; $k++) {
            $instruction = $state->memory->readInstruction($state->pc);
            if ($instruction === null) {
                return;
            }
            $state->mar = $state->pc;
            $ss['queue'][] = $instruction->toArray();
            $state->pc += 4;
        }
    }

    private function unitsIdle(array $ss): bool
    {
        foreach ($ss['units'] as $occ) {
            if ($occ !== null) {
                return false;
            }
        }

        return true;
    }
}