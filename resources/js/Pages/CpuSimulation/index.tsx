import { useState } from "react";
import axios from "axios";
import {
  Box, Typography, Button, Paper, Chip,
  TextField, CircularProgress, Alert, Snackbar, Divider, Stack, Collapse,
  ToggleButton, ToggleButtonGroup,
  Table, TableBody, TableCell, TableContainer, TableHead, TableRow,
} from "@mui/material";
import EastIcon from '@mui/icons-material/East';

type Scheduler = 'inorder' | 'superscalar' | 'scoreboard' | 'tomasulo' | 'ooo';
type InstrClass = 'ALU' | 'LOAD' | 'STORE' | 'JMP';

const STAGES: { key: keyof Pipeline; label: string }[] = [
  { key: "if", label: "IF" }, { key: "of", label: "OF" }, { key: "ex", label: "EX" },
  { key: "mem", label: "MEM" }, { key: "wb", label: "WB" },
];
const SS_UNITS: { key: keyof SuperscalarState["units"]; label: string }[] = [
  { key: "ADD", label: "ADD" }, { key: "MUL", label: "MUL" },
  { key: "LDST", label: "LD/ST" }, { key: "JMP", label: "JMP" },
];
const SB_UNITS = ["LDST", "MUL", "ADD1", "ADD2", "JMP"];
const TM_STATIONS = ["A1", "A2", "A3", "M1", "M2", "L1", "L2", "L3", "J1"];
const OOO_UNITS = ["ADD", "MUL", "LDST", "JMP"];

interface Instruction {
    class: InstrClass; opcode: string;
    dest: number | null; src1: number | null; src2: number | null;
    immediate: number | null; address: number; raw: string;
}
interface StageLatch {
    instruction: Instruction; operand1: number | null; operand2: number | null;
    result: number | null; memoryAddress: number | null; stalled: boolean;
}
interface Pipeline { if: StageLatch | null; of: StageLatch | null; ex: StageLatch | null; mem: StageLatch | null; wb: StageLatch | null; }

interface UnitOccupant { ins: Instruction; rem: number; }
interface SuperscalarState {
    units: { ADD: UnitOccupant | null; MUL: UnitOccupant | null; LDST: UnitOccupant | null; JMP: UnitOccupant | null };
    queue: Instruction[]; branchWait: boolean;
}

interface SbOccupant {
    ins: Instruction; stage: string;
    fi: number | null; fj: number | null; fk: number | null;
    qj: string | null; qk: string | null; rj: boolean; rk: boolean; rem: number;
}
interface SbLogEntry { raw: string; unit: string; IS: number | null; RO: number | null; EX: number | null; WB: number | null; }
interface ScoreboardState {
    units: Record<string, SbOccupant | null>;
    regstat: Record<string, string>;
    queue: Instruction[]; log: SbLogEntry[]; branchWait: boolean;
}

interface TmStation {
    ins: Instruction; vj: number | null; vk: number | null;
    qj: string | null; qk: string | null; dest: number | null;
    res: number | null; addr: number | null; execing: boolean;
}
interface TmLogEntry { raw: string; tag: string; IS: number | null; EX: number | null; WB: number | null; }
interface TomasuloState {
    rs: Record<string, TmStation | null>;
    regtag: Record<string, string>;
    fu: Record<string, { tag: string; rem: number } | null>;
    queue: Instruction[]; log: TmLogEntry[]; branchWait: boolean;
}

interface OooEntry {
    ins: Instruction; st: 'wait' | 'exec' | 'done'; unit: string; rem: number;
    res: number | null; IS: number | null; WB: number | null; raw: string;
}
interface OooState {
    win: OooEntry[]; unit: Record<string, boolean>;
    branchWait: boolean; windowSize: number;
    log: { raw: string; IS: number | null; WB: number | null }[];
}

interface Register { value: number; valid: boolean; }
interface CpuConfig { scheduler: Scheduler; superscalar: boolean; issueWidth: number; fetchWidth: number; }
interface Cpu {
    clock: number; pc: number; halted: boolean; mar: number; mdr: number;
    ir: Instruction | null; registers: Register[]; pipeline: Pipeline; config: CpuConfig;
    superscalar: SuperscalarState | null; scoreboard: ScoreboardState | null;
    tomasulo: TomasuloState | null; ooo: OooState | null;
}
interface IsaEntry { opcode: string; class: InstrClass; syntax: string; effect: string; }

