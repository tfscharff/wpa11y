// Tests for the block-finding logic in assets/editor.js. Run: node --test tests/js/
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const find = createRequire(import.meta.url)('../../assets/editor.js');
const contentOf = block => block.content;

const li = (id, text) => ({ clientId: id, name: 'core/list-item', content: `<li>${text}</li>`, innerBlocks: [] });
const list = {
	clientId: 'list', name: 'core/list',
	content: '<ul class="wp-block-list"><li>Physical condition: The item is moldy.</li>\n<li>Additional copies: Duplicated items.</li></ul>',
	innerBlocks: [li('li1', 'Physical condition: The item is moldy.'), li('li2', 'Additional copies: Duplicated items.')],
};
const image = { clientId: 'img', name: 'core/image', content: '<figure class="wp-block-image"><img src="https://library.wheatoncollege.edu/a.png" alt=""/></figure>', innerBlocks: [] };
const quote = { clientId: 'para', name: 'core/paragraph', content: "<p>It's the library's \"best\" room -- open late...</p>", innerBlocks: [] };
const group = {
	clientId: 'group', name: 'core/group',
	content: list.content + image.content + quote.content,
	innerBlocks: [{ clientId: 'h', name: 'core/heading', content: '<h2>Deaccession</h2>', innerBlocks: [] }, list, image, quote],
};
const blocks = [group];

test('normalize: tags, comments, entities, smart punctuation and whitespace fall away', () => {
	assert.equal(
		find.normalize('<!-- wp:paragraph --><p>“It’s” &amp;&nbsp;open – late…</p>'),
		find.normalize('"It\'s" & open - late...'),
	);
	assert.equal(find.normalize('<b>A</b>\n  <i>b</i>'), 'ab');
});

test('flatten: every block with its depth', () => {
	assert.deepEqual(find.flatten(blocks).map(e => [e.block.clientId, e.depth]), [
		['group', 0], ['h', 1], ['list', 1], ['li1', 2], ['li2', 2], ['img', 1], ['para', 1],
	]);
});

test('findBlockId: a whole list (text spans items) selects the list, not an item', () => {
	const issue = { context: '<ul>', text: 'Physical condition: The item is moldy.Additional copies: Duplicated items.' };
	assert.equal(find.findBlockId(blocks, issue, contentOf), 'list');
});

test('findBlockId: one list item selects that item', () => {
	const issue = { context: '<li>Additional copies: Duplicated items.</li>', text: 'Additional copies: Duplicated items.' };
	assert.equal(find.findBlockId(blocks, issue, contentOf), 'li2');
});

test('findBlockId: an element with no text is found by src', () => {
	const issue = { context: '<img src="https://library.wheatoncollege.edu/a.png" alt="">', text: '' };
	assert.equal(find.findBlockId(blocks, issue, contentOf), 'img');
});

test('findBlockId: falls back to the text inside context when there is no text field', () => {
	const issue = { context: '<p>It’s the library’s “best” room – open late…</p>' };
	assert.equal(find.findBlockId(blocks, issue, contentOf), 'para');
});

test('findBlockId: theme markup not in the content finds nothing', () => {
	assert.equal(find.findBlockId(blocks, { context: '<html lang="">', text: '' }, contentOf), null);
	assert.equal(find.findBlockId(blocks, { context: '<a href="/hours/">Hours</a>', text: 'Library hours today' }, contentOf), null);
});

test('findBlockId: href of "#" or empty is not a usable clue', () => {
	assert.equal(find.findBlockId(blocks, { context: '<a href="#"></a>', text: '' }, contentOf), null);
});

test('findBlockId: text shorter than 3 characters is not a usable clue', () => {
	// "is" occurs in the list text, but would match almost any block.
	assert.equal(find.findBlockId(blocks, { context: '<span>is</span>', text: 'is' }, contentOf), null);
});

// run() against a stand-in for wp.data, wp.blocks, window timers and the editor canvas.
const strings = { found: 'Found: %s', notFound: 'Not found: %s', stale: 'Stale' };
function editor(getBlocksSequence) {
	const log = { notices: [], selected: [], flashed: [], scrolled: [], getBlocks: 0, timers: [] };
	const wp = {
		data: {
			dispatch: store => (store === 'core/notices'
				? { createNotice: (status, message, opts) => log.notices.push([status, message, opts.id]) }
				: { selectBlock: id => log.selected.push(id), flashBlock: id => log.flashed.push(id) }),
			select: () => ({ getBlocks: () => getBlocksSequence[Math.min(log.getBlocks++, getBlocksSequence.length - 1)] }),
		},
		blocks: { getBlockContent: block => { if (block.broken) throw new Error('invalid block'); return block.content; } },
	};
	globalThis.window = { setTimeout: fn => log.timers.push(fn) };
	globalThis.document = {
		querySelector: sel => (sel.startsWith('[data-block=') ? { scrollIntoView: () => log.scrolled.push(sel) } : null),
	};
	const drain = () => { while (log.timers.length) log.timers.shift()(); };
	return { wp, log, drain };
}
const issue = { found: true, message: 'List message', context: '<li>Additional copies: Duplicated items.</li>', text: 'Additional copies: Duplicated items.' };

test('run: an issue no longer in the scan shows the stale notice and selects nothing', () => {
	const { wp, log, drain } = editor([blocks]);
	find.run({ issue: { found: false }, strings }, wp);
	drain();
	assert.deepEqual(log.notices, [['warning', 'Stale', 'wpa11y-find']]);
	assert.deepEqual(log.selected, []);
});

test('run: selects, flashes and scrolls to the block, and names the problem', () => {
	const { wp, log, drain } = editor([blocks]);
	find.run({ issue, strings }, wp);
	drain();
	assert.deepEqual(log.selected, ['li2']);
	assert.deepEqual(log.flashed, ['li2']);
	assert.deepEqual(log.scrolled, ['[data-block="li2"]']);
	assert.deepEqual(log.notices, [['info', 'Found: List message', 'wpa11y-find']]);
});

test('run: waits for the editor to load its blocks', () => {
	const { wp, log, drain } = editor([[], [], blocks]);
	find.run({ issue, strings }, wp);
	drain();
	assert.equal(log.getBlocks, 3);
	assert.deepEqual(log.selected, ['li2']);
});

test('run: gives up after 40 waits with the not-found notice', () => {
	const { wp, log, drain } = editor([[]]);
	find.run({ issue, strings }, wp);
	drain();
	assert.equal(log.getBlocks, 41);
	assert.deepEqual(log.notices, [['warning', 'Not found: List message', 'wpa11y-find']]);
	assert.deepEqual(log.selected, []);
});

test('run: a block whose content cannot be read is skipped, not fatal', () => {
	const broken = { clientId: 'bad', name: 'core/freeform', broken: true, innerBlocks: [] };
	const { wp, log, drain } = editor([[broken, ...blocks]]);
	find.run({ issue, strings }, wp);
	drain();
	assert.deepEqual(log.selected, ['li2']);
});
