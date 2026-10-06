// Pure logic for the wpa11y scanner. scan.mjs wires it to Puppeteer and axe.

export const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

// Screen-reader-only text is never seen, so its contrast results are noise.
// Cloudflare's JS Detections frame is a hidden 1x1 iframe screen readers skip.
export const EXCLUDE = ['.screen-reader-text', '.sr-only', 'body > iframe[style*="visibility: hidden"]'];

export const MAX_ISSUES = 1000;
const LIMITS = { code: 100, message: 500, selector: 1000, context: 2000, help_url: 500, error: 500, text: 200 };

// Counts code points, so an emoji is never cut into a lone surrogate (PHP's json_decode rejects those).
const clip = (value, max) => Array.from(String(value ?? '')).slice(0, max).join('');

// axe targets are strings, or nested arrays for iframes and shadow roots.
const targetToSelector = target =>
	Array.isArray(target) ? target.flat(Infinity).join(' ') : String(target ?? '');

// axe "violations" are definite failures (errors); "incomplete" means axe
// could not decide and a person must review (warnings).
export function toIssues(axeResults) {
	const issues = [];
	const add = (type, rules) => {
		for (const rule of rules ?? []) {
			for (const node of rule.nodes ?? []) {
				issues.push({
					type,
					code: clip(rule.id, LIMITS.code),
					message: clip(rule.help, LIMITS.message),
					selector: clip(targetToSelector(node.target), LIMITS.selector),
					context: clip(node.html, LIMITS.context),
					help_url: clip(rule.helpUrl, LIMITS.help_url),
					text: '',
				});
			}
		}
	};
	add('error', axeResults.violations);
	add('warning', axeResults.incomplete);
	return issues;
}

// axe keeps only the opening tag of a large element, so the element's own text
// (read from the page, in issue order) is what lets the plugin find its block.
export function attachText(issues, texts) {
	return issues.map((issue, n) => ({
		...issue,
		text: clip(String(texts[n] ?? '').replace(/\s+/g, ' ').trim(), LIMITS.text),
	}));
}

