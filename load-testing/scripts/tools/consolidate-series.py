#!/usr/bin/env python3
"""Validate and consolidate a single uniform TallPBX load-test comparison series.

Enforces cross-tier consistency so docs/load-testing-results.md is always built
from one clean series (same build SHA, same XML profiles, same PHP-FPM dynamic
pool shape, same 60-second SIP ladder with setup + teardown latency) instead of
cobbling together incomparable historical runs from different dates.

Usage:
    consolidate-series.py SERIES_DIR [options]

Options:
    --tiers=h1-1c1g,h2-2c2g,h4-4c8g   Required tiers (default: standard_comparison.tiers)
    --csv-out=PATH                    Write standardized consolidated comparison CSV
    --markdown-out=PATH               Write ready-to-publish Markdown tables + Mermaid charts
    --allow-incomplete                Emit partial output with warnings instead of exiting non-zero
"""

from __future__ import annotations

import argparse
import csv
import json
import statistics
import subprocess
import sys
from pathlib import Path
from typing import Any

REPO_ROOT = Path(__file__).resolve().parents[3]
MATRIX_PATH = REPO_ROOT / "load-testing" / "matrix.php"

CSV_COLUMNS = [
    "series",
    "date",
    "git_commit",
    "tier",
    "vcpu",
    "ram_mb",
    "variant",
    "phase",
    "workload",
    "rate_cps",
    "offered",
    "completed",
    "failed",
    "completion_pct",
    "rps",
    "p50_ms",
    "p95_ms",
    "max_ms",
    "teardown_p50_ms",
    "teardown_p95_ms",
    "teardown_max_ms",
    "fs_db",
    "cache_policy",
]


def load_matrix() -> dict[str, Any]:
    """Load load-testing/matrix.php as JSON via PHP."""
    result = subprocess.run(
        ["php", "-r", "echo json_encode(require $argv[1]);", str(MATRIX_PATH)],
        capture_output=True,
        text=True,
        check=True,
    )
    return json.loads(result.stdout)


def find_variant_dir(tier_dir: Path, variant: str) -> Path | None:
    """Locate the variant artifact directory (e.g. v-fast-default-fpm-dynamic6)."""
    matches = sorted(p for p in tier_dir.glob(f"{variant}-fpm-dynamic*") if p.is_dir())
    if len(matches) == 1:
        return matches[0]
    direct = tier_dir / variant
    if direct.is_dir():
        return direct
    return None


def fmt(value: float | None, decimals: int = 1) -> str:
    if value is None:
        return ""
    return f"{value:.{decimals}f}"


def parse_float(raw: str | None) -> float | None:
    if raw is None or raw.strip() == "":
        return None
    try:
        return float(raw.strip())
    except ValueError:
        return None


def summarize_xml_profile(
    variant_dir: Path,
    profile: str,
    ttl: str,
    section: str,
    expected_repeats: int,
) -> tuple[dict[str, Any] | None, list[str]]:
    """Aggregate repeat JSON reports for one XML profile."""
    errors: list[str] = []
    files = sorted(variant_dir.glob(f"{profile}-{ttl}-{section}-r*.json"))
    if len(files) < expected_repeats:
        errors.append(
            f"missing XML reports in {variant_dir.name}: expected {expected_repeats}x "
            f"{profile}-{ttl}-{section}-r*.json, found {len(files)}"
        )
        return None, errors

    rps_vals: list[float] = []
    p50_vals: list[float] = []
    p95_vals: list[float] = []
    max_vals: list[float] = []
    total_offered = 0
    total_completed = 0
    total_failed = 0

    for report_file in files:
        data = json.loads(report_file.read_text(encoding="utf-8"))
        offered = int(data.get("total_requests", 0))
        completed = int(data.get("successful_responses", 0))
        failed = int(data.get("failed_responses", 0))
        total_offered += offered
        total_completed += completed
        total_failed += failed
        if failed > 0 or float(data.get("success_rate_percent", 0)) < 100.0:
            errors.append(f"non-100% XML report: {report_file}")

        rps = parse_float(str(data.get("requests_per_second", "")))
        lat = data.get("latency_ms", {})
        p50 = parse_float(str(lat.get("p50", "")))
        p95 = parse_float(str(lat.get("p95", "")))
        mx = parse_float(str(lat.get("max", "")))
        if None in (rps, p50, p95, mx):
            errors.append(f"incomplete latency/throughput metrics in {report_file}")
            continue
        rps_vals.append(rps)  # type: ignore[arg-type]
        p50_vals.append(p50)  # type: ignore[arg-type]
        p95_vals.append(p95)  # type: ignore[arg-type]
        max_vals.append(mx)  # type: ignore[arg-type]

    if not rps_vals:
        return None, errors

    return {
        "offered": total_offered,
        "completed": total_completed,
        "failed": total_failed,
        "completion_pct": (100.0 * total_completed / total_offered) if total_offered else 0.0,
        "rps": statistics.median(rps_vals),
        "p50_ms": statistics.median(p50_vals),
        "p95_ms": statistics.median(p95_vals),
        "max_ms": max(max_vals),
    }, errors


