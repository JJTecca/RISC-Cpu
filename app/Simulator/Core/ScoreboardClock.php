<?php

namespace App\Simulator\Core;

// Extension 1.3 - CDC6600-style scoreboard (Curs 11, "tabela de marcaj").
// Three tables live in $state->scoreboard: functional-unit status ('units'),
// destination-register status ('regstat'), instruction status ('log').
// Four stages per instruction: Issue -> ReadOperands -> Execute -> Write.
// Resolves RAW (read-operands wait), WAW (issue stall on pending dest) and
// WAR (write stall until earlier readers have read). Used when scheduler='scoreboard'.
class ScoreboardClock
{
    private const LATENCY = ['LDST' => 2, 'MUL' => 3, 'ADD1' => 1, 'ADD2' => 1, 'JMP' => 1];
    private const UNIT_NAMES = ['LDST', 'MUL', 'ADD1', 'ADD2', 'JMP'];

    public function step(CpuState $state): CpuState
    {
        if ($state->halted) {
            return $state;
        }

        $this->ensureState($state);
        $this->write($state);
        $this->execute($state);
        $this->readOperands($state);
        $this->issue($state);
        $this->fetch($state);

        $sb = $state->scoreboard;
        if ($this->idle($sb) && $state->memory->readInstruction($state->pc) === null && ! $sb['branchWait']) {
            $state->halted = true;
        }

        $state->clock++;

        return $state;
    }

    private function ensureState(CpuState $state): void
    {
        if ($state->scoreboard === null) {
            $state->scoreboard = [
                'units' => array_fill_keys(self::UNIT_NAMES, null),
                'regstat' => [],
                'queue' => [],
                'log' => [],
                'branchWait' => false,
            ];
        }
    }

    /** @return list<string> */
    private function candidateUnits(array $i): array
    {
        if ($i['class'] === 'LOAD' || $i['class'] === 'STORE') {
            return ['LDST'];
        }
        if ($i['class'] === 'ALU') {
            return strtoupper($i['opcode']) === 'MUL' ? ['MUL'] : ['ADD1', 'ADD2'];
        }

        return ['JMP'];
    }

    private function writes(array $i): bool
    {
        return $i['class'] === 'ALU' || $i['class'] === 'LOAD';
    }

    private function fetch(CpuState $state): void
    {
        $sb = &$state->scoreboard;
        if ($sb['branchWait']) {
            return;
        }
        while (count($sb['queue']) < 8) {
            $instruction = $state->memory->readInstruction($state->pc);
            if ($instruction === null) {
                return;
            }
            $state->mar = $state->pc;
            $sb['queue'][] = $instruction->toArray();
            $state->pc += 4;
        }
    }

    private function issue(CpuState $state): void
    {
        $sb = &$state->scoreboard;
        if ($sb['branchWait'] || empty($sb['queue'])) {
            return;
        }

        $i = $sb['queue'][0];

        $unit = null;
        foreach ($this->candidateUnits($i) as $candidate) {
            if ($sb['units'][$candidate] === null) {
                $unit = $candidate;
                break;
            }
        }
        if ($unit === null) {
            return; // structural hazard
        }

        $dest = $i['dest'];
        if ($this->writes($i) && $dest !== null && $dest !== 0 && isset($sb['regstat'][$dest])) {
            return; // WAW
        }

        $fj = $i['src1'];
        $fk = $i['src2'];

        $sb['units'][$unit] = [
            'ins' => $i, 'stage' => 'issued', 'fi' => $dest, 'fj' => $fj, 'fk' => $fk,
            'qj' => $sb['regstat'][$fj] ?? null, 'qk' => $sb['regstat'][$fk] ?? null,
            'rj' => ! isset($sb['regstat'][$fj]), 'rk' => ! isset($sb['regstat'][$fk]),
            'rem' => 0, 'opA' => null, 'opB' => null, 'res' => null,
            'addr' => null, 'data' => null, 'target' => null, 'take' => false,
            'logIdx' => count($sb['log']),
        ];

        if ($this->writes($i) && $dest !== null && $dest !== 0) {
            $sb['regstat'][$dest] = $unit;
        }

        $sb['log'][] = [
            'raw' => $i['raw'], 'unit' => $unit,
            'IS' => $state->clock, 'RO' => null, 'EX' => null, 'WB' => null,
        ];

        array_shift($sb['queue']);

        if ($i['class'] === 'JMP') {
            $sb['branchWait'] = true;
        }
    }

