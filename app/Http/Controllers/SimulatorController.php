<?php

namespace App\Http\Controllers;

use App\Simulator\Core\Assembler;
use App\Simulator\Core\Clock;
use App\Simulator\Core\CpuState;
use App\Simulator\Core\InstructionSet;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SimulatorController extends Controller
{
    public function index()
    {
        $cpu = session('cpu') ?? CpuState::fresh()->toArray();

        return Inertia::render('CpuSimulation/index', [
            'cpu' => $cpu,
            'instructionSet' => InstructionSet::describe(),
        ]);
    }

    public function load(Request $request, Assembler $assembler)
    {
        $validated = $request->validate([
            'source' => ['required', 'string'],
            'baseAddress' => ['required', 'integer', 'min:0'],
        ]);

        $cpu = CpuState::fresh();
        $assembler->loadInto($cpu, $validated['source'], $validated['baseAddress']);
        session(['cpu' => $cpu->toArray()]);

        return response()->json(['cpu' => $cpu->toArray()]);
    }

    public function step(Clock $clock)
    {
        $cpu = CpuState::fromArray(session('cpu') ?? CpuState::fresh()->toArray());
        $cpu = $clock->step($cpu);
        session(['cpu' => $cpu->toArray()]);

        return response()->json(['cpu' => $cpu->toArray()]);
    }

    public function reset()
    {
        $cpu = CpuState::fresh();
        session(['cpu' => $cpu->toArray()]);

        return response()->json(['cpu' => $cpu->toArray()]);
    }
}