def parse_ladder_csv(variant_dir: Path) -> tuple[list[dict[str, Any]], list[str]]:
    """Read SIP ladder rows including per-call setup and teardown latency."""
    errors: list[str] = []
    ladder_path = variant_dir / "ladder.csv"
    if not ladder_path.is_file():
        return [], [f"missing ladder.csv in {variant_dir}"]

    rows: list[dict[str, Any]] = []
    with ladder_path.open(encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle)
        for row in reader:
            cps = int(row["cps"])
            completed = int(row["successful"])
            failed = int(row["failed"])
            offered = completed + failed
            setup_p50 = parse_float(row.get("setup_p50_ms"))
            setup_p95 = parse_float(row.get("setup_p95_ms"))
            setup_max = parse_float(row.get("setup_max_ms"))
            td_p50 = parse_float(row.get("teardown_p50_ms"))
            td_p95 = parse_float(row.get("teardown_p95_ms"))
            td_max = parse_float(row.get("teardown_max_ms"))

            if setup_p50 is None or setup_p95 is None:
                errors.append(f"missing setup p50/p95 at {cps} CPS in {ladder_path}")
            if td_p50 is None or td_p95 is None:
                errors.append(f"missing teardown p50/p95 at {cps} CPS in {ladder_path}")

            rows.append(
                {
                    "cps": cps,
                    "offered": offered,
                    "completed": completed,
                    "failed": failed,
                    "completion_pct": (100.0 * completed / offered) if offered else 0.0,
                    "setup_p50_ms": setup_p50,
                    "setup_p95_ms": setup_p95,
                    "setup_max_ms": setup_max,
                    "teardown_p50_ms": td_p50,
                    "teardown_p95_ms": td_p95,
                    "teardown_max_ms": td_max,
                }
            )

    if not rows:
        errors.append(f"empty ladder.csv in {variant_dir}")
    return rows, errors


