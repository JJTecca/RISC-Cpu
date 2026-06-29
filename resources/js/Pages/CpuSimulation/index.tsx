import { useState } from "react";
import axios from "axios";
import {
  Box, Typography, Button, Paper, Chip,
  TextField, CircularProgress, Alert, Snackbar, Divider, Stack, Collapse,
  Table, TableBody, TableCell, TableContainer, TableHead, TableRow,
} from "@mui/material";
import EastIcon from '@mui/icons-material/East';

const STAGES: { key: keyof Pipeline; label: string }[] = [
  { key: "if", label: "IF" },
  { key: "of", label: "OF" },
  { key: "ex", label: "EX" },
  { key: "mem", label: "MEM" },
  { key: "wb", label: "WB" },
];

type InstrClass = 'ALU' | 'LOAD' | 'STORE' | 'JMP';

interface Instruction {
    class: InstrClass;
    opcode: string;
    dest: number | null;
    src1: number | null;
    src2: number | null;
    immediate: number | null;
    address: number;
    raw: string;
}

interface StageLatch {
    instruction: Instruction;
    operand1: number | null;
    operand2: number | null;
    result: number | null;
    memoryAddress: number | null;
    stalled: boolean;
}

interface Pipeline {
    if: StageLatch | null;
    of: StageLatch | null;
    ex: StageLatch | null;
    mem: StageLatch | null;
    wb: StageLatch | null;
}

interface Register {
    value: number;
    valid: boolean;
}

interface Cpu {
    clock: number;
    pc: number;
    halted: boolean;
    mar: number;
    mdr: number;
    ir: Instruction | null;
    registers: Register[];
    pipeline: Pipeline;
}

interface IsaEntry {
    opcode: string;
    class: InstrClass;
    syntax: string;
    effect: string;
}

const toHex = (n: number) => "0x" + n.toString(16).toUpperCase().padStart(2, "0");

// Groups shown in the instruction-set panel, in this order.
const ISA_GROUPS: { key: InstrClass; label: string }[] = [
  { key: "ALU", label: "ALU" },
  { key: "LOAD", label: "Load" },
  { key: "STORE", label: "Store" },
  { key: "JMP", label: "Control (branch / jump)" },
];

const DEMO_PROGRAM = `ADD R1, R0, 5
ADD R2, R0, R0
loop:   ADD R2, R2, R1
SUB R1, R1, 1
BNE R1, R0, loop
ST  0[R0], R2
LD  R3, 0[R0]
MUL R5, R2, R2
JMP done
ADD R6, R0, 999
done:   ADD R7, R0, 42`;

