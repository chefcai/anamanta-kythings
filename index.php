<?php
/*
	Anamanta solar calendar - subscription URL builder.

	Self-contained: plain PHP, a little CSS, no JS framework, no build step, no
	Composer. The only external call is an optional geocoding lookup, which
	degrades to manual lat/long entry if it fails for any reason.

	Ref BRAIN-49 / BRAIN-50.
*/

// Base URL the generated feed lives at. Overridable so the page is testable
// outside production without trusting the Host header, which a caller controls.
$BASE_URL = rtrim(getenv('KYTHINGS_BASE_URL') ?: 'https://kythings.walkowiaks.com', '/');

const GEOCODER_ENDPOINT = 'https://nominatim.openstreetmap.org/search';
// Nominatim's usage policy requires a descriptive, contactable User-Agent.
// Sending a generic or absent one is how you get the whole host blocked.
const GEOCODER_UA       = 'AnamantaSolarCalendar/1.0 (+https://kythings.walkowiaks.com)';
const GEOCODER_TIMEOUT  = 6;

/* ------------------------------------------------------------------ input -- */

$submitted = isset($_GET['build']);

/*
	Real defaults, not placeholder text.

	These are rendered into value="" so the form arrives pre-filled and a bare
	submit produces a working feed. Placeholder attributes are only a greyed-out
	hint - they look like content but submit as empty, which is why hitting
	Build without typing anything used to fail.
*/
const DEFAULT_PLACE  = 'Worthington, MA';
const DEFAULT_TZ     = 'America/New_York';
const DEFAULT_LENGTH = '5';

// On a submitted form take exactly what was sent, so a field the user cleared
// on purpose stays cleared. Only a first visit gets the defaults.
$in_place  = $submitted ? trim((string)($_GET['place']  ?? '')) : DEFAULT_PLACE;
$in_lat    = $submitted ? trim((string)($_GET['lat']    ?? '')) : '';
$in_lng    = $submitted ? trim((string)($_GET['lng']    ?? '')) : '';
$in_tz     = $submitted ? trim((string)($_GET['tz']     ?? '')) : DEFAULT_TZ;
$in_length = $submitted ? trim((string)($_GET['length'] ?? '')) : DEFAULT_LENGTH;

// A bare submit with nothing filled in should still work rather than scold.
if ( $submitted ){
	if ( $in_place === '' && $in_lat === '' && $in_lng === '' ){ $in_place  = DEFAULT_PLACE; }
	if ( $in_tz === '' )                                       { $in_tz     = DEFAULT_TZ; }
	if ( $in_length === '' )                                   { $in_length = DEFAULT_LENGTH; }
}

/*
	All four ticked by default - these are the four core Anamanta times.

	Unchecked boxes send nothing at all, so "no t_* parameters" is ambiguous: it
	means either "the user unticked everything" or "this request did not come
	from the form". The form always sends a hidden `form=1`, which tells the two
	apart. Without it we are looking at a hand-typed or bare URL, and defaults
	apply.
*/
$from_form = isset($_GET['form']);

$want = array(
	'sunrise'  => ( $submitted && $from_form ) ? isset($_GET['t_sunrise'])  : true,
	'noon'     => ( $submitted && $from_form ) ? isset($_GET['t_noon'])     : true,
	'sunset'   => ( $submitted && $from_form ) ? isset($_GET['t_sunset'])   : true,
	'midnight' => ( $submitted && $from_form ) ? isset($_GET['t_midnight']) : true,
);

$errors  = array();
$notes   = array();
$result  = null;
$resolved_label = '';

/* -------------------------------------------------------------- helpers -- */

