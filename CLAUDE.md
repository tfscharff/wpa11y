# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

**wpa11y** — a WordPress plugin (not yet written) that runs pa11y-style accessibility checks from
inside the WordPress editor, so staff see problems on the page they are editing, before and after
publishing. Repo: https://github.com/tfscharff/wpa11y (public, GPLv2, like the sibling plugins).

Status (2026-09-30): v1.0.0 built from `docs/superpowers/specs/2026-09-30-wpa11y-design.md` and
`docs/superpowers/plans/2026-09-30-wpa11y.md`. Scans run on GitHub Actions with axe-core (not
HTML_CodeSniffer); the local pa11y-dashboard is being retired. Every commit to `main` is a SemVer
release via `bin/release.sh` (see README "Development").

## Background the user has already given

- Target site: library.wheatoncollege.edu (WordPress on WP Engine behind Cloudflare). Theme repo:
  `C:\Users\thoma\Documents\wheatonlibrary`. That folder **live-syncs to production**, so never put
  plugin files, notes or tests there.
- This replaces the Equalize Digital **Accessibility Checker** plugin. The user's complaints about it:
  its emails don't say which page has the issue, and "needs review" items can't be dismissed
  permanently. Both must be solved here.
- The user already runs **pa11y-dashboard** locally at `C:\Users\thoma\Documents\pa11y\`:
  - dashboard at http://localhost:4000 and webservice API at http://127.0.0.1:3000, both bound to
    this PC only;
  - the scan uses the WCAG2AA standard with the HTML_CodeSniffer runner;
  - `sync\sync-pages.mjs` mirrors published pages into tasks.

  Its tuning is the baseline to match, so results in the editor agree with the dashboard:
  - `hideElements: '.screen-reader-text, .sr-only, body > iframe[style*="visibility: hidden"]'`,
    which covers screen-reader-only text and Cloudflare's hidden 1×1 JS Detections frame;
  - ignore `WCAG2AA.Principle1.Guideline1_3.1_3_1.H48` everywhere, because every paragraph with two
    or more links is prose on this site;
  - ignore `H42` only on a few pages that have italic quotations or a bold sentence;
  - notices are left as-is: they're manual-check reminders, not findings.

## Open questions to settle first (do not assume answers)

1. **"Site editor" or block editor?** The live theme is a *classic* theme (underscores-based, no
   `theme.json`), so WordPress's Site Editor (full-site editing) isn't available on this site. The
   user most likely means the block (post/page) editor. Confirm.
2. **Where do the checks run?** pa11y itself is Node plus headless Chrome, and cannot run in PHP on
   WP Engine. The realistic options are:
   - run pa11y's own rule engine, HTML_CodeSniffer, in the editor's browser against the rendered
     page or preview. This uses the same rules and codes as the dashboard.
   - call a pa11y-webservice. The user's is localhost-only; reaching it from an https wp-admin page
     raises CORS and Private Network Access problems, and it isn't reachable by other staff.
   - use a hosted scanner.

   Trade-offs to cover: other staff (not only the user's PC), and scanning the *rendered* page (theme
   CSS matters for contrast) rather than raw block markup.
3. **Dismissals:** where they're stored (post meta, or an option keyed by code + selector/context),
   who can dismiss, and whether they sync with the dashboard's ignore rules.
4. **Neighbors:** in-editor checkers already exist:
   - Editoria11y;
   - Block Accessibility Checks;
   - Equalize Digital, the plugin being replaced;
   - NC State's axe-based a11y helper.

   None runs pa11y/HTML_CodeSniffer with this site's tuning. Say plainly if one of them already
   covers the need.

## Conventions (from the sibling plugins wphours, wprooms, wpmeet, in `C:\Users\thoma\Documents\`)

- Plain PHP and vanilla JS. No build step unless the design truly needs one; say so if it does. The
  siblings are single-file plugins with ES5-style JS.
- Pass config to inline scripts via `wp_json_encode()`, never string concatenation. Check
  capabilities and nonces on every REST route.
- The plugin's own UI must meet **WCAG 2.1 AA** (the site's bar), including keyboard use, focus
  visibility and screen-reader announcements.
- **Release workflow** (same as wphours and wprooms):
  1. Bump `Version:` (SemVer).
  2. Add a `## Changelog` entry to README.md.
  3. Commit and push to `main`.
  4. Run `gh release create vX.Y.Z` with a zip whose top-level folder is the plugin slug.

  The user installs the zip via wp-admin → Plugins → Add New → Upload → Replace current. Plugins
  are never live-synced.
- Commit and push after every change. Use concise imperative commit messages. Edit locally; never
  edit through the GitHub API.

## Tooling on this PC

- PHP CLI: `C:\Users\thoma\bin\php\php.exe` (on PATH). Run `php -l` on every PHP file. Unit-test
  with small stub harnesses (see `C:\Users\thoma\Documents\wheatonlibrary-specs\tests\` for the
  pattern). There's no local WordPress install.
- Node is on PATH. `C:\Users\thoma\Documents\pa11y\pa11y-dashboard\node_modules\` contains pa11y,
  HTML_CodeSniffer (`@pa11y\html_codesniffer\build\HTMLCS.js`) and Puppeteer. The Chrome it uses needs `PUPPETEER_CACHE_DIR=C:\Users\thoma\Documents\pa11y\chrome`.
- Verify UI in the real editor on the live site only after the user installs a release. Opening
  pages in the block editor can autosave, so check revisions after any editor visit.