const toHex = (n: number) => "0x" + n.toString(16).toUpperCase().padStart(2, "0");
const regRef = (n: number | null) => (n === null || n === undefined ? "—" : `R${n}`);
const cyc = (n: number | null) => (n === null || n === undefined ? "—" : String(n));
const stColor = (st: string) => (st === "exec" ? "primary" : st === "done" ? "success" : "default") as "primary" | "success" | "default";

const ISA_GROUPS: { key: InstrClass; label: string }[] = [
  { key: "ALU", label: "ALU" }, { key: "LOAD", label: "Load" },
  { key: "STORE", label: "Store" }, { key: "JMP", label: "Control (branch / jump)" },
];

const DEMOS: Record<Scheduler, string> = {
  inorder: `ADD R1, R0, 5
ADD R2, R0, R0
loop:   ADD R2, R2, R1
SUB R1, R1, 1
BNE R1, R0, loop
ST  0[R0], R2
LD  R3, 0[R0]
MUL R5, R2, R2
JMP done
ADD R6, R0, 999
done:   ADD R7, R0, 42`,
  superscalar: `ADD R1, R0, 6
MUL R2, R0, R0
LD  R3, 0[R0]
ADD R4, R1, R1
MUL R5, R4, R4
ST  0[R0], R5`,
  scoreboard: `ADD R1, R0, 2
ADD R2, R0, 3
ADD R3, R0, 4
ADD R4, R0, 5
MUL R5, R1, R2
ADD R2, R3, R4
ADD R2, R2, R5`,
  tomasulo: `ADD R3, R0, 10
ADD R4, R0, 20
ADD R1, R0, 5
ADD R2, R3, R4
ADD R2, R2, R1`,
  // A stalled MUL chain; the independent ADDs and LD issue out of order, ahead of MUL R2.
  ooo: `MUL R1, R0, R0
MUL R2, R1, R1
ADD R3, R0, 7
ADD R4, R0, 8
LD  R5, 0[R0]`,
};

