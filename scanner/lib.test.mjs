import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
	MAX_ISSUES, toIssues, attachText, isChallenge, isSiteUrl, normalizeUrl, selectPages, cacheBust,
	resultPayload, failurePayload, makeClient, runScan, videoFacts, dropDescribedSilentVideos, retryNotReady,
} from './lib.mjs';

const SITE = 'https://library.wheatoncollege.edu';
const page = (id, path) => ({ id, url: `${SITE}${path}`, type: 'page', title: `Page ${id}` });

const axe = {
	violations: [{
		id: 'image-alt', help: 'Images must have alternative text', helpUrl: 'https://dequeuniversity.com/rules/axe/4.11/image-alt',
		nodes: [{ target: ['#main > img'], html: '<img src="a.png">' }],
	}],
	incomplete: [{
		id: 'color-contrast', help: 'Elements must meet minimum color contrast ratio thresholds', helpUrl: 'https://dequeuniversity.com/rules/axe/4.11/color-contrast',
		nodes: [
			{ target: ['p.lede'], html: '<p class="lede">Hi</p>' },
			{ target: [['iframe#x', '#inner']], html: '<span id="inner">x</span>' },
		],
	}],
	passes: [{ id: 'html-has-lang', nodes: [{ target: ['html'], html: '<html>' }] }],
};

test('toIssues: violations are errors, incomplete are warnings, passes ignored', () => {
	const issues = toIssues(axe);
	assert.deepEqual(issues.map(i => [i.type, i.code, i.selector]), [
		['error', 'image-alt', '#main > img'],
		['warning', 'color-contrast', 'p.lede'],
		['warning', 'color-contrast', 'iframe#x #inner'],
	]);
	assert.deepEqual(issues[0], {
		type: 'error', code: 'image-alt', message: 'Images must have alternative text',
		selector: '#main > img', context: '<img src="a.png">', text: '',
		help_url: 'https://dequeuniversity.com/rules/axe/4.11/image-alt',
	});
});

test('toIssues: clips long context and tolerates missing lists', () => {
	const long = { violations: [{ id: 'x', help: 'h', helpUrl: '', nodes: [{ target: ['a'], html: 'y'.repeat(5000) }] }] };
	assert.equal(toIssues(long)[0].context.length, 2000);
	assert.deepEqual(toIssues({}), []);
});

test('isChallenge: Cloudflare challenge pages, not the JS Detections script', () => {
	assert.equal(isChallenge({ status: 403, title: 'Just a moment...', html: '' }), true);
	assert.equal(isChallenge({ status: 403, title: 'Attention Required! | Cloudflare', html: '' }), true);
	assert.equal(isChallenge({ status: 200, title: 'x', html: '<script>window._cf_chl_opt={}</script>' }), true);
	assert.equal(isChallenge({ status: 200, title: 'Library', html: '<script src="/cdn-cgi/challenge-platform/scripts/jsd/main.js"></script>' }), false);
});

test('isSiteUrl: same origin only', () => {
	assert.equal(isSiteUrl(`${SITE}/about/`, SITE), true);
	assert.equal(isSiteUrl('https://library.wheatoncollege.edu.evil.com/', SITE), false);
	assert.equal(isSiteUrl('http://library.wheatoncollege.edu/about/', SITE), false);
	assert.equal(isSiteUrl(`https://user:pw@library.wheatoncollege.edu/`, SITE), false);
	assert.equal(isSiteUrl('not a url', SITE), false);
});

test('normalizeUrl: trailing slash, lowercase host, keeps query', () => {
	assert.equal(normalizeUrl('https://Library.WheatonCollege.edu/about'), `${SITE}/about/`);
	assert.equal(normalizeUrl(`${SITE}/?page_id=5`), `${SITE}/?page_id=5`);
	assert.equal(normalizeUrl('nope'), null);
});

test('selectPages: all, one, or none', () => {
	const pages = [page(1, '/a/'), page(2, '/b/')];
	assert.equal(selectPages(pages, '').length, 2);
	assert.deepEqual(selectPages(pages, `${SITE}/b`).map(p => p.id), [2]);
	assert.throws(() => selectPages(pages, `${SITE}/c/`), /No published page matches/);
	assert.throws(() => selectPages({ code: 'x' }, ''), /not an array/);
});

