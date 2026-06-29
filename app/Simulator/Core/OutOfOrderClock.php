<?php

namespace App\Simulator\Core;

// Extension 1.5 - out-of-order issue + prefetch buffer (Curs 12 instruction window).
// A prefetch buffer (the window) is filled ahead of execution; each cycle any
// READY instruction may be launched to a free unit, bypassing a stalled older
// one (out-of-order issue - the optimization over the in-order modes 1.2-1.4).
// RAW/WAW/WAR are guarded by scanning older not-done window entries; completion
// is out of order; branches issue in program order (no speculation past them).
// State lives in $state->ooo. Used when scheduler='ooo'.
class OutOfOrderClock
{
    private const LATENCY = ['ADD' => 1, 'MUL' => 3, 'LDST' => 2, 'JMP' => 1];
    private const UNITS = ['ADD', 'MUL', 'LDST', 'JMP'];

    public function step(CpuState $state): CpuState
    {
        if ($state->halted) {
            return $state;
        }

        $this->ensureState($state);
        $this->complete($state);
        $this->retire($state);
        $this->issue($state);
        $this->fetch($state);

        $o = $state->ooo;
        if (empty($o['win']) && $state->memory->readInstruction($state->pc) === null && ! $o['branchWait']) {
            $state->halted = true;
        }

        $state->clock++;

        return $state;
    }

    private function ensureState(CpuState $state): void
    {
        if ($state->ooo === null) {
            $state->ooo = [
                'win' => [],
                'unit' => array_fill_keys(self::UNITS, false),
                'branchWait' => false,
                'windowSize' => 8,
                'log' => [],
            ];
        }
    }

    private function unitType(array $i): string
    {
        if ($i['class'] === 'LOAD' || $i['class'] === 'STORE') {
            return 'LDST';
        }
        if ($i['class'] === 'ALU') {
            return strtoupper($i['opcode']) === 'MUL' ? 'MUL' : 'ADD';
        }

        return 'JMP';
    }

    private function writes(array $i): bool
    {
        return $i['class'] === 'ALU' || $i['class'] === 'LOAD';
    }

    private function destReg(array $i): ?int
    {
        return ($this->writes($i) && $i['dest'] !== null && $i['dest'] !== 0) ? $i['dest'] : null;
    }

    /** @return list<int> */
    private function srcRegs(array $i): array
    {
        return array_values(array_filter([$i['src1'], $i['src2']], fn ($r) => $r !== null));
    }

    private function fetch(CpuState $state): void
    {
        $o = &$state->ooo;
        if ($o['branchWait']) {
            return;
        }
        while (count($o['win']) < $o['windowSize']) {
            $instruction = $state->memory->readInstruction($state->pc);
            if ($instruction === null) {
                return;
            }
            $i = $instruction->toArray();
            $state->mar = $state->pc;
            $o['win'][] = [
                'ins' => $i, 'st' => 'wait', 'unit' => $this->unitType($i), 'rem' => 0,
                'opA' => null, 'opB' => null, 'res' => null, 'addr' => null,
                'IS' => null, 'WB' => null, 'raw' => $i['raw'],
            ];
            $state->pc += 4;
            if ($i['class'] === 'JMP') {
                $o['branchWait'] = true; // stop prefetch past a branch
                return;
            }
        }
    }

    private function ready(array $win, int $k): bool
    {
        $i = $win[$k]['ins'];
        $dest = $this->destReg($i);
        $srcs = $this->srcRegs($i);

        for ($j = 0; $j < $k; $j++) {
            $o = $win[$j];
            if ($o['st'] === 'done') {
                continue;
            }
            $oWrite = $this->destReg($o['ins']);
            if ($oWrite !== null && in_array($oWrite, $srcs, true)) {
                return false; // RAW
            }
            if ($dest !== null && $oWrite === $dest) {
                return false; // WAW
            }
            if ($dest !== null && $o['st'] === 'wait' && in_array($dest, $this->srcRegs($o['ins']), true)) {
                return false; // WAR (older reader hasn't read yet)
            }
        }

        return true;
    }

