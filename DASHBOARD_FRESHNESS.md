# Supervisor Dashboard Freshness

This repository has two aidevops-maintained dashboard issues with different
purposes:

- Issue #8 is the legacy queue health dashboard. It reports supervisor queue
  state and can remain unchanged when the repo has no active PRs, assigned
  issues, auto-dispatch work, or workers.
- Issue #17 is the current code audit routines dashboard. It is updated by the
  daily quality sweep and should be used for code-audit freshness checks.

When a freshness alert references issue #8, verify both surfaces before treating
the scheduler as broken:

```bash
gh issue view 8 --repo Ultimate-Multisite/ultimate-ai-plugin-translations \
  --json title,state,updatedAt,body
gh issue view 17 --repo Ultimate-Multisite/ultimate-ai-plugin-translations \
  --json title,state,updatedAt,body
```

Expected healthy evidence from `~/.aidevops/logs/stats.log` is an hourly
`[stats-wrapper] Finished` entry and a health-refresh line like:

```text
[stats] Health issue: skipping creation for Ultimate-Multisite/ultimate-ai-plugin-translations — no active PRs, assigned issues, auto-dispatch work, or workers
[stats] Health issues: updated 15 repo(s)
```

If issue #8 is stale but the log shows the skip line above and issue #17 has a
recent `Last sweep`, the scheduler is running; the stale queue dashboard is a
legacy/inactive-work signal rather than a plugin code defect.

## Issue #26 triage evidence

Issue #26 was generated from issue #8 after the legacy queue dashboard appeared
stale. The local scheduler evidence showed the stats wrapper was still running:

```text
[stats-wrapper] Finished at 2026-06-04T17:49:19Z
[stats] Health issue: skipping creation for Ultimate-Multisite/ultimate-ai-plugin-translations — no active PRs, assigned issues, auto-dispatch work, or workers
[stats] Health issues: updated 15 repo(s)
```

The dashboard issue also had a fresh body marker during triage:

```text
issue #8 updated_at=2026-06-04T16:15:40Z
has_last_refresh=true
```

Root cause: the freshness alert was based on the legacy queue dashboard's
previous timestamp, while the stats wrapper was healthy and the repo had no
active queue work to report. Treat this as a false-positive stale-dashboard
alert unless `stats.log` lacks recent `[stats-wrapper] Finished` entries or the
issue body no longer contains `last_refresh:`.

## Issue #28 triage evidence

Issue #28 repeated the same legacy queue-dashboard freshness alert. Triage on
2026-06-04 showed the scheduler was installed and continuing to refresh managed
repo health surfaces: `~/.aidevops/logs/stats.log` contained hourly starts and
finishes through 2026-06-04T18:49:15Z, followed by a 2026-06-04T19:35:20Z run
that refreshed the queue dashboard before a later stale-process cleanup.

```text
[stats-wrapper] Finished at 2026-06-04T18:49:15Z
[stats-wrapper] Starting at 2026-06-04T19:35:20Z
[stats] Health issue: skipping creation for Ultimate-Multisite/ultimate-multisite-support-tickets — no active PRs, assigned issues, auto-dispatch work, or workers
[stats-wrapper] Killing stale stats process 1561986 (803s)
```

Dashboard issue #8 was also fresh during triage and retained the required body
marker:

```text
issue #8 updated_at=2026-06-04T19:42:06Z
last_refresh: 2026-06-04T19:41:08Z
has_last_refresh=true
```

Root cause: issue #28 was generated from an earlier stale timestamp on the
legacy queue dashboard, but a subsequent stats-wrapper run refreshed issue #8
within the acceptance window. The scheduler was not missing; the alert should be
treated as resolved when issue #8 remains under 24 hours old and contains the
`last_refresh:` marker.