    private function readOperands(CpuState $state): void
    {
        $sb = &$state->scoreboard;
        foreach (self::UNIT_NAMES as $u) {
            $occ = $sb['units'][$u];
            if ($occ === null || $occ['stage'] !== 'issued' || ! $occ['rj'] || ! $occ['rk']) {
                continue;
            }
            $i = $occ['ins'];
            $occ['opA'] = $i['src1'] !== null ? $state->registers[$i['src1']]->value : 0;
            $occ['opB'] = $i['src2'] !== null ? $state->registers[$i['src2']]->value : ($i['immediate'] ?? 0);
            $occ['rj'] = false;
            $occ['rk'] = false;
            $occ['stage'] = 'exec';
            $occ['rem'] = self::LATENCY[$u];
            $sb['log'][$occ['logIdx']]['RO'] = $state->clock;
            $sb['units'][$u] = $occ;
        }
    }

    private function execute(CpuState $state): void
    {
        $sb = &$state->scoreboard;
        foreach (self::UNIT_NAMES as $u) {
            $occ = $sb['units'][$u];
            if ($occ === null || $occ['stage'] !== 'exec') {
                continue;
            }
            $occ['rem']--;
            if ($occ['rem'] <= 0) {
                $i = $occ['ins'];
                $a = $occ['opA'];
                $b = $occ['opB'];
                switch ($i['class']) {
                    case 'ALU':
                        $occ['res'] = InstructionSet::computeAlu($i['opcode'], $a, $b);
                        break;
                    case 'LOAD':
                        $occ['addr'] = $a + ($i['immediate'] ?? 0);
                        $occ['res'] = $state->memory->read($occ['addr']);
                        break;
                    case 'STORE':
                        $occ['addr'] = $b + ($i['immediate'] ?? 0);
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
                $occ['stage'] = 'done';
                $sb['log'][$occ['logIdx']]['EX'] = $state->clock;
            }
            $sb['units'][$u] = $occ;
        }
    }

    private function warClear(array $sb, string $unit, array $occ): bool
    {
        $fi = $occ['fi'];
        if ($fi === null || $fi === 0) {
            return true;
        }
        foreach (self::UNIT_NAMES as $u2) {
            if ($u2 === $unit) {
                continue;
            }
            $o2 = $sb['units'][$u2];
            if ($o2 === null) {
                continue;
            }
            if (($o2['fj'] === $fi && $o2['rj']) || ($o2['fk'] === $fi && $o2['rk'])) {
                return false; // an earlier instruction still needs to read fi
            }
        }

        return true;
    }

    private function write(CpuState $state): void
    {
        $sb = &$state->scoreboard;
        foreach (self::UNIT_NAMES as $u) {
            $occ = $sb['units'][$u];
            if ($occ === null || $occ['stage'] !== 'done' || ! $this->warClear($sb, $u, $occ)) {
                continue;
            }
            $i = $occ['ins'];

            if ($i['class'] === 'STORE') {
                $state->memory->write($occ['addr'], $occ['data'] ?? 0);
            }
            if ($this->writes($i) && $occ['fi'] !== null && $occ['fi'] !== 0) {
                $state->registers[$occ['fi']]->value = $occ['res'] ?? 0;
            }

            // common-bus broadcast: wake units waiting on this one
            foreach (self::UNIT_NAMES as $u2) {
                $o2 = $sb['units'][$u2];
                if ($o2 === null) {
                    continue;
                }
                if ($o2['qj'] === $u) { $o2['qj'] = null; $o2['rj'] = true; }
                if ($o2['qk'] === $u) { $o2['qk'] = null; $o2['rk'] = true; }
                $sb['units'][$u2] = $o2;
            }

            if ($this->writes($i) && $occ['fi'] !== null && ($sb['regstat'][$occ['fi']] ?? null) === $u) {
                unset($sb['regstat'][$occ['fi']]);
            }
            if ($i['class'] === 'JMP') {
                if ($occ['take']) {
                    $state->pc = $occ['target'];
                    $sb['queue'] = [];
                }
                $sb['branchWait'] = false;
            }

            $sb['log'][$occ['logIdx']]['WB'] = $state->clock;
            $sb['units'][$u] = null;
        }
    }

    private function idle(array $sb): bool
    {
        foreach (self::UNIT_NAMES as $u) {
            if ($sb['units'][$u] !== null) {
                return false;
            }
        }

        return empty($sb['queue']);
    }
}