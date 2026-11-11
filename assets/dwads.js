/**
 * Crypto Banner Ads loader.
 *
 * Finds every empty slot, asks the server for one banner, and inserts it.
 * If the server answers "no banner", the slot is left untouched and therefore
 * takes up no space on the page.
 */
(function () {
	'use strict';

	var cfg = window.dwadsConfig || {};
	var pending = typeof WeakSet === 'function' ? new WeakSet() : null;
	var warned = {};

	function warnBadSlot(slotName) {
		if (warned[slotName]) {
			return;
		}
		warned[slotName] = true;
		if (window.console && window.console.warn) {
			window.console.warn(
				'[dwads] Slot "' + slotName + '" is not one of the configured sizes, so no banner will ever render there. ' +
				'Check the "Allowed sizes" list in Crypto Ads settings, or add that size.'
			);
		}
	}

	function lastScript() {
		var found = null;
		var all = document.getElementsByTagName('script');
		for (var i = 0; i < all.length; i++) {
			if (all[i].src && all[i].src.indexOf('dwads.js') !== -1) {
				found = all[i];
			}
		}
		return found;
	}

	function apiFor(slot) {
		var own = slot.getAttribute('data-dwads-api');
		if (own) {
			return own;
		}
		if (cfg.api) {
			return cfg.api;
		}
		var script = document.currentScript || lastScript();
		if (!script || !script.src) {
			return null;
		}
		try {
			return new URL('/wp-json/dwads/v1/serve', script.src).toString();
		} catch (e) {
			return null;
		}
	}

	function already(slot) {
		if (pending && pending.has(slot)) {
			return true;
		}
		if (pending) {
			pending.add(slot);
		}
		return false;
	}

	function build(slot, data) {
		var link = document.createElement('a');
		link.className = 'dwads-link';
		link.href = data.click;
		if (data.newTab) {
			link.target = '_blank';
		}
		link.rel = data.rel || 'nofollow sponsored noopener';

		var img = document.createElement('img');
		img.className = 'dwads-img';
		img.src = data.img;
		img.alt = data.alt || '';
		if (data.width) {
			img.width = data.width;
		}
		if (data.height) {
			img.height = data.height;
		}
		img.loading = 'lazy';
		img.decoding = 'async';
		img.addEventListener('error', function () {
			slot.innerHTML = '';
			slot.removeAttribute('data-dwads-filled');
		});

		link.appendChild(img);

		// The frame is exactly as wide as the creative, so the link underneath
		// can be aligned to the image's edges rather than to the column.
		var frame = document.createElement('div');
		frame.className = 'dwads-frame';
		frame.appendChild(link);

		if (data.foot && data.foot.url && data.foot.text) {
			var foot = document.createElement('a');
			foot.className = 'dwads-foot dwads-foot-' + (data.foot.align || 'center');
			foot.href = data.foot.url;
			foot.rel = 'nofollow noopener';
			foot.textContent = data.foot.text;
			frame.appendChild(foot);
		}

		slot.innerHTML = '';
		slot.appendChild(frame);

		// The slot is a flex box; give it the banner height so the creative is
		// centred vertically. Empty slots keep no height at all.
		if (data.height) {
			slot.style.minHeight = data.height + 'px';
		}

		if (data.label) {
			// Attached to the link, not the slot: the slot can be wider than the
			// creative, and the label has to line up with the image's corner.
			var badge = document.createElement('span');
			badge.className = 'dwads-adlabel';
			badge.textContent = data.label;
			link.appendChild(badge);
		}

		slot.setAttribute('data-dwads-filled', '1');
	}

	function fill(slot) {
		if (slot.getAttribute('data-dwads-filled')) {
			return;
		}
		var endpoint = apiFor(slot);
		if (!endpoint) {
			return;
		}
		if (already(slot)) {
			return;
		}

		var url;
		try {
			url = new URL(endpoint, window.location.href);
		} catch (e) {
			return;
		}

		url.searchParams.set('slot', (slot.getAttribute('data-dwads-slot') || '').trim());
		if (cfg.pageId) {
			url.searchParams.set('page_id', String(cfg.pageId));
		}
		var site = slot.getAttribute('data-dwads-site');
		if (site) {
			url.searchParams.set('site', site);
		}
		['foot', 'foot_text', 'foot_url', 'foot_align'].forEach(function (key) {
			var value = slot.getAttribute('data-dwads-' + key.replace('_', '-'));
			if (value) {
				url.searchParams.set(key, value);
			}
		});

		fetch(url.toString(), { credentials: 'omit', mode: 'cors', cache: 'no-store' })
			.then(function (response) {
				if (response.status === 200) {
					return response.json();
				}
				if (response.status === 400) {
					warnBadSlot((slot.getAttribute('data-dwads-slot') || '').trim());
				}
				return null;
			})
			.then(function (data) {
				if (!data || !data.img || !data.click) {
					return;
				}
				build(slot, data);
			})
			.catch(function () {
				// Silent: an unfilled slot simply shows nothing.
			});
	}

	function scan(root) {
		var nodes;
		try {
			nodes = (root || document).querySelectorAll('[data-dwads-slot]:not([data-dwads-filled])');
		} catch (e) {
			return;
		}
		for (var i = 0; i < nodes.length; i++) {
			fill(nodes[i]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			scan();
		});
	} else {
		scan();
	}

	if (typeof MutationObserver === 'function' && document.documentElement) {
		new MutationObserver(function (mutations) {
			for (var i = 0; i < mutations.length; i++) {
				var added = mutations[i].addedNodes;
				for (var j = 0; j < added.length; j++) {
					var node = added[j];
					if (node.nodeType !== 1) {
						continue;
					}
					if (node.hasAttribute && node.hasAttribute('data-dwads-slot')) {
						fill(node);
					} else if (node.querySelectorAll) {
						scan(node);
					}
				}
			}
		}).observe(document.documentElement, { childList: true, subtree: true });
	}
})();
