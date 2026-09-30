/* wpa11y "Show in editor": selects the block that holds one scan issue. ES5, no build step.
 * Loaded only in the block editor when the URL has ?wpa11y_find=<issue key>.
 * The matching functions are pure so tests/js/editor.test.mjs can run them in Node. */
(function (root, factory) {
	var api = factory();
	if (typeof module === 'object' && module.exports) {
		module.exports = api;
		return;
	}
	if (root.WPA11Y_FIND && root.wp && root.wp.domReady) {
		root.wp.domReady(function () { api.run(root.WPA11Y_FIND, root.wp); });
	}
}(this, function () {
	'use strict';

	var ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ' };

	function decode(s) {
		return String(s)
			.replace(/&#(\d+);/g, function (m, d) { return String.fromCharCode(Number(d)); })
			.replace(/&#x([0-9a-f]+);/gi, function (m, h) { return String.fromCharCode(parseInt(h, 16)); })
			.replace(/&([a-z]+);/gi, function (m, n) { return ENTITIES.hasOwnProperty(n.toLowerCase()) ? ENTITIES[n.toLowerCase()] : m; });
	}

	// Text for comparing the rendered page with saved block HTML. WordPress curls
	// quotes and dashes on output (wptexturize) and whitespace differs, so those go.
	function normalize(html) {
		var s = String(html || '')
			.replace(/<!--[\s\S]*?-->/g, '')
			.replace(/<[^>]*>?/g, '');
		return decode(s)
			.replace(/[‘’‚′]/g, "'")
			.replace(/[“”„″]/g, '"')
			.replace(/[–—]/g, '-')
			.replace(/-{2,}/g, '-')
			.replace(/…/g, '...')
			.toLowerCase()
			.replace(/\s+/g, '');
	}

	// Clues from one issue: its text, its tag name, and id/src/href on its opening tag.
	function needles(issue) {
		var context = String(issue.context || '');
		var open = (context.match(/^<[^>]*>?/) || [''])[0];
		var tag = (open.match(/^<([a-z0-9-]+)/i) || ['', ''])[1].toLowerCase();
		var attrs = [];
		var re = /\s(id|src|href)\s*=\s*"([^"]*)"/gi;
		var m;
		while ((m = re.exec(open)) !== null) {
			var value = decode(m[2]);
			if (value !== '' && value !== '#') { attrs.push(m[1].toLowerCase() + '="' + value + '"'); }
		}
		var text = normalize(issue.text || context).slice(0, 120);
		return { text: text.length >= 3 ? text : '', tag: tag, attrs: attrs };
	}

	function flatten(blocks, depth, out) {
		out = out || [];
		depth = depth || 0;
		for (var i = 0; i < blocks.length; i++) {
			out.push({ block: blocks[i], depth: depth });
			flatten(blocks[i].innerBlocks || [], depth + 1, out);
		}
		return out;
	}

	// The innermost block whose saved HTML holds the issue, preferring blocks that
	// contain the element's tag (so a whole list picks the list, not its first item).
	function findBlockId(blocks, issue, contentOf) {
		var n = needles(issue);
		if (!n.text && !n.attrs.length) { return null; }
		var hits = [];
		var entries = flatten(blocks);
		for (var i = 0; i < entries.length; i++) {
			var raw = String(contentOf(entries[i].block) || '');
			var plain = decode(raw);
			var match = (n.text && normalize(raw).indexOf(n.text) !== -1);
			for (var a = 0; !match && a < n.attrs.length; a++) {
				match = plain.indexOf(n.attrs[a]) !== -1;
			}
			if (match) {
				hits.push({ entry: entries[i], hasTag: n.tag !== '' && raw.toLowerCase().indexOf('<' + n.tag) !== -1 });
			}
		}
		var tagged = hits.filter(function (h) { return h.hasTag; });
		var pool = tagged.length ? tagged : hits;
		var best = null;
		for (var j = 0; j < pool.length; j++) {
			if (!best || pool[j].entry.depth > best.entry.depth) { best = pool[j]; }
		}
		return best ? best.entry.block.clientId : null;
	}

	function run(cfg, wp) {
		var notices = wp.data.dispatch('core/notices');
		function notice(status, message) {
			notices.createNotice(status, message, { id: 'wpa11y-find', isDismissible: true });
		}
		if (!cfg.issue || !cfg.issue.found) {
			notice('warning', cfg.strings.stale);
			return;
		}
		var tries = 0;
		(function wait() {
			var blocks = wp.data.select('core/block-editor').getBlocks();
			if (!blocks.length && tries++ < 40) {
				window.setTimeout(wait, 250);
				return;
			}
			var id = findBlockId(blocks, cfg.issue, function (block) {
				try { return wp.blocks.getBlockContent(block); } catch (e) { return ''; }
			});
			if (!id) {
				notice('warning', cfg.strings.notFound.replace('%s', cfg.issue.message));
				return;
			}
			var editor = wp.data.dispatch('core/block-editor');
			editor.selectBlock(id);
			if (editor.flashBlock) { editor.flashBlock(id); }
			// The canvas may be an iframe (newer WordPress) or the admin page itself.
			window.setTimeout(function () {
				var frame = document.querySelector('iframe[name="editor-canvas"]');
				var doc = frame && frame.contentDocument ? frame.contentDocument : document;
				var el = doc.querySelector('[data-block="' + id + '"]');
				if (el && el.scrollIntoView) { el.scrollIntoView({ block: 'center' }); }
			}, 300);
			notice('info', cfg.strings.found.replace('%s', cfg.issue.message));
		})();
	}

	return { normalize: normalize, needles: needles, flatten: flatten, findBlockId: findBlockId, run: run };
}));