def build_markdown(
    series: str,
    commit: str,
    date_str: str,
    tiers: list[str],
    tier_meta: dict[str, dict[str, Any]],
    csv_rows: list[dict[str, str]],
) -> str:
    """Render standardized comparison Markdown tables + Mermaid charts for docs/load-testing-results.md."""
    lines: list[str] = [
        f"## Unified Comparison Series — `{series}` ({date_str}, build `{commit[:8]}`)",
        "",
        "All tiers below were measured on the same build commit, cache policy (`production-60`), and dynamic PHP-FPM pool ceiling.",
        "",
        "### XML Lookup Throughput & Latency (PHP-FPM vs. Octane, `mixed`)",
        "",
        "| Test server | Workload | PHP-FPM req/sec | Octane req/sec | PHP-FPM median / p95 | Octane median / p95 |",
        "| --- | --- | ---: | ---: | ---: | ---: |",
    ]

    xml_index: dict[tuple[str, str, str], dict[str, str]] = {}
    for row in csv_rows:
        if row["phase"] == "xml":
            xml_index[(row["tier"], row["workload"], row["variant"])] = row

    xml_chart_labels: list[str] = []
    xml_fpm_values: list[str] = []
    xml_oct_values: list[str] = []

    for tier in tiers:
        label = f"{tier_meta[tier]['vcpu']} vCPU / {tier_meta[tier]['ram_mb'] // 1024} GB RAM"
        for profile in ("100x5", "500x25", "1000x25"):
            fpm = xml_index.get((tier, profile, "v-fast-default"))
            oct_row = xml_index.get((tier, profile, "v-octane"))
            if not fpm and not oct_row:
                continue
            fpm_rps = f"{fpm['rps']} req/sec" if fpm else "—"
            oct_rps = f"**{oct_row['rps']} req/sec**" if oct_row else "—"
            fpm_lat = f"{fpm['p50_ms']} / {fpm['p95_ms']} ms" if fpm else "—"
            oct_lat = f"**{oct_row['p50_ms']} / {oct_row['p95_ms']} ms**" if oct_row else "—"
            lines.append(f"| {label} | `{profile}` | {fpm_rps} | {oct_rps} | {fpm_lat} | {oct_lat} |")
            if profile == "500x25" and fpm and oct_row:
                short_tier = f"{tier_meta[tier]['vcpu']} vCPU 500x25"
                xml_chart_labels.append(f'"{short_tier}"')
                xml_fpm_values.append(fpm["p50_ms"])
                xml_oct_values.append(oct_row["p50_ms"])

    if xml_chart_labels:
        fpm_floats = [float(v) for v in xml_fpm_values]
        oct_floats = [float(v) for v in xml_oct_values]
        max_y = max(fpm_floats + oct_floats)
        y_top = int(((max_y // 50) + 2) * 50)
        can_overlay = all(f >= o for f, o in zip(fpm_floats, oct_floats))
        if can_overlay:
            lines.extend(
                [
                    "",
                    "```mermaid",
                    "---",
                    "config:",
                    "  themeVariables:",
                    "    xyChart:",
                    '      plotColorPalette: "#2563eb, #f97316"',
                    "---",
                    "xychart-beta",
                    '    title "Median XML Lookup Latency at 500x25 Mixed: Octane vs. PHP-FPM (ms, lower is better)"',
                    f"    x-axis [{', '.join(xml_chart_labels)}]",
                    f'    y-axis "Median Lookup Latency (ms)" 0 --> {y_top}',
                    f"    bar [{', '.join(xml_fpm_values)}]",
                    f"    bar [{', '.join(xml_oct_values)}]",
                    "```",
                    "",
                    "🟧 **Laravel Octane** *(median latency)* · 🟦 **PHP-FPM** *(additional latency; full bar height = PHP-FPM total)*",
                ]
            )
        else:
            paired_labels: list[str] = []
            paired_vals: list[str] = []
            for lbl, fv, ov in zip(xml_chart_labels, xml_fpm_values, xml_oct_values):
                raw = lbl.strip('"')
                paired_labels.extend([f'"{raw} FPM"', f'"{raw} Octane"'])
                paired_vals.extend([fv, ov])
            lines.extend(
                [
                    "",
                    "```mermaid",
                    "xychart-beta",
                    '    title "Median XML Lookup Latency at 500x25 Mixed: PHP-FPM vs. Octane (ms, lower is better)"',
                    f"    x-axis [{', '.join(paired_labels)}]",
                    f'    y-axis "Median Lookup Latency (ms)" 0 --> {y_top}',
                    f"    bar [{', '.join(paired_vals)}]",
                    "```",
                ]
            )

    lines.extend(
        [
            "",
            "### 60-Second Call Capacity & Individual Call Latency (PHP-FPM vs. Octane)",
            "",
            "| Test server | Offered rate | PHP-FPM completed | Octane completed | PHP-FPM setup p50 / p95 | Octane setup p50 / p95 | PHP-FPM teardown p50 / p95 | Octane teardown p50 / p95 |",
            "| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |",
        ]
    )

    sip_by_tier_cps: dict[tuple[str, int], dict[str, dict[str, str]]] = {}
    for row in csv_rows:
        if row["phase"] == "sip":
            sip_by_tier_cps.setdefault((row["tier"], int(row["rate_cps"])), {})[row["variant"]] = row

    clean_chart_labels: list[str] = []
    clean_fpm_values: list[str] = []
    clean_oct_values: list[str] = []

    for tier in tiers:
        label = f"{tier_meta[tier]['vcpu']} vCPU / {tier_meta[tier]['ram_mb'] // 1024} GB RAM"
        rates = sorted(cps for (t, cps) in sip_by_tier_cps if t == tier)
        highest_clean_pair: tuple[int, dict[str, str], dict[str, str]] | None = None
        for cps in rates:
            pair = sip_by_tier_cps[(tier, cps)]
            fpm = pair.get("v-fast-default")
            oct_row = pair.get("v-octane")
            fpm_comp = f"{fpm['completed']} / {fpm['offered']} ({fpm['completion_pct']}%)" if fpm else "—"
            oct_comp = f"{oct_row['completed']} / {oct_row['offered']} ({oct_row['completion_pct']}%)" if oct_row else "—"
            fpm_setup = f"{fpm['p50_ms']} / {fpm['p95_ms']} ms" if fpm else "—"
            oct_setup = f"**{oct_row['p50_ms']} / {oct_row['p95_ms']} ms**" if oct_row else "—"
            fpm_td = f"{fpm['teardown_p50_ms']} / {fpm['teardown_p95_ms']} ms" if fpm else "—"
            oct_td = f"{oct_row['teardown_p50_ms']} / {oct_row['teardown_p95_ms']} ms" if oct_row else "—"
            lines.append(
                f"| {label} | {cps} calls/sec | {fpm_comp} | {oct_comp} | {fpm_setup} | {oct_setup} | {fpm_td} | {oct_td} |"
            )
            if fpm and oct_row and fpm["failed"] == "0" and oct_row["failed"] == "0":
                highest_clean_pair = (cps, fpm, oct_row)

        if highest_clean_pair is not None:
            clean_cps, clean_fpm, clean_oct = highest_clean_pair
            clean_chart_labels.append(f'"{tier_meta[tier]["vcpu"]} vCPU {clean_cps} CPS"')
            clean_fpm_values.append(clean_fpm["p50_ms"])
            clean_oct_values.append(clean_oct["p50_ms"])

    if clean_chart_labels:
        fpm_floats = [float(v) for v in clean_fpm_values]
        oct_floats = [float(v) for v in clean_oct_values]
        max_y = max(fpm_floats + oct_floats)
        y_top = int(((max_y // 50) + 2) * 50)
        can_overlay = all(f >= o for f, o in zip(fpm_floats, oct_floats))
        if can_overlay:
            lines.extend(
                [
                    "",
                    "#### Non-Dropping Call Setup Latency (100% Completed for Both Handlers)",
                    "",
                    "```mermaid",
                    "---",
                    "config:",
                    "  themeVariables:",
                    "    xyChart:",
                    '      plotColorPalette: "#2563eb, #f97316"',
                    "---",
                    "xychart-beta",
                    '    title "Non-Dropping Median Call Setup Latency: Octane vs. PHP-FPM (ms, lower is better)"',
                    f"    x-axis [{', '.join(clean_chart_labels)}]",
                    f'    y-axis "Median Setup Latency (ms)" 0 --> {y_top}',
                    f"    bar [{', '.join(clean_fpm_values)}]",
                    f"    bar [{', '.join(clean_oct_values)}]",
                    "```",
                    "",
                    "🟧 **Laravel Octane** *(median latency)* · 🟦 **PHP-FPM** *(additional latency; full bar height = PHP-FPM total)*",
                ]
            )
        else:
            paired_labels = []
            paired_vals = []
            for lbl, fv, ov in zip(clean_chart_labels, clean_fpm_values, clean_oct_values):
                raw = lbl.strip('"')
                paired_labels.extend([f'"{raw} FPM"', f'"{raw} Octane"'])
                paired_vals.extend([fv, ov])
            lines.extend(
                [
                    "",
                    "#### Non-Dropping Call Setup Latency (100% Completed for Both Handlers)",
                    "",
                    "```mermaid",
                    "xychart-beta",
                    '    title "Non-Dropping Median Call Setup Latency: PHP-FPM vs. Octane (ms, lower is better)"',
                    f"    x-axis [{', '.join(paired_labels)}]",
                    f'    y-axis "Median Setup Latency (ms)" 0 --> {y_top}',
                    f"    bar [{', '.join(paired_vals)}]",
                    "```",
                ]
            )

    lines.append("")
    return "\n".join(lines)


def main() -> int:
    matrix = load_matrix()
    std = matrix["standard_comparison"]

    parser = argparse.ArgumentParser(
        description="Validate cross-tier consistency and consolidate one TallPBX comparison series."
    )
    parser.add_argument("series_dir", help="Path to load-testing/results/<series>")
    parser.add_argument(
        "--tiers",
        default=",".join(std["tiers"]),
        help=f"Comma-separated required tiers (default: {','.join(std['tiers'])})",
    )
    parser.add_argument("--csv-out", help="Write standardized consolidated comparison CSV to PATH")
    parser.add_argument("--markdown-out", help="Write ready-to-publish Markdown tables + charts to PATH")
    parser.add_argument(
        "--allow-incomplete",
        action="store_true",
        help="Emit output with warnings instead of exiting non-zero on missing tiers/cells",
    )
    args = parser.parse_args()

    series_dir = Path(args.series_dir).resolve()
    series_name = series_dir.name
    required_tiers = [t.strip() for t in args.tiers.split(",") if t.strip()]
    required_variants: list[str] = list(std["variants"])
    xml_profiles: list[str] = list(std["xml_profiles"])
    xml_section: str = str(std["xml_section"])
    xml_ttl: str = str(std["xml_ttl"])
    xml_repeats: int = int(std["xml_repeats"])

    errors: list[str] = []
    commits: dict[str, str] = {}
    dates: list[str] = []
    csv_rows: list[dict[str, str]] = []

    for tier in required_tiers:
        if tier not in matrix["tiers"]:
            errors.append(f"unknown tier: {tier}")
            continue

        tier_dir = series_dir / tier
        if not tier_dir.is_dir():
            errors.append(f"missing tier directory: {tier_dir}")
            continue

        failures_log = tier_dir / "failures.log"
        if failures_log.is_file() and failures_log.read_text(encoding="utf-8").strip():
            errors.append(f"non-empty failures.log in {tier_dir}")

        manifest_path = tier_dir / "manifest.json"
        if not manifest_path.is_file():
            errors.append(f"missing manifest.json in {tier_dir}")
            continue

        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        build = manifest.get("build", {})
        commit = str(build.get("commit") or "")
        dirty = build.get("dirty")
        if len(commit) != 40:
            errors.append(f"invalid or missing 40-char build.commit in {manifest_path}")
        else:
            commits[tier] = commit
        if dirty is not False:
            errors.append(f"dirty or unknown build state in {manifest_path} (dirty={dirty})")

        recorded_at = str(manifest.get("recorded_at") or "")
        date_str = recorded_at[:10] if len(recorded_at) >= 10 else ""
        if date_str:
            dates.append(date_str)

        fs_db = (
            manifest.get("configuration", {})
            .get("freeswitch", {})
            .get("runtime_db", {})
            .get("mode", "sqlite")
        )
        vcpu = int(matrix["tiers"][tier]["vcpu"])
        ram_mb = int(matrix["tiers"][tier]["ram_mb"])

        sip_rates_by_variant: dict[str, list[int]] = {}

        for variant in required_variants:
            vdir = find_variant_dir(tier_dir, variant)
            if vdir is None:
                errors.append(f"missing variant directory for {tier}/{variant}")
                continue

            for profile in xml_profiles:
                summary, xml_errs = summarize_xml_profile(vdir, profile, xml_ttl, xml_section, xml_repeats)
                errors.extend(xml_errs)
                if summary:
                    csv_rows.append(
                        {
                            "series": series_name,
                            "date": date_str,
                            "git_commit": commit,
                            "tier": tier,
                            "vcpu": str(vcpu),
                            "ram_mb": str(ram_mb),
                            "variant": variant,
                            "phase": "xml",
                            "workload": profile,
                            "rate_cps": "",
                            "offered": str(summary["offered"]),
                            "completed": str(summary["completed"]),
                            "failed": str(summary["failed"]),
                            "completion_pct": fmt(summary["completion_pct"]),
                            "rps": fmt(summary["rps"], 2),
                            "p50_ms": fmt(summary["p50_ms"]),
                            "p95_ms": fmt(summary["p95_ms"]),
                            "max_ms": fmt(summary["max_ms"]),
                            "teardown_p50_ms": "",
                            "teardown_p95_ms": "",
                            "teardown_max_ms": "",
                            "fs_db": str(fs_db),
                            "cache_policy": xml_ttl,
                        }
                    )

            ladder_rows, sip_errs = parse_ladder_csv(vdir)
            errors.extend(sip_errs)
            sip_rates_by_variant[variant] = [r["cps"] for r in ladder_rows]

            for lrow in ladder_rows:
                csv_rows.append(
                    {
                        "series": series_name,
                        "date": date_str,
                        "git_commit": commit,
                        "tier": tier,
                        "vcpu": str(vcpu),
                        "ram_mb": str(ram_mb),
                        "variant": variant,
                        "phase": "sip",
                        "workload": f"sip-60s-{lrow['cps']}cps",
                        "rate_cps": str(lrow["cps"]),
                        "offered": str(lrow["offered"]),
                        "completed": str(lrow["completed"]),
                        "failed": str(lrow["failed"]),
                        "completion_pct": fmt(lrow["completion_pct"]),
                        "rps": "",
                        "p50_ms": fmt(lrow["setup_p50_ms"]),
                        "p95_ms": fmt(lrow["setup_p95_ms"]),
                        "max_ms": fmt(lrow["setup_max_ms"]),
                        "teardown_p50_ms": fmt(lrow["teardown_p50_ms"]),
                        "teardown_p95_ms": fmt(lrow["teardown_p95_ms"]),
                        "teardown_max_ms": fmt(lrow["teardown_max_ms"]),
                        "fs_db": str(fs_db),
                        "cache_policy": xml_ttl,
                    }
                )

        if len(sip_rates_by_variant) == len(required_variants):
            unique_rate_sets = {tuple(rates) for rates in sip_rates_by_variant.values()}
            if len(unique_rate_sets) > 1:
                errors.append(
                    f"mismatched SIP ladder CPS rates between handlers in {tier}: {sip_rates_by_variant}"
                )

    unique_commits = set(commits.values())
    if len(unique_commits) > 1:
        errors.append(f"tiers ran on different git commits (cannot combine into one clean series): {commits}")

    if errors:
        for err in errors:
            print(f"ERROR: {err}", file=sys.stderr)
        if not args.allow_incomplete:
            return 1

    if args.csv_out:
        out_path = Path(args.csv_out)
        out_path.parent.mkdir(parents=True, exist_ok=True)
        with out_path.open("w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=CSV_COLUMNS)
            writer.writeheader()
            writer.writerows(csv_rows)
        print(f"Wrote {len(csv_rows)} consolidated rows to {out_path}")

    if args.markdown_out:
        md_path = Path(args.markdown_out)
        md_path.parent.mkdir(parents=True, exist_ok=True)
        commit_display = next(iter(unique_commits), "unknown")
        date_display = max(dates) if dates else "undated"
        md_path.write_text(
            build_markdown(series_name, commit_display, date_display, required_tiers, matrix["tiers"], csv_rows),
            encoding="utf-8",
        )
        print(f"Wrote Markdown comparison tables to {md_path}")

    if not args.csv_out and not args.markdown_out:
        print(f"Validated {series_name}: {len(required_tiers)} tiers, {len(csv_rows)} rows, 0 errors.")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