test('payloads: shape, issue cap, error clip', () => {
	const p = page(7, '/x/');
	const many = Array.from({ length: MAX_ISSUES + 5 }, () => ({ type: 'error' }));
	const r = resultPayload(p, many, '2026-09-30T10:00:00.000Z');
	assert.equal(r.post_id, 7);
	assert.equal(r.url, `${SITE}/x/`);
	assert.equal(r.issues.length, MAX_ISSUES);
	const f = failurePayload(p, 'e'.repeat(900), 't');
	assert.deepEqual(Object.keys(f), ['post_id', 'url', 'scanned_at', 'error']);
	assert.equal(f.error.length, 500);
	assert.equal(failurePayload(p, '', 't').error, 'Unknown error');
});

test('makeClient: bearer header, JSON body, readable errors', async () => {
	const calls = [];
	const fetchFn = async (url, init) => {
		calls.push({ url, init });
		if (url.endsWith('/results')) return new Response('{"code":"wpa11y_bad_post"}', { status: 404 });
		return new Response('[]', { status: 200 });
	};
	const c = makeClient({ site: `${SITE}/`, secret: 's3cret', fetchFn });
	assert.deepEqual(await c.pages(), []);
	assert.equal(calls[0].url, `${SITE}/wp-json/wpa11y/v1/pages`);
	assert.equal(calls[0].init.headers.Authorization, 'Bearer s3cret');
	await assert.rejects(c.postResult({ a: 1 }), /POST \/results → 404/);
	assert.equal(calls[1].init.body, '{"a":1}');
	await c.scanComplete(3);
	assert.equal(calls[2].init.body, '{"scanned":3}');
});

function fakeClient(pages, failUpload = () => false) {
	const posted = [];
	let completed = null;
	return {
		posted, get completed() { return completed; },
		pages: async () => pages,
		postResult: async body => { if (failUpload(body)) throw new Error('400'); posted.push(body); },
		scanComplete: async n => { completed = n; },
	};
}
const quiet = { log() {}, error() {} };
const now = () => new Date('2026-09-30T10:00:00Z');

test('runScan: one failure does not stop the run', async () => {
	const client = fakeClient([page(1, '/a/'), page(2, '/b/'), page(3, '/c/')]);
	const scan = async url => { if (url.endsWith('/b/')) throw new Error('Navigation timeout'); return []; };
	const summary = await runScan({ client, scan, log: quiet, now });
	assert.deepEqual(summary, { scanned: 3, failed: 1 });
	assert.deepEqual(client.posted.map(b => b.post_id), [1, 2, 3]);
	assert.equal(client.posted[1].error, 'Navigation timeout');
	assert.equal(client.completed, 3);
});

test('runScan: upload failure counts as failed and continues', async () => {
	const client = fakeClient([page(1, '/a/'), page(2, '/b/')], body => body.post_id === 1);
	const summary = await runScan({ client, scan: async () => [], log: quiet, now });
	assert.deepEqual(summary, { scanned: 2, failed: 1 });
	assert.deepEqual(client.posted.map(b => b.post_id), [2]);
});

test('runScan: single URL scans one page and skips scan-complete', async () => {
	const client = fakeClient([page(1, '/a/'), page(2, '/b/')]);
	const summary = await runScan({ client, scan: async () => [], onlyUrl: `${SITE}/b/`, log: quiet, now });
	assert.deepEqual(summary, { scanned: 1, failed: 0 });
	assert.equal(client.completed, null);
});

test('toIssues: never splits an emoji at the clip boundary', () => {
	const html = 'a'.repeat(1999) + '😀tail';
	const ctx = toIssues({ violations: [{ id: 'x', help: 'h', helpUrl: '', nodes: [{ target: ['a'], html }] }] })[0].context;
	assert.equal(ctx, 'a'.repeat(1999) + '😀');
	assert.doesNotThrow(() => JSON.parse(JSON.stringify(ctx)));
	assert.equal(/[\uD800-\uDBFF](?![\uDC00-\uDFFF])/.test(ctx), false);
});

test('attachText: adds each element\'s visible text, clipped and whitespace-collapsed', () => {
	const issues = toIssues(axe);
	const out = attachText(issues, ['', '  Hi\n  there ', 'x'.repeat(250) + '😀']);
	assert.deepEqual(out.map(i => i.text), ['', 'Hi there', 'x'.repeat(200)]);
	assert.equal(out[0].code, 'image-alt');
	assert.equal(attachText(issues, [])[1].text, '');
});

