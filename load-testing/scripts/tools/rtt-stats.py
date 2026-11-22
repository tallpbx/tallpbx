#!/usr/bin/env python3
"""Summarize SIPp RTT traces per call cell.

Each SIPp run with `-trace_rtt` writes one CSV row per scenario response
(`Date_ms;response_time_ms;rtd_no`). Capacity runs emit `setup` (initial INVITE
through 200 OK) and `teardown` (BYE through 200 OK) timers.

Usage:
    rtt-stats.py [--rtd=setup|teardown] CELL_DIR [CELL_DIR ...]

Prints one tab-separated line per cell:
    label  samples  avg_ms  p50_ms  p90_ms  p95_ms  max_ms
"""

import argparse
import statistics
import sys
from pathlib import Path


def percentile(values: list[float], p: float) -> float:
    """Linear-interpolated percentile, matching common monitoring tools."""
    values = sorted(values)
    if len(values) == 1:
        return values[0]

    position = (len(values) - 1) * (p / 100.0)
    lower = int(position)
    upper = min(lower + 1, len(values) - 1)
    weight = position - lower

    return values[lower] + (values[upper] - values[lower]) * weight


def read_rtd_times(path: Path, rtd_name: str = "setup") -> list[float]:
    """Return the response times recorded in one RTT trace for the given RTD timer."""
    samples: list[float] = []

    for line in path.read_text(encoding="utf-8", errors="replace").splitlines():
        parts = line.strip().split(";")

        if len(parts) != 3 or parts[2] != rtd_name:
            continue

        try:
            samples.append(float(parts[1]))
        except ValueError:
            continue

    return samples


def main() -> int:
    """Summarize every cell directory or trace file given on the command line."""
    parser = argparse.ArgumentParser(description="Summarize SIPp RTT traces per call cell.")
    parser.add_argument(
        "--rtd",
        default="setup",
        help="Named SIPp RTD timer to summarize (default: setup; also supports teardown).",
    )
    parser.add_argument("cells", nargs="+", help="Cell directories or *_rtt.csv files.")
    args = parser.parse_args()

    for cell in args.cells:
        path = Path(cell)

        if path.is_file():
            files = [path]
            label = f"{path.parent.name}/{path.name}"
        else:
            files = sorted(path.glob("*_rtt.csv"))
            label = cell

        if not files:
            print(f"{cell}\t(no RTT trace)", file=sys.stderr)
            continue

        samples: list[float] = []
        for trace in files:
            samples.extend(read_rtd_times(trace, args.rtd))

        if not samples:
            print(f"{cell}\t(no {args.rtd} rows)", file=sys.stderr)
            continue

        print(
            f"{label}\t{len(samples)}\t{statistics.fmean(samples):.1f}\t"
            f"{percentile(samples, 50):.1f}\t{percentile(samples, 90):.1f}\t"
            f"{percentile(samples, 95):.1f}\t{max(samples):.1f}"
        )

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