export default function CpuSimulation(
  { cpu: initialCpu, instructionSet = [] }: { cpu: Cpu; instructionSet?: IsaEntry[] }
) {
  const [cpu, setCpu] = useState<Cpu>(initialCpu);
  const [source, setSource] = useState("ADD R9,R8,R7");
  const [baseAddress, setBaseAddress] = useState(256);
  const [scheduler, setScheduler] = useState<Scheduler>("inorder");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [showIsa, setShowIsa] = useState(true);

  const mode: Scheduler = cpu.config?.scheduler ?? "inorder";
  const isSuperscalar = mode === "superscalar";
  const isScoreboard = mode === "scoreboard";
  const isTomasulo = mode === "tomasulo";
  const isOoo = mode === "ooo";

  const tmExec = new Set<string>();
  if (cpu.tomasulo) Object.values(cpu.tomasulo.fu).forEach((f) => f && tmExec.add(f.tag));

  const handle = async (request: Promise<{ data: { cpu: Cpu } }>) => {
    setLoading(true); setError(null);
    try { const { data } = await request; setCpu(data.cpu); }
    catch { setError("Request failed. Check the console for details."); }
    finally { setLoading(false); }
  };

  const load = () => handle(axios.post("/sim/load", { source, baseAddress, scheduler }));
  const step = () => handle(axios.post("/sim/step"));
  const reset = () => handle(axios.post("/sim/reset"));
  const loadDemo = () => { setSource(DEMOS[scheduler]); setBaseAddress(256); };

  const wide = isScoreboard || isTomasulo || isOoo;

  return (
    <Box sx={{ minHeight: "100vh", bgcolor: "grey.50", p: 3 }}>
      {/* ---------- header ---------- */}
      <Stack direction="row" alignItems="center" spacing={2} sx={{ mb: 3 }} flexWrap="wrap">
        <Typography variant="h5" fontWeight={600}>RISC Pipeline Simulator</Typography>
        <Chip label={`Clock ${cpu.clock}`} size="small" />
        <Chip label={`PC ${cpu.pc}`} size="small" variant="outlined" />
        <Chip label={cpu.halted ? "halted" : "running"} size="small" color={cpu.halted ? "default" : "success"} />
        {mode !== "inorder" && <Chip label={mode} size="small" color="primary" />}
        {loading && <CircularProgress size={18} />}
      </Stack>

      {/* ---------- control bar ---------- */}
      <Paper variant="outlined" sx={{ p: 2 }}>
        <Stack direction={{ xs: "column", md: "row" }} spacing={2} alignItems="flex-start">
          <TextField
            label="Program" value={source} onChange={(e) => setSource(e.target.value)}
            multiline minRows={3} fullWidth
            slotProps={{ htmlInput: { style: { fontFamily: "monospace" } } }}
          />
          <TextField
            label="Base address" type="number" value={baseAddress}
            onChange={(e) => setBaseAddress(Number(e.target.value))} sx={{ width: 140 }}
          />
        </Stack>

        <Stack direction="row" spacing={1} sx={{ mt: 2 }} alignItems="center" flexWrap="wrap" useFlexGap>
          <Button variant="contained" color="inherit" onClick={load} disabled={loading}>Load</Button>
          <Button variant="contained" onClick={step} disabled={loading || cpu.halted}>Next</Button>
          <Button variant="outlined" color="inherit" onClick={reset} disabled={loading}>Reset</Button>
          <ToggleButtonGroup
            size="small" exclusive value={scheduler}
            onChange={(_, v: Scheduler | null) => v && setScheduler(v)} sx={{ ml: 1 }}
          >
            <ToggleButton value="inorder">In-order</ToggleButton>
            <ToggleButton value="superscalar">Superscalar</ToggleButton>
            <ToggleButton value="scoreboard">Scoreboard</ToggleButton>
            <ToggleButton value="tomasulo">Tomasulo</ToggleButton>
            <ToggleButton value="ooo">OoO</ToggleButton>
          </ToggleButtonGroup>
          <Box sx={{ flex: 1 }} />
          <Button variant="text" onClick={loadDemo} disabled={loading}>Demo</Button>
          <Button variant="text" onClick={() => setShowIsa((v) => !v)}>
            {showIsa ? "Hide instruction set" : "Instruction set"}
          </Button>
        </Stack>
        <Typography variant="caption" color="text.disabled" sx={{ display: "block", mt: 1 }}>
          Pick a scheduler before Load. It applies on the next Load.
        </Typography>
      </Paper>

      {/* ---------- main grid ---------- */}
      <Box sx={{ mt: 3, display: "grid", gap: 3, gridTemplateColumns: { xs: "1fr", lg: wide ? "1fr 360px" : "1fr 340px" } }}>
        {/* ---------- left panel ---------- */}
        <Paper variant="outlined" sx={{ p: 2, bgcolor: "grey.100" }}>
          {isOoo ? (
            <>
              <Stack direction="row" alignItems="center" spacing={1} sx={{ mb: 1.5 }}>
                <Typography variant="subtitle2" color="text.secondary">Prefetch Buffer / Instruction Window</Typography>
                <Box sx={{ flex: 1 }} />
                {OOO_UNITS.map((u) => (
                  <Chip key={u} label={u} size="small" color={cpu.ooo?.unit[u] ? "primary" : "default"}
                        variant={cpu.ooo?.unit[u] ? "filled" : "outlined"} />
                ))}
              </Stack>
              <TableContainer>
                <Table size="small" sx={{ "& td, & th": { fontFamily: "monospace", whiteSpace: "nowrap" } }}>
                  <TableHead>
                    <TableRow>
                      {["#", "instruction", "unit", "status", "issued", "done"].map((h) => (
                        <TableCell key={h} sx={{ fontWeight: 600 }}>{h}</TableCell>
                      ))}
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {(cpu.ooo?.win.length ?? 0) === 0 ? (
                      <TableRow><TableCell colSpan={6} sx={{ color: "text.disabled" }}>window empty</TableCell></TableRow>
                    ) : (
                      cpu.ooo?.win.map((e, i) => (
                        <TableRow key={i} hover>
                          <TableCell sx={{ color: "text.secondary" }}>{i}</TableCell>
                          <TableCell>{e.raw}</TableCell>
                          <TableCell sx={{ color: "text.secondary" }}>{e.unit}</TableCell>
                          <TableCell>
                            <Chip size="small" color={stColor(e.st)} variant={e.st === "wait" ? "outlined" : "filled"}
                                  label={e.st === "exec" ? `exec (${e.rem})` : e.st} />
                          </TableCell>
                          <TableCell>{cyc(e.IS)}</TableCell>
                          <TableCell>{cyc(e.WB)}</TableCell>
                        </TableRow>
                      ))
                    )}
                  </TableBody>
                </Table>
              </TableContainer>
              {cpu.ooo?.branchWait && <Chip label="prefetch paused at branch" size="small" color="warning" sx={{ mt: 1.5 }} />}
            </>
          ) : isTomasulo ? (
            <>
              <Typography variant="subtitle2" color="text.secondary" sx={{ mb: 1.5 }}>Reservation Stations</Typography>
              <TableContainer>
                <Table size="small" sx={{ "& td, & th": { fontFamily: "monospace", whiteSpace: "nowrap" } }}>
                  <TableHead>
                    <TableRow>
                      {["station", "busy", "op", "Vj", "Vk", "Qj", "Qk", "dest"].map((h) => (
                        <TableCell key={h} sx={{ fontWeight: 600 }}>{h}</TableCell>
                      ))}
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {TM_STATIONS.map((tag) => {
                      const st = cpu.tomasulo?.rs[tag] ?? null;
                      const execing = tmExec.has(tag);
                      return (
                        <TableRow key={tag} hover sx={{ bgcolor: execing ? "primary.50" : st ? "action.hover" : undefined }}>
                          <TableCell sx={{ color: "text.secondary" }}>{tag}{execing ? " ▶" : ""}</TableCell>
                          <TableCell>{st ? "yes" : "no"}</TableCell>
                          <TableCell>{st ? st.ins.opcode : "—"}</TableCell>
                          <TableCell>{st && st.qj === null && st.vj !== null ? toHex(st.vj) : "—"}</TableCell>
                          <TableCell>{st && st.qk === null && st.vk !== null ? toHex(st.vk) : "—"}</TableCell>
                          <TableCell sx={{ color: st && st.qj ? "warning.main" : "text.primary" }}>{st ? (st.qj ?? "—") : "—"}</TableCell>
                          <TableCell sx={{ color: st && st.qk ? "warning.main" : "text.primary" }}>{st ? (st.qk ?? "—") : "—"}</TableCell>
                          <TableCell>{st ? regRef(st.dest) : "—"}</TableCell>
                        </TableRow>
                      );
                    })}
                  </TableBody>
                </Table>
              </TableContainer>
              <Divider sx={{ my: 2 }} />
              <Typography variant="subtitle2" color="text.secondary" sx={{ mb: 1 }}>Issue Queue</Typography>
              <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
                {(cpu.tomasulo?.queue.length ?? 0) === 0
                  ? <Typography variant="body2" color="text.disabled">empty</Typography>
                  : cpu.tomasulo?.queue.map((instr, i) => (
                      <Chip key={i} label={instr.raw} size="small" variant={i === 0 ? "filled" : "outlined"} sx={{ fontFamily: "monospace" }} />
                    ))}
              </Stack>
              {cpu.tomasulo?.branchWait && <Chip label="waiting on branch" size="small" color="warning" sx={{ mt: 1.5 }} />}
            </>
          ) : isScoreboard ? (
            <>
              <Typography variant="subtitle2" color="text.secondary" sx={{ mb: 1.5 }}>Functional Unit Status</Typography>
              <TableContainer>
                <Table size="small" sx={{ "& td, & th": { fontFamily: "monospace", whiteSpace: "nowrap" } }}>
                  <TableHead>
                    <TableRow>
                      {["unit", "busy", "op", "Fi", "Fj", "Fk", "Qj", "Qk", "Rj", "Rk"].map((h) => (
                        <TableCell key={h} sx={{ fontWeight: 600 }}>{h}</TableCell>
                      ))}
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {SB_UNITS.map((u) => {
                      const o = cpu.scoreboard?.units[u] ?? null;
                      return (
                        <TableRow key={u} hover sx={{ bgcolor: o ? "action.hover" : undefined }}>
                          <TableCell sx={{ color: "text.secondary" }}>{u}</TableCell>
                          <TableCell>{o ? "yes" : "no"}</TableCell>
                          <TableCell>{o ? o.ins.opcode : "—"}</TableCell>
                          <TableCell>{o ? regRef(o.fi) : "—"}</TableCell>
                          <TableCell>{o ? regRef(o.fj) : "—"}</TableCell>
                          <TableCell>{o ? regRef(o.fk) : "—"}</TableCell>
                          <TableCell>{o ? (o.qj ?? "—") : "—"}</TableCell>
                          <TableCell>{o ? (o.qk ?? "—") : "—"}</TableCell>
                          <TableCell sx={{ color: o && !o.rj ? "warning.main" : "text.primary" }}>{o ? (o.rj ? "y" : "n") : "—"}</TableCell>
                          <TableCell sx={{ color: o && !o.rk ? "warning.main" : "text.primary" }}>{o ? (o.rk ? "y" : "n") : "—"}</TableCell>
                        </TableRow>
                      );
                    })}
                  </TableBody>
                </Table>
              </TableContainer>
              <Divider sx={{ my: 2 }} />
              <Typography variant="subtitle2" color="text.secondary" sx={{ mb: 1 }}>Issue Queue</Typography>
              <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
                {(cpu.scoreboard?.queue.length ?? 0) === 0
                  ? <Typography variant="body2" color="text.disabled">empty</Typography>
                  : cpu.scoreboard?.queue.map((instr, i) => (
                      <Chip key={i} label={instr.raw} size="small" variant={i === 0 ? "filled" : "outlined"} sx={{ fontFamily: "monospace" }} />
                    ))}
              </Stack>
              {cpu.scoreboard?.branchWait && <Chip label="waiting on branch" size="small" color="warning" sx={{ mt: 1.5 }} />}
            </>
          ) : isSuperscalar ? (
            <>
              <Typography variant="subtitle2" color="text.secondary" sx={{ mb: 1.5 }}>Execution Units</Typography>
              <Box sx={{ display: "grid", gap: 1.5, gridTemplateColumns: { xs: "1fr 1fr", md: "repeat(4, 1fr)" } }}>
                {SS_UNITS.map((unit) => {
                  const occ = cpu.superscalar?.units[unit.key] ?? null;
                  return (
                    <Paper key={unit.key} variant="outlined" sx={{ p: 1.5, textAlign: "center", borderColor: occ ? "primary.main" : "divider", bgcolor: occ ? "background.paper" : "grey.50" }}>
                      <Typography variant="caption" color="text.secondary" fontWeight={600}>{unit.label}</Typography>
                      <Typography sx={{ mt: 1, fontFamily: "monospace", minHeight: 24, fontSize: 13 }}>
                        {occ ? occ.ins.raw : <Box component="span" sx={{ color: "text.disabled" }}>idle</Box>}
                      </Typography>
                      {occ && <Chip label={`${occ.rem} cyc left`} size="small" color="primary" variant="outlined" sx={{ mt: 0.5 }} />}
                    </Paper>
                  );
                })}
              </Box>
              <Divider sx={{ my: 2 }} />
              <Typography variant="subtitle2" color="text.secondary" sx={{ mb: 1 }}>Issue Queue</Typography>
              <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
                {(cpu.superscalar?.queue.length ?? 0) === 0
                  ? <Typography variant="body2" color="text.disabled">empty</Typography>
                  : cpu.superscalar?.queue.map((instr, i) => (
                      <Chip key={i} label={instr.raw} size="small" variant={i === 0 ? "filled" : "outlined"} sx={{ fontFamily: "monospace" }} />
                    ))}
              </Stack>
            </>
          ) : (
            <>
              <Typography variant="subtitle2" color="text.secondary" sx={{ mb: 1.5 }}>Pipeline</Typography>
              <Stack direction="row" alignItems="stretch" spacing={1}>
                {STAGES.map((stage, i) => {
                  const latch: StageLatch | null = cpu.pipeline[stage.key];
                  return (
                    <Stack key={stage.key} direction="row" alignItems="center" spacing={1} sx={{ flex: 1 }}>
                      <Paper variant="outlined" sx={{ flex: 1, p: 1.5, textAlign: "center", borderColor: latch?.stalled ? "warning.main" : "divider", bgcolor: latch ? "background.paper" : "grey.50" }}>
                        <Typography variant="caption" color="text.secondary" fontWeight={600}>{stage.label}</Typography>
                        <Typography sx={{ mt: 1, fontFamily: "monospace", minHeight: 24 }}>
                          {latch ? latch.instruction.raw : <Box component="span" sx={{ color: "text.disabled" }}>—</Box>}
                        </Typography>
                        {latch?.stalled && <Chip label="stall" size="small" color="warning" sx={{ mt: 0.5 }} />}
                      </Paper>
                      {i < STAGES.length - 1 && <EastIcon fontSize="small" sx={{ color: "text.disabled" }} />}
                    </Stack>
                  );
                })}
              </Stack>
              <Divider sx={{ my: 2 }} />
              <Stack direction="row" spacing={2}>
                <Chip label={`MAR ${cpu.mar ?? "—"}`} size="small" variant="outlined" />
                <Chip label={`MDR ${cpu.mdr ?? "—"}`} size="small" variant="outlined" />
                <Chip label={`IR ${cpu.ir?.raw ?? "—"}`} size="small" variant="outlined" />
              </Stack>
            </>
          )}
        </Paper>

        {/* ---------- register file ---------- */}
        <Paper variant="outlined" sx={{ p: 2 }}>
          <Typography variant="subtitle2" color="text.secondary" sx={{ mb: 1.5 }}>Register File</Typography>
          <TableContainer sx={{ maxHeight: 480 }}>
            <Table size="small" stickyHeader sx={{ "& td, & th": { fontFamily: "monospace" } }}>
              <TableHead>
                <TableRow>
                  <TableCell>reg</TableCell>
                  <TableCell>value</TableCell>
                  <TableCell align="right">{isTomasulo ? "tag" : isScoreboard ? "writes" : isSuperscalar ? "ready" : "valid"}</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {cpu.registers.map((reg: Register, i: number) => {
                  const producer = isScoreboard ? cpu.scoreboard?.regstat?.[String(i)] : undefined;
                  const tag = isTomasulo ? cpu.tomasulo?.regtag?.[String(i)] : undefined;
                  return (
                    <TableRow key={i} hover>
                      <TableCell sx={{ color: "text.secondary" }}>R{i}</TableCell>
                      <TableCell>{toHex(reg.value)}</TableCell>
                      {isTomasulo ? (
                        <TableCell align="right" sx={{ color: tag ? "primary.main" : "text.disabled" }}>{tag ?? "—"}</TableCell>
                      ) : isScoreboard ? (
                        <TableCell align="right" sx={{ color: producer ? "primary.main" : "text.disabled" }}>{producer ?? "—"}</TableCell>
                      ) : (
                        <TableCell align="right" sx={{ color: reg.valid ? "text.disabled" : "error.main" }}>{reg.valid ? "1" : "0"}</TableCell>
                      )}
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </TableContainer>
        </Paper>
      </Box>

      {/* ---------- scoreboard / tomasulo instruction status ---------- */}
      {(isScoreboard || isTomasulo) && (
        <Paper variant="outlined" sx={{ p: 2, mt: 3 }}>
          <Typography variant="subtitle2" color="text.secondary" sx={{ mb: 1.5 }}>Instruction Status</Typography>
          <TableContainer>
            <Table size="small" sx={{ "& td, & th": { fontFamily: "monospace", whiteSpace: "nowrap" } }}>
              <TableHead>
                <TableRow>
                  <TableCell sx={{ fontWeight: 600 }}>instruction</TableCell>
                  <TableCell sx={{ fontWeight: 600 }}>{isTomasulo ? "station" : "unit"}</TableCell>
                  <TableCell sx={{ fontWeight: 600 }}>issue</TableCell>
                  {isScoreboard && <TableCell sx={{ fontWeight: 600 }}>read</TableCell>}
                  <TableCell sx={{ fontWeight: 600 }}>exec</TableCell>
                  <TableCell sx={{ fontWeight: 600 }}>write</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {isScoreboard && cpu.scoreboard?.log.map((e, i) => (
                  <TableRow key={i} hover>
                    <TableCell>{e.raw}</TableCell>
                    <TableCell sx={{ color: "text.secondary" }}>{e.unit}</TableCell>
                    <TableCell>{cyc(e.IS)}</TableCell>
                    <TableCell>{cyc(e.RO)}</TableCell>
                    <TableCell>{cyc(e.EX)}</TableCell>
                    <TableCell>{cyc(e.WB)}</TableCell>
                  </TableRow>
                ))}
                {isTomasulo && cpu.tomasulo?.log.map((e, i) => (
                  <TableRow key={i} hover>
                    <TableCell>{e.raw}</TableCell>
                    <TableCell sx={{ color: "text.secondary" }}>{e.tag}</TableCell>
                    <TableCell>{cyc(e.IS)}</TableCell>
                    <TableCell>{cyc(e.EX)}</TableCell>
                    <TableCell>{cyc(e.WB)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </TableContainer>
        </Paper>
      )}

      {/* ---------- OoO issue order (shows reordering vs program order) ---------- */}
      {isOoo && (
        <Paper variant="outlined" sx={{ p: 2, mt: 3 }}>
          <Typography variant="subtitle2" color="text.secondary" sx={{ mb: 0.5 }}>Issue Order</Typography>
          <Typography variant="caption" color="text.disabled" sx={{ display: "block", mb: 1.5 }}>
            Order instructions actually launched — compare with program order above to see the reordering.
          </Typography>
          <TableContainer>
            <Table size="small" sx={{ "& td, & th": { fontFamily: "monospace", whiteSpace: "nowrap" } }}>
              <TableHead>
                <TableRow>
                  {["#", "instruction", "issued", "written"].map((h) => (
                    <TableCell key={h} sx={{ fontWeight: 600 }}>{h}</TableCell>
                  ))}
                </TableRow>
              </TableHead>
              <TableBody>
                {(cpu.ooo?.log.length ?? 0) === 0 ? (
                  <TableRow><TableCell colSpan={4} sx={{ color: "text.disabled" }}>nothing issued yet</TableCell></TableRow>
                ) : (
                  cpu.ooo?.log.map((e, i) => (
                    <TableRow key={i} hover>
                      <TableCell sx={{ color: "text.secondary" }}>{i + 1}</TableCell>
                      <TableCell>{e.raw}</TableCell>
                      <TableCell>{cyc(e.IS)}</TableCell>
                      <TableCell>{cyc(e.WB)}</TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </TableContainer>
        </Paper>
      )}

      {/* ---------- instruction set reference ---------- */}
      <Collapse in={showIsa}>
        <Paper variant="outlined" sx={{ p: 2, mt: 3 }}>
          <Typography variant="subtitle2" color="text.secondary" sx={{ mb: 1.5 }}>Instruction Set</Typography>
          <Box sx={{ display: "grid", gap: 2, gridTemplateColumns: { xs: "1fr", md: "1fr 1fr" } }}>
            {ISA_GROUPS.map((group) => {
              const rows = instructionSet.filter((e) => e.class === group.key);
              if (rows.length === 0) return null;
              return (
                <Box key={group.key}>
                  <Typography variant="caption" color="text.secondary" fontWeight={600}>{group.label}</Typography>
                  <Table size="small" sx={{ mt: 0.5, "& td": { fontFamily: "monospace", borderBottom: "none", py: 0.25 } }}>
                    <TableBody>
                      {rows.map((e) => (
                        <TableRow key={e.opcode}>
                          <TableCell sx={{ whiteSpace: "nowrap" }}>{e.syntax}</TableCell>
                          <TableCell sx={{ color: "text.disabled" }}>{e.effect}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </Box>
              );
            })}
          </Box>
          <Typography variant="caption" color="text.disabled" sx={{ display: "block", mt: 1.5 }}>
            R0 is hardwired to 0. Third ALU operand may be a register or a constant.
            Targets can be labels (e.g. loop:) or addresses.
          </Typography>
        </Paper>
      </Collapse>

      <Snackbar open={!!error} autoHideDuration={4000} onClose={() => setError(null)} anchorOrigin={{ vertical: "bottom", horizontal: "center" }}>
        <Alert severity="error" onClose={() => setError(null)}>{error}</Alert>
      </Snackbar>
    </Box>
  );
}