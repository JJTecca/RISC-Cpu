<?php

namespace App\Simulator\Core;

enum InstrClass: string
{
    case ALU = 'ALU';
    case LOAD = 'LOAD';
    case STORE = 'STORE';
    case JMP = 'JMP';
}