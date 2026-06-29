<?php

namespace App\Http\Controllers;

use App\Simulator\Core\Assembler;
use App\Simulator\Core\Clock;
use App\Simulator\Core\CpuState;
use App\Simulator\Core\InstructionSet;
use App\Simulator\Core\ScoreboardClock;
use App\Simulator\Core\SuperscalarClock;
use App\Simulator\Core\OutOfOrderClock;
use App\Simulator\Core\TomasuloClock;
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
            'scheduler' => ['sometimes', 'in:inorder,superscalar,scoreboard,tomasulo,ooo'],
            'superscalar' => ['sometimes', 'boolean'], // legacy toggle
        ]);

        $scheduler = $validated['scheduler']
            ?? ($request->boolean('superscalar') ? 'superscalar' : 'inorder');

        $cpu = CpuState::fresh();
        $cpu->config->scheduler = $scheduler;
        $cpu->config->superscalar = $scheduler === 'superscalar';
        $assembler->loadInto($cpu, $validated['source'], $validated['baseAddress']);
        session(['cpu' => $cpu->toArray()]);

        return response()->json(['cpu' => $cpu->toArray()]);
    }

    public function step()
    {
        $cpu = CpuState::fromArray(session('cpu') ?? CpuState::fresh()->toArray());
        $cpu = $this->engineFor($cpu)->step($cpu);
        session(['cpu' => $cpu->toArray()]);

        return response()->json(['cpu' => $cpu->toArray()]);
    }

    public function reset()
    {
        $cpu = CpuState::fresh();
        session(['cpu' => $cpu->toArray()]);

        return response()->json(['cpu' => $cpu->toArray()]);
    }

    private function engineFor(CpuState $cpu): Clock|SuperscalarClock|ScoreboardClock|TomasuloClock|OutOfOrderClock
    {
        return match ($cpu->config->scheduler) {
            'scoreboard' => new ScoreboardClock(),
            'tomasulo' => new TomasuloClock(),
            'ooo' => new OutOfOrderClock(),
            'superscalar' => new SuperscalarClock(),
            default => new Clock(),
        };
    }
}