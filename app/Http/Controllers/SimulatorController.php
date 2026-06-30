<?php

namespace App\Http\Controllers;

use App\Simulator\Core\Assembler;
use App\Simulator\Core\Cache;
use App\Simulator\Core\Clock;
use App\Simulator\Core\CpuState;
use App\Simulator\Core\InstructionSet;
use App\Simulator\Core\ScoreboardClock;
use App\Simulator\Core\SuperscalarClock;
use App\Simulator\Core\OutOfOrderClock;
use App\Simulator\Core\TomasuloClock;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Simulator\Core\Mmu;

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
            'cache' => ['sometimes', 'boolean'],
            'cacheSets' => ['sometimes', 'integer', 'min:1'],
            'cacheWays' => ['sometimes', 'integer', 'min:1'],
            'cacheLineSize' => ['sometimes', 'integer', 'min:1'],
            'replacement' => ['sometimes', 'in:lru,random,aprox'],
            'writePolicy' => ['sometimes', 'in:write-back,write-through'],
            'virtualMemory' => ['sometimes', 'boolean'],
            'pageSize' => ['sometimes', 'integer', 'min:1'],
            'tlbEntries' => ['sometimes', 'integer', 'min:1'],
            'virtualPages' => ['sometimes', 'integer', 'min:1'],
            'physicalFrames' => ['sometimes', 'integer', 'min:1'],
            'pageTableLocation' => ['sometimes', 'in:memory,cache'],
        ]);

        $scheduler = $validated['scheduler']
            ?? ($request->boolean('superscalar') ? 'superscalar' : 'inorder');

        $cpu = CpuState::fresh();
        $cpu->config->scheduler = $scheduler;
        $cpu->config->superscalar = $scheduler === 'superscalar';
        $assembler->loadInto($cpu, $validated['source'], $validated['baseAddress']);
        $this->configureCaches($cpu, $request);
        $this->configureVirtualMemory($cpu, $request);
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

    private function configureCaches(CpuState $cpu, Request $request): void
    {
        $cfg = $cpu->config;
        $cfg->cache = $request->boolean('cache');
        $cfg->cacheSets = (int) $request->input('cacheSets', $cfg->cacheSets);
        $cfg->cacheWays = (int) $request->input('cacheWays', $cfg->cacheWays);
        $cfg->cacheLineSize = (int) $request->input('cacheLineSize', $cfg->cacheLineSize);
        $cfg->replacement = $request->input('replacement', $cfg->replacement);
        $cfg->writePolicy = $request->input('writePolicy', $cfg->writePolicy);

        if (! $cfg->cache) {
            return;
        }
        $cpu->memory->dCache = Cache::make($cfg->cacheLineSize, $cfg->cacheSets, $cfg->cacheWays, $cfg->replacement, $cfg->writePolicy, $cfg->writeAllocate, $cfg->scanInterval, true);
        $cpu->memory->iCache = Cache::make($cfg->cacheLineSize, $cfg->cacheSets, $cfg->cacheWays, $cfg->replacement, $cfg->writePolicy, $cfg->writeAllocate, $cfg->scanInterval, false);
    }

    private function configureMmu(CpuState $cpu, Request $request): void
    {
        $cfg = $cpu->config;
        $cfg->virtualMemory = $request->boolean('virtualMemory');
        $cfg->pageSize = (int) $request->input('pageSize', $cfg->pageSize);
        $cfg->tlbEntries = (int) $request->input('tlbEntries', $cfg->tlbEntries);
        $cfg->virtualPages = (int) $request->input('virtualPages', $cfg->virtualPages);
        $cfg->physicalFrames = (int) $request->input('physicalFrames', $cfg->physicalFrames);
        $cfg->pageTableLocation = $request->input('pageTableLocation', $cfg->pageTableLocation);

        if (! $cfg->virtualMemory) {
            return;
        }

        $cpu->memory->mmu = Mmu::make(
            $cfg->pageSize,
            $cfg->tlbEntries,
            $cfg->virtualPages,
            $cfg->physicalFrames,
            $cfg->pageTableLocation,
        );
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