<?php

namespace App\Simulator\Core;

// Extension 1.4 - Tomasulo's algorithm (Curs 12, "Metoda lui Tomasulo").
// Reservation stations on the unit inputs capture operands as a value or as the
// tag of the station that will produce them; the common data bus broadcasts
// (tag, result) to registers and waiting stations. Register renaming via tags
// eliminates WAR and WAW - only RAW remains. State lives in $state->tomasulo.
// Used when scheduler='tomasulo'.
class TomasuloClock
{
    private const STATIONS = [
        'A1' => 'ADD', 'A2' => 'ADD', 'A3' => 'ADD',
        'M1' => 'MUL', 'M2' => 'MUL',
        'L1' => 'LDST', 'L2' => 'LDST', 'L3' => 'LDST',
        'J1' => 'JMP',
    ];
    private const LATENCY = ['ADD' => 1, 'MUL' => 3, 'LDST' => 2, 'JMP' => 1];
    private const UNITS = ['ADD', 'MUL', 'LDST', 'JMP'];

    public function step(CpuState $state): CpuState
    {
        if ($state->halted) {
            return $state;
        }

        $this->ensureState($state);
        $this->executeAndBroadcast($state);
        $this->dispatch($state);
        $this->issue($state);
        $this->fetch($state);

        $tm = $state->tomasulo;
        if ($this->idle($tm) && $state->memory->readInstruction($state->pc) === null && ! $tm['branchWait']) {
            $state->halted = true;
        }

        $state->clock++;

        return $state;
    }

