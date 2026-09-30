# wpa11y

Site accessibility dashboard for WordPress editors. A daily scan on GitHub Actions checks every
published page with [axe-core](https://github.com/dequelabs/axe-core) (WCAG 2.0/2.1 A and AA) and
reports to the plugin. In wp-admin, anyone who can edit pages sees:

- **Accessibility → Overview** — every page with its error and warning counts, filterable and sortable.
- **Page detail** — errors and warnings grouped by rule, with the element, its HTML, and a link to
  how to fix it. **Rescan this page** reruns the check in about 1–2 minutes.
- **Accessibility → Dismissal log** — every dismissed warning with who, when and why; undo or restore.
- An **Accessibility** column on the Pages list (and other public post types).

**Errors** are definite failures and cannot be dismissed; they clear when fixed. **Warnings** are
things the checker could not decide; after a person reviews one they can dismiss it with a note. A
dismissal applies to that rule on that element: if the element changes, the warning comes back.

## Setup

1. Install the release zip: Plugins → Add New → Upload Plugin.
2. **Accessibility → Settings → Generate secret.** In GitHub (this repository → Settings → Secrets
   and variables → Actions) add:
   - `WPA11Y_SECRET` — the secret just shown;
   - `WPA11Y_SITE` — the site URL shown on the same screen.
3. Create a fine-grained personal access token limited to this repository with **Actions: Read and
   write**, and paste it under **Rescan (GitHub)** in Settings.
4. Run the first scan: GitHub → Actions → Accessibility scan → Run workflow.

GitHub pauses scheduled workflows in repositories with no activity for 60 days; re-enable the
workflow from the Actions tab if the daily scan stops.

## How it works

- `scanner/` — Node script run by `.github/workflows/scan.yml` (daily at 10:00 UTC, or one URL on
  demand). It gets the page list from `GET /wp-json/wpa11y/v1/pages`, scans each page with
  Puppeteer + axe, and posts results to `POST /wp-json/wpa11y/v1/results` with the secret.
- `wpa11y.php` — stores each page's latest result in post meta and dismissals in the
  `wp_wpa11y_dismissals` table, and renders the admin screens.

## Development

- Plugin tests: `php tests/run.php` (stub harness, no WordPress needed); `php -l wpa11y.php`.
- Scanner tests: `cd scanner && npm test`. Scan one page locally without WordPress:
  `node scan.mjs --print https://library.wheatoncollege.edu/`.
- Every commit to `main` is a release: bump `Version:` and `WPA11Y_VERSION`, add a changelog line,
  commit, push, then `bin/release.sh X.Y.Z "notes"` (add `--prerelease` for unfinished builds).

## Changelog

- **1.0.0** (2026-09-30) — First complete release: daily axe scan on GitHub Actions, overview, page detail with rescan, dismissable warnings with a log, Pages column.
- **0.10.0** (2026-09-30) — Work in progress, do not install: review fixes (timeout announcement, focus restore on refresh, emoji-safe clipping, token autofill, notes kept as typed).
- **0.9.0** (2026-09-30) — Work in progress, do not install: scanner entry point and daily scan workflow.
- **0.8.0** (2026-09-30) — Work in progress, do not install: scanner logic with axe result mapping and run loop.
- **0.7.0** (2026-09-30) — Work in progress, do not install: dismissal log with undo and restore.
- **0.6.0** (2026-09-30) — Work in progress, do not install: accessibility overview and list column.
- **0.5.0** (2026-09-30) — Work in progress, do not install: page detail with dismiss, undo and rescan.
- **0.4.0** (2026-09-30) — Work in progress, do not install: GitHub rescan dispatch and settings screen.
- **0.3.0** (2026-09-30) — Work in progress, do not install: scanner REST routes.
- **0.2.0** (2026-09-30) — Work in progress, do not install: dismissals table and issue sorting.
- **0.1.0** (2026-09-30) — Work in progress, do not install: plugin skeleton and result storage.

## License

GPL-2.0+