function e($s) {
	return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/*
	Resolve a free-text place to coordinates.

	Returns array('lat'=>float,'lng'=>float,'label'=>string) or false. Never
	throws and never blocks for long: any failure returns false and the caller
	falls back to manual entry.
*/
function geocode($query) {
	$url = GEOCODER_ENDPOINT . '?' . http_build_query(array(
		'q'              => $query,
		'format'         => 'jsonv2',
		'limit'          => 1,
		'addressdetails' => 0,
	));

	$body = false;

	if ( function_exists('curl_init') ){
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => GEOCODER_TIMEOUT,
			CURLOPT_CONNECTTIMEOUT => GEOCODER_TIMEOUT,
			CURLOPT_USERAGENT      => GEOCODER_UA,
			CURLOPT_FOLLOWLOCATION => false,
		));
		$body = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ( $body === false || $code !== 200 ){ $body = false; }
	} else {
		$ctx = stream_context_create(array('http' => array(
			'method'  => 'GET',
			'header'  => "User-Agent: " . GEOCODER_UA . "\r\n",
			'timeout' => GEOCODER_TIMEOUT,
		)));
		$body = @file_get_contents($url, false, $ctx);
	}

	if ( $body === false ){ return false; }

	$data = json_decode($body, true);
	if ( !is_array($data) || !isset($data[0]['lat']) || !isset($data[0]['lon']) ){
		return false;
	}

	return array(
		'lat'   => (float)$data[0]['lat'],
		'lng'   => (float)$data[0]['lon'],
		// Treated as untrusted: escaped at every point of output.
		'label' => isset($data[0]['display_name']) ? (string)$data[0]['display_name'] : $query,
	);
}

/*
	sun.php accepts `gmt` as a whole number of hours and has no `timezone`
	parameter, so an IANA name has to be reduced to an integer offset here.

	This loses nothing in practice. Every DTSTART in the feed is written as an
	absolute UTC instant, so calendar apps render the correct local time in the
	viewer's own zone all year, DST included. The offset only frames which local
	day each event is filed under. Standard time is used, and half-hour zones
	round to the nearest hour.
*/
function gmtOffsetForTimezone($tzname) {
	try {
		$tz  = new DateTimeZone($tzname);
		$jan = new DateTime('first day of january this year', $tz);
		return (int) round($tz->getOffset($jan) / 3600);
	} catch ( Exception $ex ){
		return null;
	}
}

/* ------------------------------------------------------------ processing -- */

