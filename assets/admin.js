/**
 * Crypto Banner Ads - settings screen helpers.
 *
 * Keeps the copy-paste snippet in sync with the chosen slot size so nobody has
 * to hand-edit dimensions.
 */
(function () {
	'use strict';

	var picker = document.getElementById('dwads-slot-picker');
	var snippet = document.getElementById('dwads-snippet');
	var shortcode = document.getElementById('dwads-shortcode');

	if (!picker || !snippet) {
		return;
	}

	function update() {
		var value = picker.value;
		if (!value) {
			return;
		}
		snippet.value = snippet.value.replace(/data-dwads-slot="[^"]*"/, 'data-dwads-slot="' + value + '"');
		if (shortcode) {
			shortcode.value = '[dwads slot="' + value + '"]';
		}
	}

	picker.addEventListener('change', update);
	update();
})();
