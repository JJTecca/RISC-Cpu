<?php

namespace App\Simulator\Core;

// Two-pass assembler for the Curs 2 ISA. Pass 1 assigns addresses + collects
// labels; pass 2 parses each line by the operand shape its opcode declares.
class Assembler
{
    /** @return array<int, Instruction> address => instruction */
    public function assemble(string $source, int $baseAddress = 0): array
    {
        [$lines, $labels] = $this->firstPass($source, $baseAddress);

        $instructions = [];
        foreach ($lines as [$address, $line]) {
            $instructions[$address] = $this->parseLine($line, $address, $labels);
        }

        return $instructions;
    }

    public function loadInto(CpuState $state, string $source, int $baseAddress = 0): void
    {
        foreach ($this->assemble($source, $baseAddress) as $address => $instruction) {
            $state->memory->writeInstruction($address, $instruction);
        }

        $state->pc = $baseAddress;
    }

    /** @return array{0: array<int, array{0:int,1:string}>, 1: array<string,int>} */
    private function firstPass(string $source, int $baseAddress): array
    {
        $lines = [];
        $labels = [];
        $address = $baseAddress;

        foreach (explode("\n", $source) as $raw) {
            $line = trim($this->stripComment($raw));

            while (preg_match('/^([A-Za-z_]\w*)\s*:\s*(.*)$/', $line, $m)) {
                $labels[strtoupper($m[1])] = $address;
                $line = trim($m[2]);
            }

            if ($line === '') {
                continue;
            }

            $lines[] = [$address, $line];
            $address += 4;
        }

        return [$lines, $labels];
    }

    /** @param array<string,int> $labels */
    private function parseLine(string $line, int $address, array $labels): Instruction
    {
        $parts = preg_split('/[\s,]+/', trim($line));
        $opcode = strtoupper((string) array_shift($parts));
        [$class, $shape] = InstructionSet::lookup($opcode) ?? [InstrClass::ALU, 'ALU3'];

        $ops = array_values(array_filter($parts, fn (string $p) => $p !== ''));

        $dest = $src1 = $src2 = $immediate = null;

        switch ($shape) {
            case 'ALU3':
                $dest = $this->register($ops[0] ?? null);
                $src1 = $this->register($ops[1] ?? null);
                $third = $ops[2] ?? null;
                // R-R-R if the third operand is a register, else R-R-I.
                if ($this->isRegister($third)) {
                    $src2 = $this->register($third);
                } else {
                    $immediate = $this->value($third, $labels);
                }
                break;

            case 'LOAD':
                $dest = $this->register($ops[0] ?? null);
                [$immediate, $src1] = $this->offsetBase($ops[1] ?? null, $labels);
                break;

            case 'STORE': // memory operand comes first: ST off[Rb], Rs
                [$immediate, $src2] = $this->offsetBase($ops[0] ?? null, $labels);
                $src1 = $this->register($ops[1] ?? null);
                break;

            case 'BRANCH':
                $src1 = $this->register($ops[0] ?? null);
                $src2 = $this->register($ops[1] ?? null);
                $immediate = $this->value($ops[2] ?? null, $labels);
                break;

            case 'JMP':
                $target = $ops[0] ?? null;
                if ($target !== null && str_contains($target, '[')) {
                    [$immediate, $src1] = $this->offsetBase($target, $labels);
                } else {
                    $immediate = $this->value($target, $labels);
                }
                break;
        }

        return new Instruction(
            class: $class,
            opcode: $opcode,
            dest: $dest,
            src1: $src1,
            src2: $src2,
            immediate: $immediate,
            address: $address,
            raw: $line,
        );
    }

    private function stripComment(string $line): string
    {
        $position = strpos($line, ';');

        return $position === false ? $line : substr($line, 0, $position);
    }

    private function isRegister(?string $token): bool
    {
        return $token !== null && preg_match('/^R\d+$/i', trim($token)) === 1;
    }

    private function register(?string $token): ?int
    {
        if ($token === null) {
            return null;
        }

        $token = trim($token, "[] \t");

        return preg_match('/^R(\d+)$/i', $token, $m) === 1 ? (int) $m[1] : null;
    }

    /** A label, a hex literal (1F4h), or a number. @param array<string,int> $labels */
    private function value(?string $token, array $labels): ?int
    {
        if ($token === null) {
            return null;
        }

        $token = trim($token, "[] \t");
        if ($token === '') {
            return null;
        }

        $upper = strtoupper($token);

        if (array_key_exists($upper, $labels)) {
            return $labels[$upper];
        }

        if (preg_match('/^[0-9A-F]+H$/i', $token)) {
            return (int) hexdec(substr($token, 0, -1));
        }

        return (int) $token;
    }

    /** "200[R8]" => [200, 8], "[R8]" => [0, 8]. @return array{0:int,1:int} [offset, base] */
    private function offsetBase(?string $token, array $labels): array
    {
        if ($token === null) {
            return [0, 0];
        }

        $token = trim($token);

        if (preg_match('/^(-?\w+)?\s*\[\s*R(\d+)\s*\]$/i', $token, $m)) {
            $offset = (isset($m[1]) && $m[1] !== '') ? ($this->value($m[1], $labels) ?? 0) : 0;

            return [$offset, (int) $m[2]];
        }

        return [$this->value($token, $labels) ?? 0, 0];
    }
}