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
