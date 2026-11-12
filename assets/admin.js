/**
 * Crypto Banner Ads - settings screen helpers.
 *
 * Keeps the copy-paste snippet in sync with the chosen slot size so nobody has
 * to hand-edit dimensions.
 */
(function () {
	'use strict';

	var picker = document.getElementById('dwads-slot-picker');
	var mobilePicker = document.getElementById('dwads-slot-mobile-picker');
	var snippet = document.getElementById('dwads-snippet');
	var shortcode = document.getElementById('dwads-shortcode');

	if (!picker || !snippet) {
		return;
	}

	function update() {
		var wide = picker.value;
		if (!wide) {
			return;
		}
		var mobile = mobilePicker ? mobilePicker.value : '';
		if (mobile === wide) {
			mobile = '';
		}

		var html = snippet.value
			.replace(/data-dwads-slot="[^"]*"/, 'data-dwads-slot="' + wide + '"')
			.replace(/\s*data-dwads-slot-mobile="[^"]*"/g, '');

		if (mobile) {
			html = html.replace(
				'data-dwads-slot="' + wide + '"',
				'data-dwads-slot="' + wide + '" data-dwads-slot-mobile="' + mobile + '"'
			);
		}
		snippet.value = html;

		if (shortcode) {
			shortcode.value = '[dwads slot="' + wide + '"]' + (mobile ? ' slot_mobile="' + mobile + '"' : '');
		}
	}

	picker.addEventListener('change', update);
	if (mobilePicker) {
		mobilePicker.addEventListener('change', update);
	}
	update();
})();
