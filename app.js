/*
	Anamanta solar calendar - builder page behaviour.

	Kept as a same-origin static file (rather than inline <script>) so the page
	can run under a Content-Security-Policy with no 'unsafe-inline' in
	script-src. Three independent behaviours, each a no-op if its markup is not
	present on the page:

	1. Timezone field: clears on focus so the full <datalist> is visible
	   (browsers filter datalist suggestions against the field's current text,
	   so a pre-filled value otherwise hides everything but itself), and
	   restores the previous value on blur if nothing new was picked.
	2. Copy-address button: copies the feed URL to the clipboard, with a
	   window.prompt() fallback for browsers/contexts without clipboard access.
	3. Empty override_* fields: a plain GET form serializes every named field
	   on submit, blank or not -- only unchecked checkboxes are dropped. Left
	   alone, that puts a no-op "override_noon=" (etc.) on the built URL for
	   every fixed-time field the person didn't fill in (issue #20). Disabling
	   an empty one right before the browser reads the form for submission
	   excludes it from that read entirely -- a disabled field is the one kind
	   of control GET serialization always omits, so this needs no follow-up
	   re-enabling: the click that triggered it also navigates the page away.
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

	var form = document.querySelector('form[method="get"]');
	if ( form ) {
		form.addEventListener('submit', function () {
			var overrides = form.querySelectorAll('input[type="time"][name^="override_"]');
			for ( var i = 0; i < overrides.length; i++ ) {
				if ( overrides[i].value === '' ) {
					overrides[i].disabled = true;
				}
			}
		});
	}
})();