if ( $submitted ){

	$lat = null;
	$lng = null;

	// A manual override always wins over the place lookup, so a bad geocode is
	// always recoverable by the user without a dependency on anything external.
	if ( $in_lat !== '' && $in_lng !== '' ){
		if ( !is_numeric($in_lat) || !is_numeric($in_lng) ){
			$errors[] = 'Latitude and longitude must be numbers.';
		} else {
			$lat = (float)$in_lat;
			$lng = (float)$in_lng;
			$resolved_label = 'entered manually';
		}
	} elseif ( $in_place !== '' ){
		$hit = geocode($in_place);
		if ( $hit === false ){
			$errors[] = 'Could not look that place up just now. Enter latitude and longitude directly below and try again '
			          . '- the page works fine without the lookup.';
		} else {
			$lat = $hit['lat'];
			$lng = $hit['lng'];
			$resolved_label = $hit['label'];
		}
	} else {
		$errors[] = 'Enter a town or city, or a latitude and longitude.';
	}

	if ( $lat !== null && ( $lat < -90 || $lat > 90 ) ){
		$errors[] = 'Latitude must be between -90 and 90.';
		$lat = null;
	}
	if ( $lng !== null && ( $lng < -180 || $lng > 180 ) ){
		$errors[] = 'Longitude must be between -180 and 180.';
		$lng = null;
	}

	$length = (int)$in_length;
	if ( $length < 1 || $length > 1440 ){
		$errors[] = 'Event length must be between 1 and 1440 minutes.';
	}

	$tzname = ( $in_tz === '' ) ? DEFAULT_TZ : $in_tz;
	if ( !in_array($tzname, timezone_identifiers_list(), true) ){
		$errors[] = 'Unrecognised timezone name. Pick one from the list, for example America/Los_Angeles.';
		$tzname = null;
	}

	$gmt = ( $tzname === null ) ? null : gmtOffsetForTimezone($tzname);
	if ( $tzname !== null && $gmt === null ){
		$errors[] = 'Could not work out a UTC offset for that timezone.';
	}

	if ( !$want['sunrise'] && !$want['noon'] && !$want['sunset'] && !$want['midnight'] ){
		$errors[] = 'Pick at least one event type.';
	}

	if ( !$errors && $lat !== null && $lng !== null && $gmt !== null ){

		$params = array(
			'lat'    => rtrim(rtrim(number_format($lat, 6, '.', ''), '0'), '.'),
			'lng'    => rtrim(rtrim(number_format($lng, 6, '.', ''), '0'), '.'),
			'gmt'    => $gmt,
			'length' => $length,
		);

		$query = http_build_query($params);

		// The underlying feed emits sunrise and sunset together under a single
		// `actual` flag - there is no way to ask for one without the other.
		if ( $want['sunrise'] || $want['sunset'] ){
			$query .= '&actual';
			if ( $want['sunrise'] xor $want['sunset'] ){
				$notes[] = 'Sunrise and sunset are produced as a pair by the calendar feed, so both are included. '
				         . 'You can hide the one you do not want in your calendar app.';
			}
		}
		if ( $want['noon'] ){     $query .= '&noon'; }
		if ( $want['midnight'] ){ $query .= '&midnight'; }

		$feed_url = $BASE_URL . '/sun.php?' . $query;

		// webcal:// - macOS and iOS hand this straight to Calendar.
		$webcal_url = preg_replace('#^https?://#', 'webcal://', $feed_url);

		/*
			Google Calendar one-click subscribe. Verified working on desktop web,
			signed in: Google shows an "Add calendar" confirmation carrying the
			full untruncated feed URL.

			Two things both have to be true or it fails, which is why this is
			built here rather than hand-assembled:

			1. The cid value must use the webcal:// scheme. Given https://,
			   Google reads it as an account identifier instead of a feed.
			2. The whole webcal:// string is percent-encoded exactly ONCE, so the
			   inner & become %26 and ? becomes %3F. Without that, Google's outer
			   query parser stops at the first raw &, and this feed has seven
			   parameters - it would receive only "...sun.php?lat=42.395999".

			Caveat that no amount of URL correctness fixes: subscribing to an
			external calendar is desktop-web only. Google's own help states you
			cannot do it in the Android or iOS app. On a phone this link just
			opens Google Calendar. The page says so plainly next to the button.
		*/
		$google_url = 'https://calendar.google.com/calendar/render?cid=' . rawurlencode($webcal_url);

		// Documented fallback if render misbehaves for a given account.
		$google_alt = 'https://calendar.google.com/calendar/u/0/r/settings/addcalendar?cid=' . rawurlencode($webcal_url);

		/*
			Outlook on the web. Same encoding rules as Google - the feed URL is a
			value inside another query string, so it is encoded once.

			Unlike the Google link, this one is NOT verified working. Outlook
			returns HTTP 417 to any unauthenticated request, including to paths
			that certainly exist, so probing it from outside a signed-in session
			proves nothing either way. The URL form is Microsoft's documented one
			and the button is offered on that basis, with the manual address kept
			directly underneath so a failure costs the user one paste.
		*/
		$outlook_url = 'https://outlook.live.com/calendar/0/addfromweb?url=' . rawurlencode($webcal_url)
		             . '&name=' . rawurlencode('Anamanta Kythings');

		$result = array(
			'feed'    => $feed_url,
			'google'  => $google_url,
			'googlealt' => $google_alt,
			'outlook' => $outlook_url,
			'webcal'  => $webcal_url,
			'tz'     => $tzname,
			'gmt'    => $gmt,
			'lat'    => $params['lat'],
			'lng'    => $params['lng'],
		);
	}
}