test('cacheBust: adds a wpa11y query so Cloudflare and WP Engine serve a fresh copy', () => {
	assert.equal(cacheBust(`${SITE}/about/`, 1700000000000), `${SITE}/about/?wpa11y=1700000000000`);
	assert.equal(cacheBust(`${SITE}/about/?p=2#top`, 5), `${SITE}/about/?p=2&wpa11y=5#top`);
	// Not utm_*: WP Engine leaves utm_ parameters out of its cache key.
	assert.ok(!cacheBust(`${SITE}/`, 1).includes('utm_'));
	assert.equal(cacheBust('not a url', 1), 'not a url');
});

// A stand-in for a DOM element: just what videoFacts reads.
const el = (tag, attrs = {}, muted = false) => ({
	tagName: tag, muted,
	hasAttribute: name => Object.prototype.hasOwnProperty.call(attrs, name),
	getAttribute: name => (Object.prototype.hasOwnProperty.call(attrs, name) ? attrs[name] : null),
});
const doc = texts => ({ getElementById: id => (id in texts ? { textContent: texts[id] } : null) });

test('videoFacts: silent means the muted attribute and muted playback', () => {
	assert.equal(videoFacts(el('VIDEO', { muted: '' }, true), doc({})).silent, true);
	// Foyer unmutes a slide whose sound is on, leaving the attribute behind.
	assert.equal(videoFacts(el('VIDEO', { muted: '' }, false), doc({})).silent, false);
	assert.equal(videoFacts(el('VIDEO', {}, true), doc({})).silent, false);
});

test('videoFacts: described by a non-blank aria-label or aria-describedby text', () => {
	assert.equal(videoFacts(el('VIDEO', { 'aria-label': 'Fall hours: 8am to midnight' }), doc({})).described, true);
	assert.equal(videoFacts(el('VIDEO', { 'aria-label': '   ' }), doc({})).described, false);
	assert.equal(videoFacts(el('VIDEO', { 'aria-describedby': 'gone d1' }), doc({ d1: ' Welcome ' })).described, true);
	assert.equal(videoFacts(el('VIDEO', { 'aria-describedby': 'd1' }), doc({ d1: ' ' })).described, false);
	assert.equal(videoFacts(el('VIDEO', {}), doc({})).described, false);
});

test('videoFacts: anything that is not a video, or missing, is neither', () => {
	assert.deepEqual(videoFacts(el('AUDIO', { muted: '', 'aria-label': 'x' }, true), doc({})), { silent: false, described: false });
	assert.deepEqual(videoFacts(null, doc({})), { silent: false, described: false });
});

test('dropDescribedSilentVideos: only video-caption on silent, described videos is dropped', () => {
	const issue = (code, selector) => ({ type: 'warning', code, selector });
	const issues = [issue('video-caption', 'v1'), issue('video-caption', 'v2'), issue('video-caption', 'v3'), issue('color-contrast', 'p')];
	const facts = [
		{ silent: true, described: true },   // muted with alt text: dropped
		{ silent: true, described: false },  // muted, no alt text: kept
		{ silent: false, described: true },  // has sound: kept
		{ silent: true, described: true },   // another rule: kept
	];
	assert.deepEqual(dropDescribedSilentVideos(issues, facts).map(i => i.selector), ['v2', 'v3', 'p']);
	assert.equal(dropDescribedSilentVideos(issues, []).length, 4);
});

test('retryNotReady: retries "Page/Frame is not ready", waiting first, then succeeds', async () => {
	let calls = 0;
	const waits = [];
	const out = await retryNotReady(async () => {
		calls++;
		if (calls < 3) throw new Error('Page/Frame is not ready');
		return 'ok';
	}, { wait: async n => { waits.push(n); } });
	assert.equal(out, 'ok');
	assert.equal(calls, 3);
	assert.deepEqual(waits, [1, 2]);
});

test('retryNotReady: gives up after the last try with the original error', async () => {
	let calls = 0;
	await assert.rejects(retryNotReady(async () => { calls++; throw new Error('Page/Frame is not ready'); }, { tries: 3, wait: async () => {} }), /not ready/);
	assert.equal(calls, 3);
});

test('retryNotReady: any other error fails at once', async () => {
	let calls = 0;
	await assert.rejects(retryNotReady(async () => { calls++; throw new Error('HTTP 500'); }, { wait: async () => {} }), /HTTP 500/);
	assert.equal(calls, 1);
});
