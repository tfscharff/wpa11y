# wpa11y

Site accessibility dashboard for WordPress editors. A daily scan on GitHub Actions checks every
published page with [axe-core](https://github.com/dequelabs/axe-core) (WCAG 2.0/2.1 A and AA) and
reports to the plugin. In wp-admin, anyone who can edit pages sees:

- **Accessibility → Overview** — every page with its error and warning counts, filterable and sortable;
  each count links to that part of the page detail.
- **Page detail** — errors and warnings grouped by rule, with the element, its HTML, and a link to
  how to fix it. **Show in editor** opens the page in the block editor with the block that has the
  problem selected. **Rescan this page** reruns the check in about 1–2 minutes.
- **Accessibility → Dismissal log** — every dismissed warning with who, when and why; undo or restore.
- An **Accessibility** column on the Pages list (and other public post types).
- **Email notifications** (optional, Settings) — when a scan finds errors or warnings on a page that
  weren't there before and aren't dismissed, one email names the page and lists the new issues by
  rule. Send them to one address for every page, or to each page's owner with the
  [Sitemap plugin (wpshare)](https://github.com/tfscharff/wpshare/releases/latest), which lets you
  give each page an owner. wpa11y works the same without wpshare.

**Errors** are definite failures and cannot be dismissed; they clear when fixed. **Warnings** are
things the checker could not decide; after a person reviews one they can dismiss it with a note. When a rule
repeats, **Dismiss all N…** on its group dismisses every listed warning with one note (still one log
row each, undone one at a time). A dismissal applies to that rule on that element: if the element changes, the warning comes back.

## Setup

1. Install the release zip: Plugins → Add New → Upload Plugin.
2. **Accessibility → Settings → Generate secret.** In GitHub (this repository → Settings → Secrets
   and variables → Actions) add:
   - `WPA11Y_SECRET` — the secret just shown;
   - `WPA11Y_SITE` — the site URL shown on the same screen.
3. Create a fine-grained personal access token limited to this repository with **Actions: Read and
   write**, and paste it under **Rescan (GitHub)** in Settings.
4. Run the first scan: GitHub → Actions → Accessibility scan → Run workflow.
5. Optional: under **Email notifications** in Settings, choose one address or page owners. Nothing
   is emailed for the site's first full scan, so a new install doesn't send a backlog.

GitHub pauses scheduled workflows in repositories with no activity for 60 days; re-enable the
workflow from the Actions tab if the daily scan stops.

## How it works

- `scanner/` — Node script run by `.github/workflows/scan.yml` (daily at 9:17 UTC, or one URL on
  demand). It gets the page list from `GET /wp-json/wpa11y/v1/pages`, scans each page with
  Puppeteer + axe, and posts results to `POST /wp-json/wpa11y/v1/results` with the secret.
- `wpa11y.php` — stores each page's latest result in post meta and dismissals in the
  `wp_wpa11y_dismissals` table, and renders the admin screens.

## Development

- Plugin tests: `php tests/run.php` (stub harness, no WordPress needed); `php -l wpa11y.php`.
- Editor-script tests: `node --test "tests/js/*.test.mjs"`.
- Scanner tests: `cd scanner && npm test`. Scan one page locally without WordPress:
  `node scan.mjs --print https://library.wheatoncollege.edu/`.
- Every commit to `main` is a release: bump `Version:` and `WPA11Y_VERSION`, add a changelog line,
  commit, push, then `bin/release.sh X.Y.Z "notes"` (add `--prerelease` for unfinished builds).

## Changelog

- **1.5.0** (2026-10-28) — Email notifications (Settings → Email notifications): when a scan finds new, undismissed errors or warnings on a page, one plain-text email names the page, lists the new issues by rule and links to its detail. Send to one address, or to each page's owner through the [Sitemap plugin (wpshare)](https://github.com/tfscharff/wpshare/releases/latest); without wpshare, wpa11y works as before and Settings says where to get it. Unchanged rescans, failed scans and the site's first scan send nothing.
- **1.4.0** (2026-10-28) — Page detail: a warning group with two or more items has **Dismiss all N…**, which dismisses every warning listed in that rule group with one note. Each still gets its own log row and Undo; ones already dismissed are skipped, and if the page is out of date with the latest scan nothing is dismissed. Floor Maps alone had 85 identical color-contrast reviews.
- **1.3.3** (2026-10-15) — The scanner waits 3 seconds between pages, and when Cloudflare answers with its rate-limit challenge (HTTP 429) it backs off 1, 2, then 4 minutes and retries that page (up to 20 minutes of waiting per run). The 2026-10-15 daily scan lost 25 of 39 pages to that challenge.
- **1.3.2** (2026-10-07) — The daily scan is scheduled for 9:17 UTC instead of 10:00. GitHub delays scheduled runs at the top of the hour the most; the 10:00 scan was starting 4–9 hours late.
- **1.3.1** (2026-10-06) — The scanner retries a page up to twice when axe reports "Page/Frame is not ready" (seen now and then on the signage pages), instead of failing the whole daily scan.
- **1.3.0** (2026-10-06) — The scanner no longer asks for captions on a silent video that has a text alternative: a `<video>` that is muted (attribute and playback) and has a non-blank `aria-label` or `aria-describedby` text. Silent videos without one, and videos with sound, are still flagged.
- **1.2.2** (2026-09-30) — Settings: the note beside **Replace secret** now says what replacing would do, instead of reading like the scan is currently failing.
- **1.2.1** (2026-09-30) — The scanner loads each page with a `?wpa11y=<time>` query so Cloudflare and WP Engine caches can't hand it an old copy; a rescan right after a fix now sees the fix.
- **1.2.0** (2026-09-30) — Show in editor: each error and warning opens the block editor with the block that has the problem selected. The scanner now records each element's text so the block can be found.
- **1.1.0** (2026-09-30) — Overview error and warning counts link straight to that section of the page's detail.
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