    private function ensureState(CpuState $state): void
    {
        if ($state->tomasulo === null) {
            $state->tomasulo = [
                'rs' => array_fill_keys(array_keys(self::STATIONS), null),
                'regtag' => [],
                'fu' => array_fill_keys(self::UNITS, null),
                'queue' => [],
                'log' => [],
                'branchWait' => false,
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

    private function fetch(CpuState $state): void
    {
        $tm = &$state->tomasulo;
        if ($tm['branchWait']) {
            return;
        }
        while (count($tm['queue']) < 8) {
            $instruction = $state->memory->readInstruction($state->pc);
            if ($instruction === null) {
                return;
            }
            $state->mar = $state->pc;
            $tm['queue'][] = $instruction->toArray();
            $state->pc += 4;
        }
    }

    private function issue(CpuState $state): void
    {
        $tm = &$state->tomasulo;
        if ($tm['branchWait'] || empty($tm['queue'])) {
            return;
        }

        $i = $tm['queue'][0];
        $type = $this->unitType($i);

        $tag = null;
        foreach (self::STATIONS as $name => $unit) {
            if ($unit === $type && $tm['rs'][$name] === null) {
                $tag = $name;
                break;
            }
        }
        if ($tag === null) {
            return; // structural: no free station
        }

        // Capture src1: value if ready, else the producing station's tag (RAW).
        if ($i['src1'] === null) {
            $vj = 0;
            $qj = null;
        } elseif (isset($tm['regtag'][$i['src1']])) {
            $vj = null;
            $qj = $tm['regtag'][$i['src1']];
        } else {
            $vj = $state->registers[$i['src1']]->value;
            $qj = null;
        }

        // src2: register, or the immediate when there is no second register.
        if ($i['src2'] === null) {
            $vk = $i['immediate'] ?? 0;
            $qk = null;
        } elseif (isset($tm['regtag'][$i['src2']])) {
            $vk = null;
            $qk = $tm['regtag'][$i['src2']];
        } else {
            $vk = $state->registers[$i['src2']]->value;
            $qk = null;
        }

        $tm['rs'][$tag] = [
            'ins' => $i, 'vj' => $vj, 'vk' => $vk, 'qj' => $qj, 'qk' => $qk,
            'dest' => $i['dest'], 'res' => null, 'addr' => null, 'execing' => false,
        ];

        if ($this->writes($i) && $i['dest'] !== null && $i['dest'] !== 0) {
            $tm['regtag'][$i['dest']] = $tag; // rename: kills WAW / WAR
        }

        $tm['log'][] = ['raw' => $i['raw'], 'tag' => $tag, 'IS' => $state->clock, 'EX' => null, 'WB' => null];
        array_shift($tm['queue']);

        if ($i['class'] === 'JMP') {
            $tm['branchWait'] = true;
        }
    }

    private function dispatch(CpuState $state): void
    {
        $tm = &$state->tomasulo;
        foreach (self::UNITS as $unit) {
            if ($tm['fu'][$unit] !== null) {
                continue;
            }
            foreach (self::STATIONS as $tag => $stationUnit) {
                if ($stationUnit !== $unit) {
                    continue;
                }
                $st = $tm['rs'][$tag];
                if ($st === null || $st['execing'] || $st['qj'] !== null || $st['qk'] !== null) {
                    continue;
                }
                $st['execing'] = true;
                $tm['rs'][$tag] = $st;
                $tm['fu'][$unit] = ['tag' => $tag, 'rem' => self::LATENCY[$unit]];
                foreach ($tm['log'] as $k => $L) {
                    if ($L['tag'] === $tag && $L['EX'] === null) {
                        $tm['log'][$k]['EX'] = $state->clock;
                        break;
                    }
                }
                break;
            }
        }
    }

    private function executeAndBroadcast(CpuState $state): void
    {
        $tm = &$state->tomasulo;
        foreach (self::UNITS as $unit) {
            $cur = $tm['fu'][$unit];
            if ($cur === null) {
                continue;
            }
            $cur['rem']--;
            $tm['fu'][$unit] = $cur;
            if ($cur['rem'] > 0) {
                continue;
            }

            $tag = $cur['tag'];
            $st = $tm['rs'][$tag];
            $i = $st['ins'];
            $a = $st['vj'];
            $b = $st['vk'];
            $result = null;

            switch ($i['class']) {
                case 'ALU':
                    $result = InstructionSet::computeAlu($i['opcode'], $a, $b);
                    break;
                case 'LOAD':
                    $st['addr'] = $a + ($i['immediate'] ?? 0);
                    $result = $state->memory->read($st['addr']);
                    break;
                case 'STORE':
                    $st['addr'] = $b + ($i['immediate'] ?? 0);
                    $state->memory->write($st['addr'], $a);
                    break;
                case 'JMP':
                    $take = InstructionSet::isUnconditionalJump($i['opcode'])
                        || InstructionSet::branchTaken($i['opcode'], $a, $b);
                    if ($take) {
                        $state->pc = InstructionSet::isUnconditionalJump($i['opcode']) && $i['src1'] !== null
                            ? $a + ($i['immediate'] ?? 0)
                            : ($i['immediate'] ?? $state->pc);
                        $tm['queue'] = [];
                    }
                    $tm['branchWait'] = false;
                    break;
            }

            // common data bus: broadcast (tag, result)
            if ($result !== null) {
                foreach ($tm['regtag'] as $reg => $t) {
                    if ($t === $tag) {
                        $state->registers[$reg]->value = $result;
                        unset($tm['regtag'][$reg]);
                    }
                }
                foreach (self::STATIONS as $name => $u2) {
                    $st2 = $tm['rs'][$name];
                    if ($st2 === null) {
                        continue;
                    }
                    if ($st2['qj'] === $tag) { $st2['vj'] = $result; $st2['qj'] = null; }
                    if ($st2['qk'] === $tag) { $st2['vk'] = $result; $st2['qk'] = null; }
                    $tm['rs'][$name] = $st2;
                }
            }

            foreach ($tm['log'] as $k => $L) {
                if ($L['tag'] === $tag && $L['WB'] === null) {
                    $tm['log'][$k]['WB'] = $state->clock;
                    break;
                }
            }

            $tm['rs'][$tag] = null;
            $tm['fu'][$unit] = null;
        }
    }

    private function idle(array $tm): bool
    {
        foreach ($tm['rs'] as $st) {
            if ($st !== null) {
                return false;
            }
        }

        return empty($tm['queue']);
    }
}