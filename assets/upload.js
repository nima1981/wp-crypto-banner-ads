/* Crypto Banner Ads - upload page helpers.
 *
 * One job: let an advertiser drop several banner files at once and have each
 * one land in its own row, grouped by size, so they only fill in the links.
 *
 * The server never sees anything new: files are placed into the existing
 * per-row <input type="file"> through a DataTransfer, so the POST shape, the
 * cap and every validation stay exactly as they were. This only saves clicks.
 */
(function () {
	'use strict';

	var cfg = window.DWADS_UPLOAD || {};
	var maxFiles = parseInt(cfg.max, 10) || 5;
	/* The configured maximum is the intent; a single request can only carry
	   what PHP's max_file_uploads allows. Take the smaller of the two so the
	   count in the message is a number that can actually arrive. */
	var phpMax = parseInt(cfg.phpMax, 10) || 0;
	var limit = phpMax > 0 && phpMax < maxFiles ? phpMax : maxFiles;
	var sizes = Array.isArray(cfg.sizes) ? cfg.sizes : [];
	var autofit = cfg.autofit !== false;
	var maxKb = parseInt(cfg.maxKb, 10) || 0;

	var wrap = document.getElementById('dwads-rows');
	var addBtn = document.getElementById('dwads-add-row');
	var tpl = document.getElementById('dwads-row-template');
	var zone = document.getElementById('dwads-dropzone');
	var picker = document.getElementById('dwads-drop-input');
	var status = document.getElementById('dwads-drop-status');

	if (!wrap || !addBtn || !tpl) {
		return;
	}

	function rows() {
		return Array.prototype.slice.call(wrap.querySelectorAll('.dwads-row'));
	}

	function fileInput(row) {
		return row ? row.querySelector('input[type=file]') : null;
	}

	function rowFileCount(row) {
		var input = fileInput(row);
		return input && input.files ? input.files.length : 0;
	}

	function totalFiles() {
		return rows().reduce(function (n, row) {
			return n + rowFileCount(row);
		}, 0);
	}

	function rowHasFile(row) {
		return rowFileCount(row) > 0;
	}

	/* Names carry the row number, so every input must be renumbered after any
	   insert or move - otherwise two rows share an index and one is lost. */
	function reindex() {
		rows().forEach(function (row, i) {
			Array.prototype.slice.call(row.querySelectorAll('input, select, textarea')).forEach(function (el) {
				var name = el.getAttribute('name');
				if (!name) {
					return;
				}
				var m = /^rows\[[^\]]*\]\[([^\]]+)\]$/.exec(name);
				if (m) {
					el.setAttribute('name', 'rows[' + i + '][' + m[1] + ']');
				}
			});
		});
	}

	function syncAddButton() {
		addBtn.disabled = totalFiles() >= limit || rows().length >= limit;
	}

	function newRow() {
		var holder = document.createElement('div');
		holder.innerHTML = tpl.innerHTML.replace(/__i__/g, '0').trim();
		var node = holder.firstChild;
		if (node) {
			wrap.appendChild(node);
		}
		return node;
	}

	function attach(row, file) {
		var input = fileInput(row);
		if (!input) {
			return false;
		}
		try {
			var dt = new DataTransfer();
			dt.items.add(file);
			input.files = dt.files;
			return input.files.length === 1;
		} catch (e) {
			return false;
		}
	}

	/* Which banner size this file is. Exact match wins; otherwise mirror the
	   server's nearest_size(): closest aspect ratio, and only if the centre
	   crop stays within the same 8% the plugin allows. */
	function classify(w, h) {
		var i;
		for (i = 0; i < sizes.length; i++) {
			if (parseInt(sizes[i].w, 10) === w && parseInt(sizes[i].h, 10) === h) {
				return { key: sizes[i].key, order: i, exact: true };
			}
		}
		if (autofit && w > 0 && h > 0) {
			var ratio = w / h;
			var best = null;
			var bestDiff = Infinity;
			var bestOrder = -1;
			for (i = 0; i < sizes.length; i++) {
				var tw = parseInt(sizes[i].w, 10);
				var th = parseInt(sizes[i].h, 10);
				if (!tw || !th) {
					continue;
				}
				var diff = Math.abs(Math.log((tw / th) / ratio));
				if (diff < bestDiff) {
					bestDiff = diff;
					best = sizes[i];
					bestOrder = i;
				}
			}
			if (best) {
				var target = best.w / best.h;
				var cropped = ratio > target ? 1 - (target / ratio) : 1 - (ratio / target);
				if (cropped <= 0.08) {
					return { key: best.key, order: bestOrder, fitted: true };
				}
			}
		}
		return { key: '', order: sizes.length, unmatched: true };
	}

	function measure(file) {
		return new Promise(function (resolve) {
			var url = URL.createObjectURL(file);
			var img = new Image();
			img.onload = function () {
				resolve({ w: img.naturalWidth || img.width || 0, h: img.naturalHeight || img.height || 0 });
				URL.revokeObjectURL(url);
			};
			img.onerror = function () {
				resolve(null);
				URL.revokeObjectURL(url);
			};
			img.src = url;
		});
	}

	function badge(row, key, dims, verdict) {
		var el = row.querySelector('.dwads-row-size');
		if (!el) {
			el = document.createElement('p');
			el.className = 'dwads-row-size';
			row.insertBefore(el, row.firstChild);
		}
		var text = key;
		var extra = '';
		if (verdict.unmatched) {
			extra = ' is-warn';
			text = dims.w && dims.h ? dims.w + '×' + dims.h + ' — no matching size' : 'Not a readable image';
		} else if (verdict.fitted) {
			extra = ' is-fit';
			text = 'Fitted to ' + key;
		}
		el.textContent = 'Detected: ' + text;
		el.className = 'dwads-row-size' + extra;
		row.setAttribute('data-dwads-order', String(verdict.order));
	}

	function orderOf(row) {
		var v = parseInt(row.getAttribute('data-dwads-order'), 10);
		return isNaN(v) ? sizes.length + 1 : v;
	}

	/* Same sizes together: a stable sort keeps the drop order inside a group,
	   and rows still waiting for a file sink to the bottom. Moving a node in
	   the DOM keeps its input values and its FileList. */
	function sortRows() {
		var list = rows().map(function (row, i) {
			return { row: row, i: i, order: orderOf(row) };
		});
		list.sort(function (a, b) {
			return a.order === b.order ? a.i - b.i : a.order - b.order;
		});
		var current = rows();
		var changed = list.some(function (item, i) {
			return item.row !== current[i];
		});
		if (!changed) {
			return;
		}
		list.forEach(function (item) {
			wrap.appendChild(item.row);
		});
		reindex();
	}

	function say(text, kind) {
		if (!status) {
			return;
		}
		status.textContent = text || '';
		status.className = 'dwads-drop-status' + (kind ? ' is-' + kind : '');
	}

	function handle(fileList) {
		var files = Array.prototype.slice.call(fileList || []).filter(function (f) {
			return /^image\//.test(f.type || '');
		});
		if (!files.length) {
			say('Those files are not images. Use PNG, JPEG or WebP.', 'warn');
			return;
		}

		var tooBig = files.filter(function (f) {
			return maxKb && f.size > maxKb * 1024;
		});
		if (tooBig.length) {
			files = files.filter(function (f) {
				return tooBig.indexOf(f) === -1;
			});
			say(tooBig.length + (tooBig.length === 1 ? ' file is' : ' files are') + ' larger than ' + maxKb + ' KB and were skipped.',
				'warn');
			if (!files.length) {
				return;
			}
		}

		var free = Math.max(0, limit - totalFiles());
		var extra = files.length > free ? files.length - free : 0;
		var take = files.slice(0, free);

		if (!take.length) {
			say('This upload already has the maximum of ' + limit + ' banners. Upload these in another run.', 'warn');
			return;
		}

		Promise.all(take.map(function (file) {
			return measure(file).then(function (dims) {
				return { file: file, dims: dims };
			});
		})).then(function (items) {
			items.forEach(function (item) {
				var d = item.dims || { w: 0, h: 0 };
				item.verdict = classify(d.w, d.h);
			});

			items.sort(function (a, b) {
				return a.verdict.order - b.verdict.order;
			});

			var added = [];
			var failed = 0;

			items.forEach(function (item) {
				var target = null;
				var all = rows();
				for (var i = 0; i < all.length; i++) {
					if (!rowHasFile(all[i])) {
						target = all[i];
						break;
					}
				}
				if (!target) {
					target = newRow();
				}
				if (!target || !attach(target, item.file)) {
					failed++;
					return;
				}
				badge(target, item.verdict.key, item.dims || { w: 0, h: 0 }, item.verdict);
				added.push(item);
			});

			sortRows();
			reindex();
			syncAddButton();

			var groups = {};
			added.forEach(function (item) {
				if (item.verdict.unmatched) {
					groups['unmatched'] = (groups['unmatched'] || 0) + 1;
				} else {
					groups[item.verdict.key] = (groups[item.verdict.key] || 0) + 1;
				}
			});

			var parts = Object.keys(groups).map(function (key) {
				return key === 'unmatched' ? groups[key] + ' unmatched' : groups[key] + ' × ' + key;
			});

			var message = added.length ?
				added.length + (added.length === 1 ? ' banner added' : ' banners added') +
				(parts.length ? ' — ' + parts.join(', ') : '') + '. Fill in a destination link for each.' : '';

			if (failed) {
				message += ' ' + failed + ' file' + (failed === 1 ? '' : 's') + ' could not be attached automatically — choose ' +
					(failed === 1 ? 'it' : 'them') + ' with the file picker below.';
			}
			if (extra) {
				message += ' ' + extra + ' more ' + (extra === 1 ? 'was' : 'were') + ' not added: ' +
					(limit < maxFiles
						? 'your server takes ' + limit + ' files per request'
						: 'the limit is ' + limit + ' banners per upload') +
					'. Add the rest in another run.';
			}

			say(message, failed || extra || groups.unmatched ? 'warn' : 'ok');
			syncAddButton();
		});
	}

	/* --- drop zone ------------------------------------------------------- */

	/* Setting input.files needs DataTransfer. Where that is missing (old
	   Safari, anything without the File constructor) the zone stays hidden
	   and the plain per-row file pickers are the only way in - no half-broken
	   shortcut on offer. */
	function canAttach() {
		try {
			var dt = new DataTransfer();
			dt.items.add(new File([''], 'probe.png', { type: 'image/png' }));
			var input = document.createElement('input');
			input.type = 'file';
			input.files = dt.files;
			return input.files.length === 1;
		} catch (e) {
			return false;
		}
	}

	if (zone && picker && canAttach()) {
		zone.removeAttribute('hidden');

		/* The picker lives inside the zone, so its own click bubbles back here.
		   Without this guard picker.click() would re-enter and open twice. */
		zone.addEventListener('click', function (event) {
			if (event.target === picker) {
				return;
			}
			picker.click();
		});

		zone.addEventListener('keydown', function (event) {
			if (event.key === 'Enter' || event.key === ' ') {
				event.preventDefault();
				picker.click();
			}
		});

		picker.addEventListener('change', function () {
			handle(picker.files);
			picker.value = '';
		});

		['dragenter', 'dragover'].forEach(function (name) {
			zone.addEventListener(name, function (event) {
				event.preventDefault();
				event.stopPropagation();
				zone.classList.add('is-over');
			});
		});

		['dragleave', 'dragend'].forEach(function (name) {
			zone.addEventListener(name, function (event) {
				event.preventDefault();
				event.stopPropagation();
				zone.classList.remove('is-over');
			});
		});

		zone.addEventListener('drop', function (event) {
			event.preventDefault();
			event.stopPropagation();
			zone.classList.remove('is-over');
			if (event.dataTransfer) {
				handle(event.dataTransfer.files);
			}
		});
	}

	/* --- add another banner ---------------------------------------------- */

	addBtn.addEventListener('click', function () {
		if (totalFiles() >= limit || rows().length >= limit) {
			/* Rows without a file do not count toward the server cap, but one
			   more empty row than the cap is only confusing, so stop here. */
			say('The limit is ' + limit + ' banners per upload. Finish this set, then upload more in another run.', 'warn');
			syncAddButton();
			return;
		}
		newRow();
		reindex();
		syncAddButton();
	});

	syncAddButton();
	reindex();
}());
