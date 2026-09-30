// Entry point for the GitHub Actions workflow: scans the site's published
// pages with axe and posts each page's result to the wpa11y plugin.
//   node scan.mjs                 full scan (or one page if WPA11Y_URL is set)
//   node scan.mjs --print <url>   scan one URL and print its issues; no WordPress
import puppeteer from 'puppeteer';
import { AxePuppeteer } from '@axe-core/puppeteer';
import { TAGS, EXCLUDE, isChallenge, isSiteUrl, makeClient, runScan, toIssues } from './lib.mjs';

async function scan(browser, url) {
	const page = await browser.newPage();
	try {
		await page.setViewport({ width: 1280, height: 1024 });
		const res = await page.goto(url, { waitUntil: 'networkidle2', timeout: 60000 });
		const status = res ? res.status() : 0;
		if (isChallenge({ status, title: await page.title(), html: await page.content() })) {
			throw new Error(`Cloudflare challenge page (HTTP ${status})`);
		}
		if (status >= 400) throw new Error(`HTTP ${status}`);
		let axe = new AxePuppeteer(page).withTags(TAGS);
		for (const selector of EXCLUDE) axe = axe.exclude(selector);
		return toIssues(await axe.analyze());
	} finally {
		await page.close();
	}
}

const printUrl = process.argv[2] === '--print' ? process.argv[3] : '';
const site = (process.env.WPA11Y_SITE || '').trim();
const secret = (process.env.WPA11Y_SECRET || '').trim();
const onlyUrl = (process.env.WPA11Y_URL || '').trim();

if (!printUrl) {
	if (!site || !secret) {
		console.error('WPA11Y_SITE and WPA11Y_SECRET must be set.');
		process.exit(2);
	}
	if (onlyUrl && !isSiteUrl(onlyUrl, site)) {
		console.error(`Refusing to scan ${onlyUrl}: it is not on ${site}.`);
		process.exit(2);
	}
}

const browser = await puppeteer.launch({ args: ['--no-sandbox', '--disable-setuid-sandbox'] });
let summary;
try {
	if (printUrl) {
		console.log(JSON.stringify(await scan(browser, printUrl), null, 2));
	} else {
		summary = await runScan({ client: makeClient({ site, secret }), scan: url => scan(browser, url), onlyUrl });
	}
} finally {
	await browser.close();
}
if (summary) {
	console.log(`Scanned ${summary.scanned} page(s); ${summary.failed} failed.`);
	if (summary.failed) process.exit(1);
}
