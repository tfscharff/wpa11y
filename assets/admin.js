/* wpa11y page detail: dismiss, undo, rescan. ES5, no build step. */
(function () {
	'use strict';

	var cfg = window.WPA11Y || {};
	var region = document.getElementById('wpa11y-detail');
	var live = document.getElementById('wpa11y-live');
	var errorBox = document.getElementById('wpa11y-error');
	if (!region || !cfg.root) { return; }

	var postId = region.getAttribute('data-post');
	var pollTimer = null;
	var deferred = null;

	function announce(text) {
		live.textContent = '';
		window.setTimeout(function () { live.textContent = text; }, 100);
	}

	function showError(text) {
		errorBox.innerHTML = '';
		errorBox.className = '';
		if (!text) { return; }
		var p = document.createElement('p');
		p.textContent = text;
		errorBox.className = 'notice notice-error';
		errorBox.appendChild(p);
	}

	function api(method, path, body) {
		return window.fetch(cfg.root + path, {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
			body: body ? JSON.stringify(body) : undefined
		}).then(function (res) {
			return res.json().catch(function () { return {}; }).then(function (data) {
				if (!res.ok) {
					throw new Error(data && data.message ? data.message : cfg.strings.failed + ' (' + res.status + ')');
				}
				return data;
			});
		});
	}

	function formOpen() {
		return !!region.querySelector('.wpa11y-dismiss-form:not([hidden])');
	}

	// Replaces the region, keeping open rule groups open.
	function render(data, focusSelector) {
		deferred = null;
		var open = [];
		var groups = region.querySelectorAll('details[open]');
		for (var i = 0; i < groups.length; i++) { open.push(groups[i].id); }
		region.innerHTML = data.html;
		for (var j = 0; j < open.length; j++) {
			var d = document.getElementById(open[j]);
			if (d) { d.open = true; }
		}
		if (focusSelector) {
			var el = region.querySelector(focusSelector) || region.querySelector('#wpa11y-h-errors') || region.querySelector('#wpa11y-rescan');
			if (el) { el.focus(); }
		}
		setPolling(data.pending);
	}

	function setPolling(on) {
		if (on && !pollTimer) {
			pollTimer = window.setInterval(poll, 15000);
		} else if (!on && pollTimer) {
			window.clearInterval(pollTimer);
			pollTimer = null;
		}
	}

	// A selector for whatever has focus inside the region, so a background
	// re-render can put focus back instead of dropping it to <body>.
	function focusTarget() {
		var el = document.activeElement;
		if (!el || !region.contains(el)) { return null; }
		if (el.id) { return '#' + el.id; }
		var group = el.closest('details[id]');
		var issue = el.closest('.wpa11y-issue');
		if (issue && el.classList.contains('wpa11y-undo')) {
			return '.wpa11y-issue[data-key="' + issue.getAttribute('data-key') + '"] .wpa11y-undo';
		}
		if (group) { return '#' + group.id + ' > summary'; }
		return '#wpa11y-h-errors';
	}

	function poll() {
		api('GET', 'status?post=' + encodeURIComponent(postId)).then(function (data) {
			if (data.pending) { return; }
			setPolling(false);
			if (formOpen()) {
				deferred = data; // Don't wipe a note being typed; render when the form closes.
			} else {
				render(data, focusTarget());
			}
			announce(data.rescan === 'timed_out' ? cfg.strings.timedOut : cfg.strings.scanDone + ' ' + data.summary);
		}, function () { /* Keep polling; the next request may succeed. */ });
	}

	function busy(button, on) {
		if (on) { button.setAttribute('aria-disabled', 'true'); } else { button.removeAttribute('aria-disabled'); }
	}

	function send(button, path, body, focusSelector, prefix) {
		busy(button, true);
		showError('');
		api('POST', path, body).then(function (data) {
			render(data, focusSelector);
			announce(prefix + (data.pending ? '' : ' ' + data.summary));
		}, function (err) {
			busy(button, false);
			showError(err.message);
		});
	}

	region.addEventListener('click', function (event) {
		var button = event.target.closest('button');
		if (!button || !region.contains(button) || button.getAttribute('aria-disabled') === 'true') { return; }
		var issue = button.closest('.wpa11y-issue');
		var form;

		if (button.classList.contains('wpa11y-dismiss-open')) {
			form = document.getElementById(button.getAttribute('aria-controls'));
			form.hidden = false;
			button.setAttribute('aria-expanded', 'true');
			form.querySelector('textarea').focus();
		} else if (button.classList.contains('wpa11y-dismiss-cancel')) {
			form = button.closest('.wpa11y-dismiss-form');
			form.hidden = true;
			var opener = issue.querySelector('.wpa11y-dismiss-open');
			opener.setAttribute('aria-expanded', 'false');
			if (deferred) { render(deferred, '#wpa11y-h-warnings'); } else { opener.focus(); }
		} else if (button.classList.contains('wpa11y-dismiss-confirm')) {
			form = button.closest('.wpa11y-dismiss-form');
			send(button, 'dismiss', {
				post: Number(postId),
				key: issue.getAttribute('data-key'),
				note: form.querySelector('textarea').value
			}, '#wpa11y-h-warnings', cfg.strings.dismissed);
		} else if (button.classList.contains('wpa11y-undo')) {
			send(button, 'undismiss', { id: Number(button.getAttribute('data-id')) }, '#wpa11y-h-warnings', cfg.strings.undone);
		} else if (button.id === 'wpa11y-rescan') {
			send(button, 'rescan', { post: Number(postId) }, '#wpa11y-rescan', cfg.strings.scanning);
		}
	});

	// Escape closes an open dismiss form.
	region.addEventListener('keydown', function (event) {
		if (event.key !== 'Escape') { return; }
		var form = event.target.closest('.wpa11y-dismiss-form');
		if (form) { form.querySelector('.wpa11y-dismiss-cancel').click(); }
	});

	setPolling(region.getAttribute('data-pending') === '1');
})();
