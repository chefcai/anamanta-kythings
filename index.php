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

$in_place    = isset($_GET['place'])    ? trim((string)$_GET['place'])    : '';
$in_lat      = isset($_GET['lat'])      ? trim((string)$_GET['lat'])      : '';
$in_lng      = isset($_GET['lng'])      ? trim((string)$_GET['lng'])      : '';
$in_tz       = isset($_GET['tz'])       ? trim((string)$_GET['tz'])       : '';
$in_length   = isset($_GET['length'])   ? trim((string)$_GET['length'])   : '5';

// All four ticked by default - these are the four core Anamanta times. On a
// submitted form we read what was actually ticked instead.
$want = array(
	'sunrise'  => $submitted ? isset($_GET['t_sunrise'])  : true,
	'noon'     => $submitted ? isset($_GET['t_noon'])     : true,
	'sunset'   => $submitted ? isset($_GET['t_sunset'])   : true,
	'midnight' => $submitted ? isset($_GET['t_midnight']) : true,
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

	$tzname = $in_tz;
	if ( $tzname === '' ){
		$tzname = 'UTC';
		$notes[] = 'No timezone given, so UTC was assumed. This does not change the times you see - '
		         . 'your calendar app converts them to your own timezone either way.';
	}
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

		$feed_url   = $BASE_URL . '/sun.php?' . $query;
		$google_url = 'https://calendar.google.com/calendar/render?cid=' . rawurlencode($feed_url);
		$webcal_url = preg_replace('#^https?://#', 'webcal://', $feed_url);

		$result = array(
			'feed'   => $feed_url,
			'google' => $google_url,
			'webcal' => $webcal_url,
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
<style>
	:root { --ink:#1d2021; --dim:#5f6769; --line:#d8d4cc; --bg:#faf8f4; --accent:#7a4b12; --warm:#f2ece0; }
	* { box-sizing:border-box; }
	body { margin:0; padding:2rem 1rem 4rem; background:var(--bg); color:var(--ink);
	       font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif; }
	.wrap { max-width:41rem; margin:0 auto; }
	h1 { font-size:1.6rem; margin:0 0 .3rem; letter-spacing:-.01em; }
	h2 { font-size:1.05rem; margin:2.2rem 0 .6rem; }
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
	<input type="text" id="tz" name="tz" list="tzlist" value="<?php echo e($in_tz); ?>" placeholder="America/Los_Angeles">
	<datalist id="tzlist">
		<?php foreach ( $tz_list as $tz ): ?><option value="<?php echo e($tz); ?>"><?php endforeach; ?>
	</datalist>
	<p class="hint" style="margin:.35rem 0 0;">Full list at
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

	<h3>One-click for Google Calendar</h3>
	<p><a class="btnlink" href="<?php echo e($result['google']); ?>" target="_blank" rel="noopener noreferrer">Subscribe in Google Calendar</a></p>

	<h3>Apple Calendar / Outlook</h3>
	<p class="meta" style="margin:.2rem 0 .3rem;">Copy this address and add it as a subscribed calendar:</p>
	<code class="url"><?php echo e($result['feed']); ?></code>

	<p class="meta" style="margin:.2rem 0 .3rem;">On a Mac or iPhone this link usually opens Calendar directly:</p>
	<code class="url"><?php echo e($result['webcal']); ?></code>
</div>

<h2>How to subscribe</h2>

<h3>Google Calendar</h3>
<ol>
	<li>Use the button above, or go to Settings &rarr; Add calendar &rarr; <em>From URL</em>.</li>
	<li>Paste the address and click <em>Add calendar</em>.</li>
</ol>

<h3>Apple Calendar</h3>
<ol>
	<li>Mac: File &rarr; New Calendar Subscription, then paste the address.</li>
	<li>iPhone or iPad: Settings &rarr; Apps &rarr; Calendar &rarr; Accounts &rarr; Add Account &rarr; Other &rarr; Add Subscribed Calendar.</li>
</ol>

<h3>Outlook</h3>
<ol>
	<li>Go to Add calendar &rarr; Subscribe from web, paste the address and give it a name.</li>
</ol>

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

</div>
</body>
</html>