export default function CpuSimulation(
  { cpu: initialCpu, instructionSet = [] }: { cpu: Cpu; instructionSet?: IsaEntry[] }
) {
  const [cpu, setCpu] = useState<Cpu>(initialCpu);
  const [source, setSource] = useState("ADD R9,R8,R7");
  const [baseAddress, setBaseAddress] = useState(256);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [showIsa, setShowIsa] = useState(true);

  const handle = async (request: Promise<{ data: { cpu: Cpu } }>) => {
    setLoading(true);
    setError(null);
    try {
      const { data } = await request;
      setCpu(data.cpu);
    } catch {
      setError("Request failed. Check the console for details.");
    } finally {
      setLoading(false);
    }
  };

  const load = () => handle(axios.post("/sim/load", { source, baseAddress }));
  const step = () => handle(axios.post("/sim/step"));
  const reset = () => handle(axios.post("/sim/reset"));
  const loadDemo = () => { setSource(DEMO_PROGRAM); setBaseAddress(256); };

  return (
    <Box sx={{ minHeight: "100vh", bgcolor: "grey.50", p: 3 }}>
      {/* ---------- header ---------- */}
      <Stack direction="row" alignItems="center" spacing={2} sx={{ mb: 3 }}>
        <Typography variant="h5" fontWeight={600}>
          RISC Pipeline Simulator
        </Typography>
        <Chip label={`Clock ${cpu.clock}`} size="small" />
        <Chip label={`PC ${cpu.pc}`} size="small" variant="outlined" />
        <Chip
          label={cpu.halted ? "halted" : "running"}
          size="small"
          color={cpu.halted ? "default" : "success"}
        />
        {loading && <CircularProgress size={18} />}
      </Stack>

      {/* ---------- control bar ---------- */}
      <Paper variant="outlined" sx={{ p: 2 }}>
        <Stack direction={{ xs: "column", md: "row" }} spacing={2} alignItems="flex-start">
          <TextField
            label="Program"
            value={source}
            onChange={(e) => setSource(e.target.value)}
            multiline
            minRows={3}
            fullWidth
            slotProps={{ htmlInput: { style: { fontFamily: "monospace" } } }}
          />
          <TextField
            label="Base address"
            type="number"
            value={baseAddress}
            onChange={(e) => setBaseAddress(Number(e.target.value))}
            sx={{ width: 140 }}
          />
        </Stack>

        <Stack direction="row" spacing={1} sx={{ mt: 2 }} flexWrap="wrap">
          <Button variant="contained" color="inherit" onClick={load} disabled={loading}>
            Load
          </Button>
          <Button variant="contained" onClick={step} disabled={loading || cpu.halted}>
            Next
          </Button>
          <Button variant="outlined" color="inherit" onClick={reset} disabled={loading}>
            Reset
          </Button>
          <Box sx={{ flex: 1 }} />
          <Button variant="text" onClick={loadDemo} disabled={loading}>
            Demo
          </Button>
          <Button variant="text" onClick={() => setShowIsa((v) => !v)}>
            {showIsa ? "Hide instruction set" : "Instruction set"}
          </Button>
        </Stack>
      </Paper>

      {/* ---------- main grid ---------- */}
      <Box
        sx={{
          mt: 3,
          display: "grid",
          gap: 3,
          gridTemplateColumns: { xs: "1fr", lg: "1fr 340px" },
        }}
      >
        {/* ---------- pipeline ---------- */}
        <Paper variant="outlined" sx={{ p: 2, bgcolor: "grey.100" }}>
          <Typography variant="subtitle2" color="text.secondary" sx={{ mb: 1.5 }}>
            Pipeline
          </Typography>
          <Stack direction="row" alignItems="stretch" spacing={1}>
            {STAGES.map((stage, i) => {
              const latch: StageLatch | null = cpu.pipeline[stage.key];
              return (
                <Stack key={stage.key} direction="row" alignItems="center" spacing={1} sx={{ flex: 1 }}>
                  <Paper
                    variant="outlined"
                    sx={{
                      flex: 1,
                      p: 1.5,
                      textAlign: "center",
                      borderColor: latch?.stalled ? "warning.main" : "divider",
                      bgcolor: latch ? "background.paper" : "grey.50",
                    }}
                  >
                    <Typography variant="caption" color="text.secondary" fontWeight={600}>
                      {stage.label}
                    </Typography>
                    <Typography sx={{ mt: 1, fontFamily: "monospace", minHeight: 24 }}>
                      {latch ? (
                        latch.instruction.raw
                      ) : (
                        <Box component="span" sx={{ color: "text.disabled" }}>—</Box>
                      )}
                    </Typography>
                    {latch?.stalled && (
                      <Chip label="stall" size="small" color="warning" sx={{ mt: 0.5 }} />
                    )}
                  </Paper>
                  {i < STAGES.length - 1 && (
                    <EastIcon fontSize="small" sx={{ color: "text.disabled" }} />
                  )}
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
        </Paper>

        {/* ---------- register file ---------- */}
        <Paper variant="outlined" sx={{ p: 2 }}>
          <Typography variant="subtitle2" color="text.secondary" sx={{ mb: 1.5 }}>
            Register File
          </Typography>
          <TableContainer sx={{ maxHeight: 480 }}>
            <Table size="small" stickyHeader sx={{ "& td, & th": { fontFamily: "monospace" } }}>
              <TableHead>
                <TableRow>
                  <TableCell>reg</TableCell>
                  <TableCell>value</TableCell>
                  <TableCell align="right">valid</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {cpu.registers.map((reg: Register, i: number) => (
                  <TableRow key={i} hover>
                    <TableCell sx={{ color: "text.secondary" }}>R{i}</TableCell>
                    <TableCell>{toHex(reg.value)}</TableCell>
                    <TableCell
                      align="right"
                      sx={{ color: reg.valid ? "text.disabled" : "error.main" }}
                    >
                      {reg.valid ? "1" : "0"}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </TableContainer>
        </Paper>
      </Box>

      {/* ---------- instruction set reference ---------- */}
      <Collapse in={showIsa}>
        <Paper variant="outlined" sx={{ p: 2, mt: 3 }}>
          <Typography variant="subtitle2" color="text.secondary" sx={{ mb: 1.5 }}>
            Instruction Set
          </Typography>
          <Box
            sx={{
              display: "grid",
              gap: 2,
              gridTemplateColumns: { xs: "1fr", md: "1fr 1fr" },
            }}
          >
            {ISA_GROUPS.map((group) => {
              const rows = instructionSet.filter((e) => e.class === group.key);
              if (rows.length === 0) return null;
              return (
                <Box key={group.key}>
                  <Typography variant="caption" color="text.secondary" fontWeight={600}>
                    {group.label}
                  </Typography>
                  <Table
                    size="small"
                    sx={{ mt: 0.5, "& td": { fontFamily: "monospace", borderBottom: "none", py: 0.25 } }}
                  >
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

      {/* ---------- error toast ---------- */}
      <Snackbar
        open={!!error}
        autoHideDuration={4000}
        onClose={() => setError(null)}
        anchorOrigin={{ vertical: "bottom", horizontal: "center" }}
      >
        <Alert severity="error" onClose={() => setError(null)}>
          {error}
        </Alert>
      </Snackbar>
    </Box>
  );
}
