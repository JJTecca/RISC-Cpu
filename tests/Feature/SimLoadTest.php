<?php
 
it('loads a program and reports the base address as PC', function () {
    $this->postJson('/sim/load', [
        'source' => 'ADD R9,R8,R7',
        'baseAddress' => 256,
    ])
        ->assertOk()
        ->assertJsonPath('cpu.pc', 256);
});