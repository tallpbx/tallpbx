---
name: tallpbx-documentation
description: Use when writing, restructuring, or reviewing TallPBX documentation, including the README, installation and operations guides, architecture, and published load-testing results.
---

# TallPBX documentation

Use [documentation-writer](../documentation-writer/SKILL.md) to choose an audience, purpose, and structure. Apply its outline review when the user asks to agree on a structure before drafting. Use [docs-sync-audit](../docs-sync-audit/SKILL.md) when checking whether documented behavior still matches the code, configuration, or benchmark evidence. Follow the repository's research and verification instructions.

## Write for the reader's goal

Assume readers are new to TallPBX and Laravel, even in the architecture guide. Lead with what the reader came to find. Explain product-specific terms in plain language on first use, then use the same names throughout.

- **README** introduces TallPBX, basic requirements, and how to get started. Link to setup, capacity results, and design details where readers need more.
- **Load testing results** help readers evaluate capacity and choose hardware. Put one readable comparison table near the top and link to dated reports and data for the full test conditions.
- **Installation and operations** explain the recommended setup, prerequisites, commands, and how to verify the outcome or return to the default.
- **Architecture** starts with what the components do and how a request travels through them. Introduce exact names, settings, and source paths when they help readers trace the design.

## Explain the XML handlers

At the first substantial discussion of the XML handlers in each document, explain what FreeSWITCH asks TallPBX for, such as account and call-routing information. Then describe the serving paths in plain language.

1. **PHP-FPM is the default.** It runs the PHP code that answers XML requests. TallPBX's fast handler loads only the telephony modules needed for the lookup and skips web panel setup.
2. **Laravel Octane is an optional performance mode.** It uses the same list of telephony modules and keeps Laravel loaded in memory between requests.
3. **The fast handler can fall back to the full application.** If it catches an application exception, it handles the same request through the standard Laravel entry point used by the web panel, in the same PHP-FPM worker.

Keep the port, binding order, and restart details where the reader needs them. FreeSWITCH falls back from Octane to the fast PHP-FPM handler when the optional worker is unavailable. With both XML endpoints already configured and loaded, starting or stopping the worker takes effect on subsequent lookups. Changing the endpoints in `xml_curl.conf.xml` requires a FreeSWITCH restart. [Operations guide](../../../docs/operations.md#octane-xml-worker-optional-performance-mode) · [Shared module list](../../../config/freeswitch.php) · [Fast PHP-FPM fallback](../../../public/xml-handler-fast.php).

## Publish benchmark evidence

Read the [load testing harness guide](../../../load-testing/README.md) before reasoning about a run. Tie every published capacity figure to a dated report or consolidated CSV. For call tests, show the hardware, software build and serving mode, workload, offered call rate, duration, and calls completed. Keep the conditions that materially affect comparison close to the figure and link to the full setup.

Label short smoke tests, sustained SIP runs, XML-only throughput, and projections clearly. When two runs differ, present the results with their conditions and verify the numbers against the source. Use the capacity reported for an older build or configuration only as dated evidence. Keep current figures in one place and link to it from summaries. When a full uniform comparison series passes `load-testing/scripts/tools/consolidate-series.py` across all standard tiers (`h1-1c1g`, `h2-2c2g`, `h4-4c8g`) on one clean commit SHA, replace older piecemeal multi-date tables in `docs/load-testing-results.md` with that single consistent dataset rather than cobbling together comparisons across dates, pools, or mismatched metrics.

## Upstream skills

The two bundled skills come from [GitHub Awesome Copilot](https://github.com/github/awesome-copilot/tree/f10fdb8b2660ce85c98f220b2f1ca24653768a5c/skills). Each copy includes the upstream MIT license. To update them, review a newer upstream revision and replace the corresponding skill files and bundled audit script together.