// A silent video has no audio to caption; it needs a text alternative instead
// (WCAG 1.2.1). Runs in the page via scan.mjs, so it must stay self-contained.
// Silent = the muted attribute and muted playback (Foyer unmutes a slide whose
// sound is on). Described = a non-blank aria-label or aria-describedby text.
export function videoFacts(el, doc) {
	if (!el || String(el.tagName).toUpperCase() !== 'VIDEO') return { silent: false, described: false };
	const silent = el.hasAttribute('muted') && el.muted === true;
	const label = String(el.getAttribute('aria-label') || '').trim();
	const ids = String(el.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
	const described = label !== '' || ids.some(id => {
		const target = doc.getElementById(id);
		return !!target && String(target.textContent).trim() !== '';
	});
	return { silent, described };
}

// axe asks a person to check every <video> for captions; a silent video with a
// text alternative has already passed that check. facts line up with issues.
export function dropDescribedSilentVideos(issues, facts) {
	return issues.filter((issue, n) => !(issue.code === 'video-caption' && facts[n] && facts[n].silent && facts[n].described));
}

// axe refuses to start ("Page/Frame is not ready") if the page doesn't answer
// within a second or is mid-navigation. It happens now and then on the signage
// pages and passes on the next try, so wait and retry that one error; any
// other error fails at once. wait(n) is called before retry n.
export async function retryNotReady(fn, { tries = 3, wait }) {
	for (let n = 1; ; n++) {
		try {
			return await fn();
		} catch (e) {
			if (n >= tries || !/Page\/Frame is not ready/.test(String(e && e.message))) throw e;
			await wait(n);
		}
	}
}

// Every page carries Cloudflare's JS Detections script (challenge-platform/…/jsd),
// so only the interstitial's title or its _cf_chl_opt config counts as a challenge.
export function isChallenge({ title, html }) {
	if (/^(just a moment|attention required)/i.test(String(title ?? '').trim())) return true;
	return String(html ?? '').includes('_cf_chl_opt');
}

export function isSiteUrl(url, site) {
	try {
		const u = new URL(url);
		return u.origin === new URL(site).origin && !u.username && !u.password;
	} catch {
		return false;
	}
}

// The URL actually loaded for a scan. Cloudflare and WP Engine cache pages and page saves don't
// purge them, so without this a rescan right after a fix can check the old copy. Each distinct
// query is a cache miss. Not utm_*: WP Engine leaves those out of its cache key. Results are still
// stored under the page's real URL.
export function cacheBust(url, now = Date.now()) {
	try {
		const u = new URL(url);
		u.searchParams.set('wpa11y', String(now));
		return u.toString();
	} catch {
		return url;
	}
}

// Same rules as the plugin's wpa11y_normalize_url().
export function normalizeUrl(url) {
	try {
		const u = new URL(url);
		const path = u.pathname.endsWith('/') ? u.pathname : `${u.pathname}/`;
		return `${u.protocol}//${u.host.toLowerCase()}${path}${u.search}`;
	} catch {
		return null;
	}
}

export function selectPages(pages, onlyUrl) {
	if (!Array.isArray(pages)) throw new Error('Page list from WordPress was not an array');
	if (!onlyUrl) return pages;
	const want = normalizeUrl(onlyUrl);
	const match = pages.filter(p => normalizeUrl(p.url) === want);
	if (!match.length) throw new Error(`No published page matches ${onlyUrl}`);
	return match;
}

export const resultPayload = (page, issues, scannedAt) => ({
	post_id: page.id, url: page.url, scanned_at: scannedAt, issues: issues.slice(0, MAX_ISSUES),
});

export const failurePayload = (page, message, scannedAt) => ({
	post_id: page.id, url: page.url, scanned_at: scannedAt, error: clip(message || 'Unknown error', LIMITS.error),
});

export function makeClient({ site, secret, fetchFn = fetch }) {
	const base = `${site.replace(/\/+$/, '')}/wp-json/wpa11y/v1`;
	const headers = {
		Authorization: `Bearer ${secret}`,
		'Content-Type': 'application/json',
		'User-Agent': 'wpa11y-scanner',
	};
	async function call(method, path, body) {
		const res = await fetchFn(base + path, {
			method, headers, body: body === undefined ? undefined : JSON.stringify(body),
		});
		const text = await res.text();
		if (!res.ok) throw new Error(`${method} ${path} → ${res.status}: ${text.slice(0, 300)}`);
		return text ? JSON.parse(text) : null;
	}
	return {
		pages: () => call('GET', '/pages'),
		postResult: body => call('POST', '/results', body),
		scanComplete: scanned => call('POST', '/scan-complete', { scanned }),
	};
}

// Scans pages one at a time. A page that fails to scan is still reported (as
// an error) so the plugin can say so; nothing stops the rest of the run.
export async function runScan({ client, scan, onlyUrl = '', log = console, now = () => new Date() }) {
	const pages = selectPages(await client.pages(), onlyUrl);
	let failed = 0;
	for (const page of pages) {
		const scannedAt = now().toISOString();
		let ok = true;
		let body;
		try {
			body = resultPayload(page, await scan(page.url), scannedAt);
			log.log(`${page.url}: ${body.issues.length} issue(s)`);
		} catch (err) {
			ok = false;
			body = failurePayload(page, err.message, scannedAt);
			log.error(`${page.url}: scan failed: ${err.message}`);
		}
		try {
			await client.postResult(body);
		} catch (err) {
			ok = false;
			log.error(`${page.url}: upload failed: ${err.message}`);
		}
		if (!ok) failed++;
	}
	if (!onlyUrl) await client.scanComplete(pages.length);
	return { scanned: pages.length, failed };
}
