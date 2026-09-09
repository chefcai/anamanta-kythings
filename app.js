/*
	Anamanta solar calendar - builder page behaviour.

	Kept as a same-origin static file (rather than inline <script>) so the page
	can run under a Content-Security-Policy with no 'unsafe-inline' in
	script-src. Two independent behaviours, each a no-op if its markup is not
	present on the page:

	1. Timezone field: clears on focus so the full <datalist> is visible
	   (browsers filter datalist suggestions against the field's current text,
	   so a pre-filled value otherwise hides everything but itself), and
	   restores the previous value on blur if nothing new was picked.
	2. Copy-address button: copies the feed URL to the clipboard, with a
	   window.prompt() fallback for browsers/contexts without clipboard access.
*/
(function () {
	var tz = document.getElementById('tz');
	if ( tz ) {
		tz.addEventListener('focus', function () {
			tz.dataset.prevTz = tz.value;
			tz.value = '';
		});
		tz.addEventListener('blur', function () {
			if ( tz.value === '' ) {
				tz.value = tz.dataset.prevTz || '';
			}
		});
	}

	var btn = document.getElementById('copybtn');
	var src = document.getElementById('feedurl');
	if ( btn && src ) {
		btn.addEventListener('click', function () {
			var text = src.textContent.trim();
			var done = function () { btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = 'Copy address'; }, 2000); };
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText(text).then(done, function () { window.prompt('Copy this address:', text); });
			} else {
				window.prompt('Copy this address:', text);
			}
		});
	}
})();
