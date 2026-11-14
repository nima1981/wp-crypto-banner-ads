/**
 * Crypto Banner Ads loader.
 *
 * Finds every empty slot, asks the server for one banner, and inserts it.
 * If the server answers "no banner", the slot is left untouched and therefore
 * takes up no space on the page.
 *
 * Responsive slots
 * ----------------
 * A slot may name a second size for narrow screens:
 *
 *   <div data-dwads-slot="970x250" data-dwads-slot-mobile="320x100"></div>
 *
 * One size is chosen per viewport, the way a media query would choose a
 * stylesheet: the narrow size below the breakpoint, the wide size at or above
 * it. Nothing is rendered twice, so there is no device targeting involved —
 * the same visitor moving the window across the breakpoint simply gets the
 * creative that fits the space.
 *
 * If the narrow size has no inventory at all, the wide creative is requested
 * instead so the space is not left empty at mobile width. Add
 * data-dwads-fallback="0" to a slot to disable that and leave it blank
 * instead.
 */
(function () {
	'use strict';

	var cfg = window.dwadsConfig || {};

	var breakpoint = parseInt(cfg.mobileBreakpoint, 10);
	if (isNaN(breakpoint) || breakpoint <= 0) {
		breakpoint = 600;
	}

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

	/**
	 * Report to a sibling endpoint (/seen, /armed) built from the serve address.
	 * The token travels in the query string so the request is a simple no-body
	 * POST: no preflight, and sendBeacon can carry it unchanged.
	 */
	function report(name, token) {
		if (!token) {
			return;
		}
		var base = cfg[name + 'Api'];
		if (!base) {
			var serve = cfg.api;
			if (!serve) {
				var script = document.currentScript || lastScript();
				if (script && script.src) {
					try {
						serve = new URL('/wp-json/dwads/v1/serve', script.src).toString();
					} catch (e) {
						serve = null;
					}
				}
			}
			if (!serve) {
				return;
			}
			base = String(serve).replace(/serve(?:[?#].*)?$/, name);
		}

		var url = base + (base.indexOf('?') === -1 ? '?' : '&') + 'token=' + encodeURIComponent(token);

		try {
			if (window.fetch) {
				fetch(url, {
					method: 'POST',
					credentials: 'omit',
					mode: 'cors',
					keepalive: true,
					cache: 'no-store'
				}).catch(function () {});
				return;
			}
			if (navigator.sendBeacon) {
				navigator.sendBeacon(url);
			}
		} catch (e) {
			// Silent: a lost report only means the click may not be billable.
		}
	}

	/**
	 * Tell the server when the creative is genuinely on screen.
	 *
	 * Half of it visible for a full second, so a slot that is technically
	 * rendered but never scrolled to does not count as an impression.
	 */
	function watchVisibility(frame, token) {
		var fired = false;
		var timer = null;
		var observer = null;

		function fire() {
			if (fired) {
				return;
			}
			fired = true;
			if (timer) {
				clearTimeout(timer);
				timer = null;
			}
			if (observer) {
				observer.disconnect();
			}
			report('seen', token);
		}

		if (!('IntersectionObserver' in window)) {
			// No way to measure, so fall back to "it has been a second".
			window.setTimeout(fire, 1000);
			return;
		}

		observer = new IntersectionObserver(function (entries) {
			for (var i = 0; i < entries.length; i++) {
				var entry = entries[i];
				if (entry.isIntersecting && entry.intersectionRatio >= 0.5) {
					if (!timer && !fired) {
						timer = window.setTimeout(function () {
							timer = null;
							fire();
						}, 1000);
					}
				} else if (timer) {
					clearTimeout(timer);
					timer = null;
				}
			}
		}, { threshold: [0, 0.5, 1] });

		observer.observe(frame);
	}

	/**
	 * Note a pointer, key or context menu on the creative, and mark the link.
	 *
	 * The marker is written into the live href inside the same event that opens
	 * the link, so a real activation always carries it. Nothing here delays the
	 * navigation: the default action is left alone.
	 */
	function watchGesture(link, token) {
		var armed = false;

		function arm() {
			if (armed) {
				return;
			}
			armed = true;
			if (link.href.indexOf('dwads_g=') === -1) {
				link.href = link.href + (link.href.indexOf('?') === -1 ? '?' : '&') + 'dwads_g=1';
			}
			report('armed', token);
		}

		var events = ['pointerdown', 'mousedown', 'touchstart', 'keydown', 'contextmenu', 'focus', 'auxclick'];
		for (var i = 0; i < events.length; i++) {
			link.addEventListener(events[i], arm);
		}
	}

	function sizesFor(slot) {
		return {
			wide: (slot.getAttribute('data-dwads-slot') || '').trim(),
			narrow: (slot.getAttribute('data-dwads-slot-mobile') || '').trim()
		};
	}

	function isNarrowView() {
		if (!window.matchMedia) {
			return window.innerWidth <= breakpoint;
		}
		return window.matchMedia('(max-width: ' + breakpoint + 'px)').matches;
	}

	/** The one size this slot wants right now. */
	function wishSize(slot) {
		var sizes = sizesFor(slot);
		if (sizes.narrow && isNarrowView()) {
			return sizes.narrow;
		}
		return sizes.wide;
	}

	function clear(slot) {
		slot.innerHTML = '';
		slot.style.minHeight = '';
		slot.removeAttribute('data-dwads-filled');
		slot.removeAttribute('data-dwads-size');
	}

	function build(slot, data, sizeName) {
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
			clear(slot);
		});
		// A creative that had to shrink to fit the column should not leave the
		// slot reserving its natural height.
		img.addEventListener('load', function () {
			if (img.naturalWidth && img.clientWidth < img.naturalWidth) {
				slot.style.minHeight = '';
			}
		});

		link.appendChild(img);

		// The frame is exactly as wide as the creative, so the link underneath
		// can be aligned to the image's edges rather than to the column.
		var frame = document.createElement('div');
		frame.className = 'dwads-frame';
		frame.appendChild(link);

		// Up to two quiet links below the creative: the site's own invitation and
		// the banner's funding page. One link keeps the simple block layout; two
		// share a row so they can sit on opposite sides.
		var links = [];
		var footBar = null;
		[data.foot, data.fund].forEach(function (item) {
			if (item && item.url && item.text) {
				links.push(item);
			}
		});

		function makeFoot(item) {
			var foot = document.createElement('a');
			foot.className = 'dwads-foot dwads-foot-' + (item.align || 'center');
			foot.href = item.url;
			foot.rel = 'nofollow noopener';
			if (item.new_tab) {
				foot.target = '_blank';
			}
			foot.textContent = item.text;
			return foot;
		}

		// The server already moves a colliding link to the far side; repeat the
		// rule here so a stale payload can never stack both links on one edge.
		if (links.length > 1 && links[1].align === links[0].align) {
			links[1] = {
				url: links[1].url,
				text: links[1].text,
				align: links[0].align === 'left' ? 'right' : (links[0].align === 'right' ? 'left' : 'right')
			};
		}

		if (links.length === 1) {
			frame.appendChild(makeFoot(links[0]));
		} else if (links.length > 1) {
			footBar = document.createElement('div');
			footBar.className = 'dwads-footbar';
			for (var k = 0; k < links.length; k++) {
				footBar.appendChild(makeFoot(links[k]));
			}
			frame.appendChild(footBar);
		}

		slot.innerHTML = '';
		slot.appendChild(frame);

		// A narrow creative (a 160x600 skyscraper, say) cannot hold both links
		// side by side. Measured after attach, when the image has a layout width:
		// if the row is wider than the creative, stack them on two lines instead
		// of letting them run past its edges.
		if (footBar && img.clientWidth > 0 && footBar.scrollWidth > img.clientWidth + 1) {
			frame.removeChild(footBar);
			for (var n = 0; n < links.length; n++) {
				frame.appendChild(makeFoot(links[n]));
			}
		}

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

		// Invisible decoy: off-screen rather than display:none, because a
		// stylesheet-hidden link is often skipped while an off-screen one is
		// followed. Nothing a person can see, reach by keyboard or click.
		if (data.hp) {
			var decoy = document.createElement('a');
			decoy.className = 'dwads-decoy';
			decoy.href = data.hp;
			decoy.rel = 'nofollow noindex';
			decoy.setAttribute('aria-hidden', 'true');
			decoy.setAttribute('tabindex', '-1');
			decoy.style.cssText = 'position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;';
			frame.appendChild(decoy);
		}

		if (data.token) {
			watchVisibility(frame, data.token);
			watchGesture(link, data.token);
		}

		slot.setAttribute('data-dwads-filled', '1');
		slot.setAttribute('data-dwads-size', sizeName);
	}

	/** Ask the server for one banner of exactly this size. Resolves to null. */
	function requestSize(slot, sizeName) {
		var endpoint = apiFor(slot);
		if (!endpoint) {
			return Promise.resolve(null);
		}

		var url;
		try {
			url = new URL(endpoint, window.location.href);
		} catch (e) {
			return Promise.resolve(null);
		}

		url.searchParams.set('slot', sizeName);
		if (cfg.pageId) {
			url.searchParams.set('page_id', String(cfg.pageId));
		}
		var site = slot.getAttribute('data-dwads-site');
		if (site) {
			url.searchParams.set('site', site);
		}
		['foot', 'foot_text', 'foot_url', 'foot_align', 'fund', 'fund_text', 'fund_align'].forEach(function (key) {
			var value = slot.getAttribute('data-dwads-' + key.replace('_', '-'));
			if (value) {
				url.searchParams.set(key, value);
			}
		});

		return fetch(url.toString(), { credentials: 'omit', mode: 'cors', cache: 'no-store' })
			.then(function (response) {
				if (response.status === 200) {
					return response.json();
				}
				if (response.status === 400) {
					warnBadSlot(sizeName);
				}
				return null;
			})
			.then(function (data) {
				if (!data || !data.img || !data.click) {
					return null;
				}
				return data;
			})
			.catch(function () {
				// Silent: an unfilled slot simply shows nothing.
				return null;
			});
	}

	function fill(slot, force) {
		if (!force && slot.getAttribute('data-dwads-filled')) {
			return;
		}
		if (slot.getAttribute('data-dwads-busy')) {
			return;
		}

		var sizes = sizesFor(slot);
		var want = wishSize(slot);
		if (!want) {
			return;
		}

		// Only a narrow request can fall back, and only when it is a different
		// size than the wide one it would fall back to.
		var fallback = '';
		if (sizes.narrow && want === sizes.narrow && sizes.wide && sizes.wide !== sizes.narrow &&
			slot.getAttribute('data-dwads-fallback') !== '0') {
			fallback = sizes.wide;
		}

		slot.setAttribute('data-dwads-busy', '1');

		requestSize(slot, want)
			.then(function (data) {
				if (data) {
					build(slot, data, want);
					return;
				}
				if (!fallback) {
					// Nothing to show at this width: drop whatever was there so a
					// stale creative cannot outlive its viewport.
					clear(slot);
					return;
				}
				return requestSize(slot, fallback).then(function (wide) {
					if (wide) {
						build(slot, wide, fallback);
					} else {
						clear(slot);
					}
				});
			})
			.catch(function () {
				// Silent by design.
			})
			.then(function () {
				slot.removeAttribute('data-dwads-busy');
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

	// A horizontal resize across the breakpoint changes which size a slot
	// wants, so swap the creative for the size that now fits.
	var resizeTimer = null;

	function onViewportChange() {
		if (resizeTimer) {
			clearTimeout(resizeTimer);
		}
		resizeTimer = setTimeout(function () {
			resizeTimer = null;
			var nodes;
			try {
				nodes = document.querySelectorAll('[data-dwads-slot][data-dwads-slot-mobile]');
			} catch (e) {
				return;
			}
			for (var i = 0; i < nodes.length; i++) {
				var slot = nodes[i];
				// Only swap what is actually on the page.
				if (!slot.getAttribute('data-dwads-filled') && !slot.getAttribute('data-dwads-size')) {
					continue;
				}
				if (slot.getAttribute('data-dwads-size') === wishSize(slot)) {
					continue;
				}
				slot.removeAttribute('data-dwads-filled');
				fill(slot, true);
			}
		}, 250);
	}

	if (window.addEventListener) {
		window.addEventListener('resize', onViewportChange);
		window.addEventListener('orientationchange', onViewportChange);
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
