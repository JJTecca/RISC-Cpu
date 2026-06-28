<?php
 
use App\Simulator\Core\Assembler;
use App\Simulator\Core\InstrClass;
 
it('parses an ALU instruction into the right fields', function () {
    $program = (new Assembler())->assemble('ADD R9,R8,R7', 0);
    $instruction = $program[0];
 
    expect($instruction->class)->toBe(InstrClass::ALU)
        ->and($instruction->opcode)->toBe('ADD')
        ->and($instruction->dest)->toBe(9)
        ->and($instruction->src1)->toBe(8)
        ->and($instruction->src2)->toBe(7);
})->group('simulator'); // belonging to simulator suite