$tz_list = timezone_identifiers_list();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Anamanta Solar Calendar &mdash; build your subscription link</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600&display=swap" rel="stylesheet">
<style>
	:root { --ink:#1d2021; --dim:#5f6769; --line:#d8d4cc; --bg:#f2f8f4; --accent:#1d5c2e; --warm:#e8f3ec; }
	* { box-sizing:border-box; }
	body { margin:0; padding:2rem 1rem 4rem; background:var(--bg); color:var(--ink);
	       font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif; }
	.wrap { max-width:41rem; margin:0 auto; }
	h1 { font-family:'Cinzel',Georgia,serif; font-weight:600; font-size:1.6rem; margin:0 0 .3rem; letter-spacing:-.01em; }
	h2 { font-family:'Cinzel',Georgia,serif; font-weight:500; font-size:1.05rem; margin:2.2rem 0 .6rem; }
	h3 { font-size:.95rem; margin:1.3rem 0 .3rem; }
	.sub { color:var(--dim); margin:0 0 2rem; }
	fieldset { border:1px solid var(--line); border-radius:8px; padding:1rem 1.1rem 1.2rem; margin:0 0 1.1rem; background:#fff; }
	legend { padding:0 .4rem; font-weight:600; font-size:.9rem; }
	label { display:block; margin:.7rem 0 .2rem; font-size:.9rem; font-weight:600; }
	.hint { font-weight:400; color:var(--dim); font-size:.85rem; }
	input[type=text], input[type=number] { width:100%; padding:.55rem .6rem; border:1px solid var(--line);
	       border-radius:6px; font-size:1rem; background:#fff; color:var(--ink); }
	input:focus { outline:2px solid var(--accent); outline-offset:1px; }
	.row { display:flex; gap:.8rem; } .row > div { flex:1; }
	.checks label { display:flex; align-items:center; gap:.55rem; font-weight:500; margin:.45rem 0; }
	.checks input { width:1.05rem; height:1.05rem; }
	button { background:var(--accent); color:#fff; border:0; border-radius:6px; padding:.7rem 1.4rem;
	       font-size:1rem; font-weight:600; cursor:pointer; }
	button:hover { filter:brightness(1.12); }
	.err, .note { border-radius:6px; padding:.7rem .9rem; margin:0 0 .9rem; font-size:.92rem; }
	.err { background:#fdecea; border:1px solid #e5b3ad; }
	.note { background:var(--warm); border:1px solid var(--line); }
	.warn { background:#fff6e0; border:1px solid #e0c98a; border-radius:6px; padding:.7rem .9rem; font-size:.92rem; margin:.2rem 0 .9rem; }
	.btnrow { display:flex; flex-wrap:wrap; gap:.6rem; margin:.2rem 0 .8rem; }
	.btnrow .btnlink { flex:1 1 auto; text-align:center; white-space:nowrap; }
	.out { background:#fff; border:1px solid var(--line); border-radius:8px; padding:1.1rem; }
	code, .url { font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:.82rem; }
	.url { display:block; width:100%; word-break:break-all; background:var(--warm); border:1px solid var(--line);
	       border-radius:6px; padding:.7rem; margin:.3rem 0 .9rem; }
	.btnlink { display:inline-block; background:var(--accent); color:#fff; text-decoration:none;
	       padding:.6rem 1.1rem; border-radius:6px; font-weight:600; font-size:.95rem; }
	.meta { color:var(--dim); font-size:.86rem; }
	ol, ul { padding-left:1.2rem; } li { margin:.3rem 0; }
	details { margin:.5rem 0; } summary { cursor:pointer; font-weight:600; font-size:.93rem; }
	a { color:var(--accent); }
	hr { border:0; border-top:1px solid var(--line); margin:2rem 0; }
</style>
</head>
<body>
<div class="wrap">

<h1>Anamanta Solar Calendar</h1>
<p class="sub">Build a calendar you can subscribe to that marks the four solar times each day &mdash;
sunrise, solar noon, sunset and solar midnight &mdash; wherever you are.</p>

<?php foreach ( $errors as $err ): ?>
	<p class="err"><?php echo e($err); ?></p>
<?php endforeach; ?>

<form method="get" action="">
<input type="hidden" name="build" value="1">
<input type="hidden" name="form" value="1">

<fieldset>
	<legend>Where you are</legend>

	<label for="place">Town or city
		<span class="hint">&mdash; for example &ldquo;Worthington, MA&rdquo;</span></label>
	<input type="text" id="place" name="place" value="<?php echo e($in_place); ?>" placeholder="Worthington, MA">
	<p class="hint" style="margin:.35rem 0 0;">A town is all you need. Solar times vary by only seconds across a town,
		so a street address gives an identical calendar.</p>

	<details>
		<summary>Or enter coordinates directly</summary>
		<p class="hint" style="margin:.5rem 0;">Use these if the lookup above cannot find your town.
		<a href="https://www.latlong.net/" target="_blank" rel="noopener noreferrer">Find your latitude and
		longitude</a>. If you fill these in they take precedence over the town name.</p>
		<div class="row">
			<div>
				<label for="lat">Latitude</label>
				<input type="text" id="lat" name="lat" value="<?php echo e($in_lat); ?>" placeholder="42.396">
			</div>
			<div>
				<label for="lng">Longitude</label>
				<input type="text" id="lng" name="lng" value="<?php echo e($in_lng); ?>" placeholder="-72.936">
			</div>
		</div>
	</details>

	<label for="tz">Timezone <span class="hint">&mdash; IANA name, for example America/Los_Angeles</span></label>
	<input type="text" id="tz" name="tz" list="tzlist" value="<?php echo e($in_tz); ?>" placeholder="<?php echo e(DEFAULT_TZ); ?>"
		autocomplete="off">
	<datalist id="tzlist">
		<?php foreach ( $tz_list as $tz ): ?><option value="<?php echo e($tz); ?>"><?php endforeach; ?>
	</datalist>
	<p class="hint" style="margin:.35rem 0 0;">Click the field to see the full list of <?php echo count($tz_list); ?> timezones,
		or start typing to search it. Full list also at
		<a href="https://www.php.net/manual/en/timezones.php" target="_blank" rel="noopener noreferrer">php.net/timezones</a>.
		This only decides which day each event is filed under &mdash; your calendar app always shows the times in your own
		timezone, and handles daylight saving for you.</p>
</fieldset>

<fieldset>
	<legend>What to include</legend>
	<div class="checks">
		<label><input type="checkbox" name="t_sunrise"  value="1" <?php echo $want['sunrise']  ? 'checked' : ''; ?>> Sunrise</label>
		<label><input type="checkbox" name="t_noon"     value="1" <?php echo $want['noon']     ? 'checked' : ''; ?>> Solar noon</label>
		<label><input type="checkbox" name="t_sunset"   value="1" <?php echo $want['sunset']   ? 'checked' : ''; ?>> Sunset</label>
		<label><input type="checkbox" name="t_midnight" value="1" <?php echo $want['midnight'] ? 'checked' : ''; ?>> Solar midnight</label>
	</div>
	<p class="hint" style="margin:.6rem 0 0;">Sunrise and sunset come as a pair from the feed, so ticking either includes both.</p>

	<label for="length">Event length in minutes</label>
	<input type="number" id="length" name="length" min="1" max="1440" value="<?php echo e($in_length); ?>">
	<p class="hint" style="margin:.35rem 0 0;">Five minutes is the default, which is what the practice calls for.</p>
</fieldset>

<button type="submit">Build my calendar link</button>
</form>

<?php if ( $result !== null ): ?>
<hr>
<h2>Your calendar link</h2>

<?php foreach ( $notes as $n ): ?>
	<p class="note"><?php echo e($n); ?></p>
<?php endforeach; ?>

<div class="out">
	<p class="meta" style="margin-top:0;">
		Location: <strong><?php echo e($resolved_label); ?></strong><br>
		Coordinates: <code><?php echo e($result['lat']); ?>, <?php echo e($result['lng']); ?></code>
		&nbsp;&middot;&nbsp; Timezone: <code><?php echo e($result['tz']); ?></code>
		(UTC<?php echo $result['gmt'] >= 0 ? '+' : ''; ?><?php echo e($result['gmt']); ?>)
	</p>
	<p class="meta"><em>If that is not the right place, adjust the form above or enter coordinates directly.</em></p>

	<h3 style="margin-top:0;">Subscribe</h3>
	<p class="warn"><strong>On mobile?</strong> Open this page on a computer to subscribe &mdash; it will then show up
		automatically on your phone. Google and Outlook do not allow adding an outside calendar from their phone apps.
		Apple Calendar is the exception: the Apple button below does work directly on an iPhone or iPad.</p>

	<p class="btnrow">
		<a class="btnlink" href="<?php echo e($result['google']); ?>" target="_blank" rel="noopener noreferrer">Add to Google Calendar</a>
		<a class="btnlink" href="<?php echo e($result['webcal']); ?>">Add to Apple Calendar</a>
		<a class="btnlink" href="<?php echo e($result['outlook']); ?>" target="_blank" rel="noopener noreferrer">Add to Outlook</a>
	</p>

	<p class="meta">
		<strong>Google</strong> shows an <em>Add calendar</em> confirmation with the address &mdash; click <em>Add</em>.
		If nothing happens, try the <a href="<?php echo e($result['googlealt']); ?>" target="_blank" rel="noopener noreferrer">alternate link</a>.
		<strong>Apple</strong> hands off to the Calendar app and asks you to confirm.
		<strong>Outlook</strong> opens Outlook on the web with the address pre-filled.
		If any button does not do what you expect, use the address below &mdash; it works everywhere and takes one paste.
	</p>

	<h3>Your calendar address</h3>
	<p class="meta" style="margin:.2rem 0 .3rem;">For any other app, or to add it to Google by hand.</p>
	<code class="url" id="feedurl"><?php echo e($result['feed']); ?></code>
	<p><button type="button" class="btnlink" id="copybtn" style="border:0;cursor:pointer;">Copy address</button></p>
</div>

<h2>How to subscribe by hand</h2>

<h3>Google Calendar (desktop browser only)</h3>
<ol>
	<li>In Google Calendar, go to Settings &rarr; Add calendar &rarr; <em>From URL</em>.</li>
	<li>Paste the address above into <em>URL of calendar</em>.</li>
	<li>Click <em>Add calendar</em>.</li>
</ol>

<h3>Apple Calendar</h3>
<ol>
	<li>Mac or iPhone: open this link and confirm &mdash;
		<a href="<?php echo e($result['webcal']); ?>">subscribe in Apple Calendar</a>.</li>
	<li>Or on a Mac: File &rarr; New Calendar Subscription, then paste the address.</li>
	<li>Or on iPhone or iPad: Settings &rarr; Apps &rarr; Calendar &rarr; Accounts &rarr; Add Account &rarr; Other &rarr; Add Subscribed Calendar.</li>
</ol>

<h3>Outlook</h3>
<ol>
	<li>Add calendar &rarr; Subscribe from web, paste the address and give it a name.</li>
</ol>

<h2>How far ahead it goes</h2>
<p>The calendar covers about <strong>18 months ahead</strong> and a month behind, and that window moves forward
	every time your calendar app refreshes it. It does not stop at the end of the year &mdash; on 31 December you can
	already see January, and you never need to re-subscribe.</p>

<h2>Two things worth knowing</h2>
<ul>
	<li><strong>Updates are not instant.</strong> Google refreshes subscribed calendars roughly every 12 to 24 hours,
		and other apps are similar. If something changes, give it a day before assuming it did not work.</li>
	<li><strong>Set your own reminder if you want an alert.</strong> Google Calendar generally ignores reminders built
		into a subscribed calendar. If you want to be notified rather than just seeing a coloured block, open the
		subscribed calendar's own settings in your calendar app and set a default notification there. You only need to
		do this once.</li>
</ul>

<?php endif; ?>
<script src="app.js" defer></script>

</div>
</body>
</html>