    private function branchBarrier(array $win): int
    {
        foreach ($win as $k => $e) {
            if ($e['ins']['class'] === 'JMP' && $e['st'] !== 'done') {
                return $k;
            }
        }

        return count($win);
    }

    private function issue(CpuState $state): void
    {
        $o = &$state->ooo;
        $barrier = $this->branchBarrier($o['win']);

        foreach (self::UNITS as $u) {
            if ($o['unit'][$u]) {
                continue;
            }
            foreach ($o['win'] as $k => $e) {
                if ($k > $barrier) {
                    break;
                }
                if ($e['st'] !== 'wait' || $e['unit'] !== $u) {
                    continue;
                }
                if ($k === $barrier && $e['ins']['class'] !== 'JMP') {
                    continue; // don't issue past an unresolved branch
                }
                if (! $this->ready($o['win'], $k)) {
                    continue;
                }

                $i = $e['ins'];
                $e['opA'] = $i['src1'] !== null ? $state->registers[$i['src1']]->value : 0;
                $e['opB'] = $i['src2'] !== null ? $state->registers[$i['src2']]->value : ($i['immediate'] ?? 0);
                $e['st'] = 'exec';
                $e['rem'] = self::LATENCY[$u];
                $e['IS'] = $state->clock;
                $o['win'][$k] = $e;
                $o['unit'][$u] = true;
                $o['log'][] = ['raw' => $e['raw'], 'IS' => $state->clock, 'WB' => null];
                break;
            }
        }
    }

    private function complete(CpuState $state): void
    {
        $o = &$state->ooo;
        foreach ($o['win'] as $k => $e) {
            if ($e['st'] !== 'exec') {
                continue;
            }
            $e['rem']--;
            if ($e['rem'] > 0) {
                $o['win'][$k] = $e;
                continue;
            }

            $i = $e['ins'];
            $a = $e['opA'];
            $b = $e['opB'];
            $flushFrom = null;

            switch ($i['class']) {
                case 'ALU':
                    $e['res'] = InstructionSet::computeAlu($i['opcode'], $a, $b);
                    break;
                case 'LOAD':
                    $e['addr'] = $a + ($i['immediate'] ?? 0);
                    $e['res'] = $state->memory->read($e['addr']);
                    break;
                case 'STORE':
                    $e['addr'] = $b + ($i['immediate'] ?? 0);
                    $state->memory->write($e['addr'], $a);
                    break;
                case 'JMP':
                    $take = InstructionSet::isUnconditionalJump($i['opcode'])
                        || InstructionSet::branchTaken($i['opcode'], $a, $b);
                    if ($take) {
                        $state->pc = InstructionSet::isUnconditionalJump($i['opcode']) && $i['src1'] !== null
                            ? $a + ($i['immediate'] ?? 0)
                            : ($i['immediate'] ?? $state->pc);
                        $flushFrom = $k + 1; // squash younger fetched instructions
                    }
                    $o['branchWait'] = false;
                    break;
            }

            if ($this->writes($i) && $i['dest'] !== null && $i['dest'] !== 0) {
                $state->registers[$i['dest']]->value = $e['res'] ?? 0;
            }

            $e['st'] = 'done';
            $e['WB'] = $state->clock;
            $o['unit'][$e['unit']] = false;
            $o['win'][$k] = $e;

            foreach ($o['log'] as $li => $L) {
                if ($L['raw'] === $e['raw'] && $L['WB'] === null) {
                    $o['log'][$li]['WB'] = $state->clock;
                    break;
                }
            }

            if ($flushFrom !== null) {
                $o['win'] = array_slice($o['win'], 0, $flushFrom);
            }
        }
    }

    private function retire(CpuState $state): void
    {
        $o = &$state->ooo;
        while (! empty($o['win']) && $o['win'][0]['st'] === 'done') {
            array_shift($o['win']);
        }
    }
}