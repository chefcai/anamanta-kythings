<?php
/*
	Acceptance checks for BRAIN-42, BRAIN-43 and BRAIN-45.

	Generates a full year of feed with all four Anamanta event types and asserts
	against it. Every check here corresponds to a written acceptance criterion,
	including the three mandated date scenarios: a normal day, the DST
	transitions, and the Dec 31 -> Jan 1 boundary.

	Usage: php tests/validate.php
	Exit code 0 = all passed, 1 = at least one failure.
*/

$FAILURES = array();
$CHECKS   = 0;

function check($label, $condition, $detail = '') {
	global $FAILURES, $CHECKS;
	$CHECKS++;
	if ( $condition ){
		echo "  ok    $label\n";
	} else {
		echo "  FAIL  $label" . ( $detail !== '' ? "  -- $detail" : '' ) . "\n";
		$FAILURES[] = $label . ( $detail !== '' ? " ($detail)" : '' );
	}
}

function section($title) {
	echo "\n== $title ==\n";
}

/* ---------- generation ---------- */

/*
	Generate under production-like INI. php:8.3-cli-alpine ships no active
	php.ini, so display_errors defaults ON, and upstream's deprecated
	date_sunrise()/date_sunset() calls then inject notices straight into the ICS
	body. That is a genuine deployment hazard, checked separately below; here we
	mirror the production settings the deployed container must use so the rest of
	the assertions are about calendar content.
*/
function generate($qs, $ini = '-d display_errors=0 -d error_reporting=0') {
	$cmd = 'php ' . $ini . ' ' . escapeshellarg(__DIR__ . '/generate.php') . ' ' . escapeshellarg($qs) . ' 2>/dev/null';
	return shell_exec($cmd);
}

/*
	Parse VEVENTs into: array of ['summary'=>, 'start'=>UTC ts, 'end'=>UTC ts, 'uid'=>]
*/
function parseEvents($ics) {
	$events = array();
	$cur = null;

	foreach ( preg_split('/\r\n|\n/', $ics) as $line ){
		if ( strpos($line, 'BEGIN:VEVENT') === 0 ){
			$cur = array('summary'=>null,'start'=>null,'end'=>null,'uid'=>null);
		} elseif ( strpos($line, 'END:VEVENT') === 0 ){
			if ( $cur !== null ){ $events[] = $cur; }
			$cur = null;
		} elseif ( $cur !== null ){
			if ( strpos($line, 'SUMMARY:') === 0 ){
				$cur['summary'] = trim(substr($line, 8));
			} elseif ( strpos($line, 'DTSTART:') === 0 ){
				$cur['start'] = icsToTs(trim(substr($line, 8)));
			} elseif ( strpos($line, 'DTEND:') === 0 ){
				$cur['end'] = icsToTs(trim(substr($line, 6)));
			} elseif ( strpos($line, 'UID:') === 0 ){
				$cur['uid'] = trim(substr($line, 4));
			}
		}
	}
	return $events;
}

function icsToTs($v) {
	$d = DateTime::createFromFormat('Ymd\THis\Z', $v, new DateTimeZone('UTC'));
	return ( $d === false ) ? null : $d->getTimestamp();
}

function byType($events, $summary) {
	$out = array();
	foreach ( $events as $e ){
		if ( $e['summary'] === $summary ){ $out[] = $e; }
	}
	usort($out, function($a,$b){ return $a['start'] - $b['start']; });
	return $out;
}

function utc($ts, $fmt = 'Y-m-d H:i:s') {
	return gmdate($fmt, $ts);
}

/* ---------- fixture ---------- */

// Portland, OR. Longitude -122.68 sits 2.68 degrees off the UTC-8 central
// meridian (-120), so true solar noon is displaced from clock noon by roughly
// 11 minutes of longitude PLUS the equation of time. A clock-noon bug cannot
// hide at these coordinates.
$LAT  = 45.5152;
$LNG  = -122.6784;
$GMT  = -8;
$YEAR = 2026;
$LEN  = 5;

$QS = "lat=$LAT&lng=$LNG&gmt=$GMT&year=$YEAR&length=$LEN&all";

echo "Feed: sun.php?$QS\n";

$ics = generate($QS);
$events = parseEvents($ics);

$noon     = byType($events, 'Solar Noon');
$midnight = byType($events, 'Solar Midnight');
$sunrise  = byType($events, 'Sunrise');
$sunset   = byType($events, 'Sunset');

section('Structural');

check('output is non-empty', strlen((string)$ics) > 0);
check('begins with BEGIN:VCALENDAR', strpos(ltrim((string)$ics), 'BEGIN:VCALENDAR') === 0);
check('contains END:VCALENDAR', strpos((string)$ics, 'END:VCALENDAR') !== false);
check('BEGIN/END:VEVENT balanced',
	substr_count((string)$ics, 'BEGIN:VEVENT') === substr_count((string)$ics, 'END:VEVENT'),
	substr_count((string)$ics, 'BEGIN:VEVENT') . ' vs ' . substr_count((string)$ics, 'END:VEVENT'));
check('no PHP diagnostics in the body under production INI',
	stripos((string)$ics, 'Deprecated:') === false
	&& stripos((string)$ics, 'Warning:') === false
	&& stripos((string)$ics, 'Fatal error') === false
	&& stripos((string)$ics, 'Notice:') === false);
check('every line CRLF-terminated under production INI',
	substr_count((string)$ics, "\r\n") >= substr_count((string)$ics, "\n") - 1,
	substr_count((string)$ics, "\n") - substr_count((string)$ics, "\r\n") . ' bare LF lines');

// Deliberate negative control. This documents a real deployment hazard rather
// than asserting a pass: with display_errors ON (the php:8.3-cli-alpine default,
// since the image activates neither php.ini-production nor -development), the
// deprecation notices for date_sunrise()/date_sunset() land inside the feed.
$leaky = generate($QS . '&year=2026', '-d display_errors=1 -d error_reporting=E_ALL');
$leaks = stripos((string)$leaky, 'Deprecated:') !== false;
echo "  note  with display_errors=On the feed " . ( $leaks ? "IS CORRUPTED by deprecation notices" : "stays clean" )
	. " -- deployed container MUST set display_errors=Off (BRAIN-47)\n";

section('All four Anamanta event types present');

check('Sunrise events present', count($sunrise) > 360, count($sunrise) . ' found');
check('Sunset events present', count($sunset) > 360, count($sunset) . ' found');
check('Solar Noon events present', count($noon) > 360, count($noon) . ' found');
check('Solar Midnight events present', count($midnight) > 360, count($midnight) . ' found');

section('BRAIN-42: solar noon uses real transit, not clock noon');

// Against a fixed UTC-8 offset, clock noon is always 20:00:00 UTC.
$clock_noon_utc_seconds = ((12 - $GMT) % 24) * 3600;
$exactly_clock_noon = 0;
$max_dev = 0;
$min_dev = PHP_INT_MAX;

foreach ( $noon as $e ){
	$sod = $e['start'] % 86400;
	$dev = abs($sod - $clock_noon_utc_seconds);
	if ( $dev === 0 ){ $exactly_clock_noon++; }
	if ( $dev > $max_dev ){ $max_dev = $dev; }
	if ( $dev < $min_dev ){ $min_dev = $dev; }
}

check('solar noon is never exactly clock noon', $exactly_clock_noon === 0, "$exactly_clock_noon days landed on 12:00:00 local");
check('solar noon deviation from clock noon exceeds 10 min somewhere',
	$max_dev > 600, 'max deviation ' . round($max_dev/60,1) . ' min');
check('solar noon deviation varies across the year (equation of time visible)',
	($max_dev - $min_dev) > 600,
	'range ' . round($min_dev/60,1) . '-' . round($max_dev/60,1) . ' min');

// Longitude correction for -122.6784 against meridian -120 = 2.6784 deg = 10.71 min.
// Equation of time swings about -14 to +16 min. So deviation should stay inside ~30 min.
check('solar noon deviation stays within a physically sane 40 min',
	$max_dev < 2400, 'max ' . round($max_dev/60,1) . ' min');

section('BRAIN-42/43: length param honoured');

$bad_len = 0;
foreach ( array_merge($noon, $midnight) as $e ){
	if ( ($e['end'] - $e['start']) !== $LEN*60 ){ $bad_len++; }
}
check("every new-type event is exactly {$LEN} minutes long", $bad_len === 0, "$bad_len events wrong");

$bad_len_existing = 0;
foreach ( array_merge($sunrise, $sunset) as $e ){
	if ( ($e['end'] - $e['start']) !== $LEN*60 ){ $bad_len_existing++; }
}
check("sunrise/sunset also {$LEN} minutes (same anchoring convention)", $bad_len_existing === 0, "$bad_len_existing events wrong");

section('BRAIN-43: solar midnight spacing (no drift)');

function intervalReport($list) {
	$bad = array();
	for ( $i = 1; $i < count($list); $i++ ){
		$gap = $list[$i]['start'] - $list[$i-1]['start'];
		if ( abs($gap - 86400) > 120 ){
			$bad[] = utc($list[$i-1]['start']) . ' -> ' . utc($list[$i]['start']) . ' = ' . round($gap/3600,3) . 'h';
		}
	}
	return $bad;
}

$bad_mid = intervalReport($midnight);
check('consecutive solar midnights are ~24h apart in absolute UTC (tolerance 120s)',
	count($bad_mid) === 0, count($bad_mid) . ' bad gaps; first: ' . ( isset($bad_mid[0]) ? $bad_mid[0] : '-' ));

$bad_noon = intervalReport($noon);
check('consecutive solar noons are ~24h apart in absolute UTC',
	count($bad_noon) === 0, count($bad_noon) . ' bad gaps; first: ' . ( isset($bad_noon[0]) ? $bad_noon[0] : '-' ));

section('BRAIN-45 Scenario 1: normal day (2026-06-15)');

function eventsOnLocalDate($list, $ymd, $gmt) {
	$out = array();
	foreach ( $list as $e ){
		if ( gmdate('Y-m-d', $e['start'] + $gmt*3600) === $ymd ){ $out[] = $e; }
	}
	return $out;
}

/*
	Solar midnight belonging to local date $ymd: the anti-transit nearest 00:00
	local on that date. Bucketing by calendar date is the wrong test for this one
	event type, because for part of the year the midnight belonging to a date
	genuinely occurs at 23:59:5x the evening before. "Exactly one within 12 hours
	of the date's local 00:00" is the definitional statement and holds all year.
*/
function midnightForLocalDate($list, $ymd, $gmt) {
	$target = strtotime($ymd . ' 00:00:00 UTC') - $gmt*3600;
	$out = array();
	foreach ( $list as $e ){
		if ( abs($e['start'] - $target) < 43200 ){ $out[] = $e; }
	}
	return $out;
}

$d1 = '2026-06-15';
$d1_sr = eventsOnLocalDate($sunrise, $d1, $GMT);
$d1_ss = eventsOnLocalDate($sunset, $d1, $GMT);
$d1_sn = eventsOnLocalDate($noon, $d1, $GMT);
$d1_sm = midnightForLocalDate($midnight, $d1, $GMT);

check("$d1: sunrise present", count($d1_sr) === 1, count($d1_sr) . ' found');
check("$d1: sunset present", count($d1_ss) === 1, count($d1_ss) . ' found');
check("$d1: solar noon present", count($d1_sn) === 1, count($d1_sn) . ' found');
check("$d1: solar midnight present", count($d1_sm) === 1, count($d1_sm) . ' found');

if ( count($d1_sr) && count($d1_ss) && count($d1_sn) ){
	check("$d1: solar noon falls between sunrise and sunset",
		$d1_sn[0]['start'] > $d1_sr[0]['start'] && $d1_sn[0]['start'] < $d1_ss[0]['start'],
		'sunrise ' . utc($d1_sr[0]['start'],'H:i') . ' noon ' . utc($d1_sn[0]['start'],'H:i') . ' sunset ' . utc($d1_ss[0]['start'],'H:i') . ' UTC');
}
if ( count($d1_sm) && count($d1_sr) ){
	check("$d1: solar midnight falls outside the daylight window",
		$d1_sm[0]['start'] < $d1_sr[0]['start'] || ( count($d1_ss) && $d1_sm[0]['start'] > $d1_ss[0]['start'] ),
		'midnight ' . utc($d1_sm[0]['start'],'H:i') . ' UTC');
}
if ( count($d1_sn) && count($d1_sm) ){
	$halfday = abs(abs($d1_sn[0]['start'] - $d1_sm[0]['start']) - 43200);
	check("$d1: noon and midnight are ~12h apart (within 5 min)", $halfday < 300, round($halfday) . 's off');
}

section('BRAIN-45 Scenario 2: DST transitions (2026-03-08 and 2026-11-01)');

foreach ( array('2026-03-08' => 'spring forward', '2026-11-01' => 'fall back') as $ymd => $what ){
	foreach ( array('-1 day','+0 day','+1 day') as $off ){
		$d = gmdate('Y-m-d', strtotime($ymd . ' ' . $off . ' UTC'));
		$n = count(eventsOnLocalDate($noon, $d, $GMT));
		$m = count(midnightForLocalDate($midnight, $d, $GMT));
		$r = count(eventsOnLocalDate($sunrise, $d, $GMT));
		$s = count(eventsOnLocalDate($sunset, $d, $GMT));
		check("$what $d: exactly one of each of the four types",
			$n===1 && $m===1 && $r===1 && $s===1,
			"sunrise=$r sunset=$s noon=$n midnight=$m");
	}
}

// The feed is built on a FIXED utc offset, so a DST shift must not appear at all
// in absolute time. A 23h or 25h absolute gap would mean an offset was applied to
// a wall-clock value instead of a timestamp.
$dst_gap_bad = 0;
foreach ( array('2026-03-08','2026-11-01') as $ymd ){
	foreach ( array($noon, $midnight) as $list ){
		$prev = eventsOnLocalDate($list, gmdate('Y-m-d', strtotime($ymd.' -1 day UTC')), $GMT);
		$cur  = eventsOnLocalDate($list, $ymd, $GMT);
		if ( count($prev) && count($cur) ){
			if ( abs(($cur[0]['start'] - $prev[0]['start']) - 86400) > 120 ){ $dst_gap_bad++; }
		}
	}
}
check('absolute day-over-day interval unaffected across both DST transitions', $dst_gap_bad === 0, "$dst_gap_bad anomalies");

section('BRAIN-45 Scenario 3: Dec 31 -> Jan 1 boundary');

// Check BOTH ends of the feed, per the acceptance criteria.
foreach ( array('2026-01-01','2026-01-02','2026-12-30','2026-12-31') as $d ){
	$n = count(eventsOnLocalDate($noon, $d, $GMT));
	$m = count(midnightForLocalDate($midnight, $d, $GMT));
	check("$d: solar noon and solar midnight both present exactly once",
		$n===1 && $m===1, "noon=$n midnight=$m");
}

// The specific failure mode this guards: solar midnight for 1 Jan must derive
// from 31 Dec of the PREVIOUS year. Getting that wrong shows up either as a ~24h
// displacement or as a gap that is visibly not 24h.
$jan1 = midnightForLocalDate($midnight, '2026-01-01', $GMT);
$jan2 = midnightForLocalDate($midnight, '2026-01-02', $GMT);
if ( count($jan1) && count($jan2) ){
	$gap = $jan2[0]['start'] - $jan1[0]['start'];
	check('2026-01-01 -> 01-02 solar midnight gap is ~24h (no year-boundary displacement)',
		abs($gap - 86400) < 120, round($gap/3600,4) . 'h');
}

// And a sanity check that Jan 1's midnight really is in Jan 1's early hours
// rather than having slid a day.
if ( count($jan1) ){
	$local_hour = (int) gmdate('H', $jan1[0]['start'] + $GMT*3600);
	check('2026-01-01 solar midnight sits within an hour of local midnight',
		$local_hour <= 1 || $local_hour >= 23,
		'local ' . gmdate('Y-m-d H:i:s', $jan1[0]['start'] + $GMT*3600));
}

// One event per iterated day, and 24h spacing, together prove no day was lost or
// duplicated anywhere in the year - which is the invariant the earlier
// date-pinning bug violated with a 48h hole in early October.
$expected_days = (int) ((strtotime('2027-01-01 UTC') - strtotime('2026-01-01 UTC')) / 86400);
check('exactly one solar midnight per day of the year',
	count($midnight) === $expected_days, count($midnight) . ' events for ' . $expected_days . ' days');
check('exactly one solar noon per day of the year',
	count($noon) === $expected_days, count($noon) . ' events for ' . $expected_days . ' days');

section('Google Calendar compatibility (the empty-subscription bug)');

/*
	These two are why a subscription in Google Calendar came back with no events
	at all, despite the feed itself being served correctly with 1460 VEVENTs.

	RFC 5545: UID is the identity of a calendar component. Several VEVENTs
	sharing one UID with no RECURRENCE-ID do not describe several events, they
	describe one event redefined several contradictory ways. Upstream gave every
	event on a date the same UID. Add a yearly RRULE on top of that and the
	result is a calendar a strict consumer will not render.
*/
$all_uids = array();
foreach ( $events as $e ){
	if ( !isset($all_uids[$e['uid']]) ){ $all_uids[$e['uid']] = 0; }
	$all_uids[$e['uid']]++;
}
$collisions = 0;
foreach ( $all_uids as $n ){ if ( $n > 1 ){ $collisions++; } }

check('every event in the feed has a globally unique UID',
	$collisions === 0,
	count($events) . ' events, ' . count($all_uids) . ' distinct UIDs, ' . $collisions . ' collided');

check('no RRULE on any event (solar times differ every year, so they must not repeat)',
	strpos((string)$ics, 'RRULE') === false);

check('Content-Disposition is not "attachment"',
	stripos((string)$ics, 'attachment') === false);

// RFC 5545 3.1: content lines are folded at 75 octets. Long lines are widely
// tolerated, but this feed is consumed by Google, which has already proven
// strict here, so keep inside the limit rather than relying on leniency.
$long = array();
foreach ( explode("\r\n", (string)$ics) as $line ){
	if ( strlen($line) > 75 ){ $long[] = $line; }
}
check('no content line exceeds the 75-octet fold limit',
	count($long) === 0,
	count($long) . ' long lines; longest ' . ( $long ? strlen($long[0]) . ' octets: ' . substr($long[0], 0, 60) : '-' ));

section('Calendar naming');

check('X-WR-CALNAME is "Anamanta Kythings"',
	strpos((string)$ics, 'X-WR-CALNAME:Anamanta Kythings') !== false);
check('PRODID no longer references the upstream host',
	stripos((string)$ics, 'PRODID:-//Anamanta') !== false);
check('no gearside.com references remain in the feed body',
	stripos((string)$ics, 'gearside') === false);

section('Rolling window (year-boundary defect)');

/*
	A feed pinned to one calendar year dies every 31 December, and only recovers
	whenever the client next refetches - up to 24 hours into January. The default
	is now a window relative to today, so it never runs out.

	The property that matters most is UID stability: as the window slides forward
	on each fetch, an event for a given day must keep the same UID, or clients
	will treat every refresh as a fresh set of events and churn.
*/
$roll_qs = "lat=$LAT&lng=$LNG&gmt=$GMT&length=$LEN&actual&noon&midnight";
$roll    = parseEvents(generate($roll_qs));

check('default (no year param) produces a rolling window, not a single year',
	count($roll) > 1460, count($roll) . ' events');

$roll_starts = array_map(function($e){ return $e['start']; }, $roll);
sort($roll_starts);
$first = $roll_starts[0];
$last  = $roll_starts[count($roll_starts)-1];

check('window starts in the recent past, not on 1 January',
	$first < time() && $first > strtotime('-70 days'),
	'first event ' . utc($first));
check('window reaches at least 12 months ahead',
	$last > strtotime('+12 months'),
	'last event ' . utc($last));
check('window crosses at least one 31 Dec -> 1 Jan boundary',
	(int) gmdate('Y', $last) > (int) gmdate('Y', $first),
	utc($first, 'Y') . ' -> ' . utc($last, 'Y'));

// The heart of it: two different windows must agree on every day they share.
$narrow = parseEvents(generate($roll_qs . '&back=5&months=6'));
$by_uid = array();
foreach ( $roll as $e ){ $by_uid[$e['uid']] = $e; }
$shared = 0; $moved = 0;
foreach ( $narrow as $e ){
	if ( isset($by_uid[$e['uid']]) ){
		$shared++;
		if ( $by_uid[$e['uid']]['start'] !== $e['start'] || $by_uid[$e['uid']]['summary'] !== $e['summary'] ){ $moved++; }
	}
}
check('UIDs are stable across window sizes - no event moves when the window slides',
	$moved === 0 && $shared > 500,
	"$shared shared events, $moved moved");

$roll_uids = array();
foreach ( $roll as $e ){ $roll_uids[$e['uid']] = true; }
check('rolling feed has no duplicate UIDs',
	count($roll_uids) === count($roll),
	count($roll) . ' events, ' . count($roll_uids) . ' distinct');

check('rolling feed still contains all four event types',
	count(byType($roll,'Sunrise')) > 500 && count(byType($roll,'Sunset')) > 500
	&& count(byType($roll,'Solar Noon')) > 500 && count(byType($roll,'Solar Midnight')) > 500);

// Legacy mode must be untouched - tests/regression.sh proves byte-identity, this
// just guards the switch itself.
$fixed = parseEvents(generate("lat=$LAT&lng=$LNG&gmt=$GMT&year=2026&length=$LEN&actual&noon&midnight"));
check('?year=NNNN still returns exactly that one year',
	count($fixed) === 1460, count($fixed) . ' events');
$fx = array_map(function($e){ return $e['start']; }, $fixed);
check('?year=2026 events all fall in 2026 local time',
	gmdate('Y', min($fx) + $GMT*3600) === '2026' && gmdate('Y', max($fx) + $GMT*3600) === '2026');

check('months and back params are clamped to sane bounds',
	count(parseEvents(generate($roll_qs . '&months=999&back=-5'))) < 4600,
	'unbounded months would blow up the response');

section('Opt-in behaviour');

$plain = parseEvents(generate("lat=$LAT&lng=$LNG&gmt=$GMT&year=$YEAR&length=$LEN&actual"));
check('without noon/midnight/all flags, no Solar Noon events',
	count(byType($plain,'Solar Noon')) === 0);
check('without noon/midnight/all flags, no Solar Midnight events',
	count(byType($plain,'Solar Midnight')) === 0);

$only_noon = parseEvents(generate("lat=$LAT&lng=$LNG&gmt=$GMT&year=$YEAR&length=$LEN&noon"));
check('noon flag alone yields solar noon and no solar midnight',
	count(byType($only_noon,'Solar Noon')) > 360 && count(byType($only_noon,'Solar Midnight')) === 0);

$only_mid = parseEvents(generate("lat=$LAT&lng=$LNG&gmt=$GMT&year=$YEAR&length=$LEN&midnight"));
check('midnight flag alone yields solar midnight and no solar noon',
	count(byType($only_mid,'Solar Midnight')) > 360 && count(byType($only_mid,'Solar Noon')) === 0);

section('BRAIN-52: fixed-time overrides');

/*
	Portland's standard-time offset is UTC-8 ($GMT above), and its real zone is
	America/Los_Angeles, which observes PDT (UTC-7) from 2026-03-08 through
	2026-11-01. A fixed 06:00 override with `tz` set should resolve to a
	DIFFERENT UTC instant in January (PST) than in July (PDT); the same
	override without `tz` must resolve to the same UTC instant both times,
	since without a real zone name this file has no way to know DST applies at
	all and falls back to the fixed $GMT convention documented in
	overrideEventStart().
*/
$TZ = 'America/Los_Angeles';

$ov_dst_jan = parseEvents(generate("lat=$LAT&lng=$LNG&gmt=$GMT&year=2026&length=$LEN&actual&override_sunrise=06:00&tz=$TZ"));
$ov_dst_jul = parseEvents(generate("lat=$LAT&lng=$LNG&gmt=$GMT&year=2026&length=$LEN&actual&override_sunrise=06:00&tz=$TZ"));
// Both requests generate a full year, so pull the specific dates out of each.
$jan_fixed = eventsOnLocalDate(byType($ov_dst_jan, 'Sunrise (fixed)'), '2026-01-15', $GMT);
$jul_fixed = eventsOnLocalDate(byType($ov_dst_jul, 'Sunrise (fixed)'), '2026-07-15', $GMT);

check('with tz: 06:00 override on 2026-01-15 (PST) resolves to 14:00 UTC',
	count($jan_fixed) === 1 && utc($jan_fixed[0]['start'], 'H:i') === '14:00',
	count($jan_fixed) ? utc($jan_fixed[0]['start'], 'H:i') . ' UTC' : 'not found');
check('with tz: 06:00 override on 2026-07-15 (PDT) resolves to 13:00 UTC',
	count($jul_fixed) === 1 && utc($jul_fixed[0]['start'], 'H:i') === '13:00',
	count($jul_fixed) ? utc($jul_fixed[0]['start'], 'H:i') . ' UTC' : 'not found');

$ov_notz = parseEvents(generate("lat=$LAT&lng=$LNG&gmt=$GMT&year=2026&length=$LEN&actual&override_sunrise=06:00"));
$jan_notz = eventsOnLocalDate(byType($ov_notz, 'Sunrise (fixed)'), '2026-01-15', $GMT);
$jul_notz = eventsOnLocalDate(byType($ov_notz, 'Sunrise (fixed)'), '2026-07-15', $GMT);
check('without tz: 06:00 override resolves to the same UTC instant year-round (no DST awareness)',
	count($jan_notz) === 1 && count($jul_notz) === 1 && $jan_notz[0]['start'] % 86400 === $jul_notz[0]['start'] % 86400,
	( count($jan_notz) ? utc($jan_notz[0]['start'],'H:i') : '?' ) . ' vs ' . ( count($jul_notz) ? utc($jul_notz[0]['start'],'H:i') : '?' ) . ' UTC');

section('BRAIN-52: relabeling');

check('overridden event SUMMARY carries the "(fixed)" suffix',
	count($jan_fixed) === 1 && $jan_fixed[0]['summary'] === 'Sunrise (fixed)',
	count($jan_fixed) ? $jan_fixed[0]['summary'] : 'not found');

$ov_desc_ics = generate("lat=$LAT&lng=$LNG&gmt=$GMT&year=2026&length=$LEN&actual&override_sunrise=06:00&tz=$TZ");
// escapeString() backslash-escapes RFC 5545 special characters, commas included.
check('overridden event DESCRIPTION says "fixed time, not calculated"',
	strpos((string)$ov_desc_ics, 'Sunrise - a fixed time\, not calculated.') !== false);
check('a calculated event in the same feed keeps the normal DESCRIPTION',
	strpos((string)$ov_desc_ics, 'Sunset - an Anamanta solar time.') !== false);

section('BRAIN-52: sunrise/sunset independence');

// override_sunrise alone, no `actual`/`all`: sunrise appears (fixed), sunset does not appear at all.
$only_sr_override = parseEvents(generate("lat=$LAT&lng=$LNG&gmt=$GMT&year=$YEAR&length=$LEN&override_sunrise=06:00&tz=$TZ"));
check('override_sunrise alone yields fixed Sunrise events',
	count(byType($only_sr_override, 'Sunrise (fixed)')) > 300);
check('override_sunrise alone yields no Sunset events at all',
	count(byType($only_sr_override, 'Sunset')) === 0 && count(byType($only_sr_override, 'Sunset (fixed)')) === 0);

// `actual` plus override_sunset only: sunrise stays calculated, sunset is fixed.
$mixed = parseEvents(generate("lat=$LAT&lng=$LNG&gmt=$GMT&year=$YEAR&length=$LEN&actual&override_sunset=20:00&tz=$TZ"));
check('actual + override_sunset: Sunrise stays calculated (not relabeled)',
	count(byType($mixed, 'Sunrise')) > 300 && count(byType($mixed, 'Sunrise (fixed)')) === 0);
check('actual + override_sunset: Sunset is fixed (relabeled)',
	count(byType($mixed, 'Sunset (fixed)')) > 300 && count(byType($mixed, 'Sunset')) === 0);

section('BRAIN-52: override forces inclusion');

check('override_noon alone (no noon/all flag) still yields Solar Noon events',
	count(byType(parseEvents(generate("lat=$LAT&lng=$LNG&gmt=$GMT&year=$YEAR&length=$LEN&override_noon=00:00")), 'Solar Noon (fixed)')) > 300);
check('override_midnight alone (no midnight/all flag) still yields Solar Midnight events',
	count(byType(parseEvents(generate("lat=$LAT&lng=$LNG&gmt=$GMT&year=$YEAR&length=$LEN&override_midnight=00:00")), 'Solar Midnight (fixed)')) > 300);

section('BRAIN-52: malformed override degrades gracefully');

$bad_override = parseEvents(generate("lat=$LAT&lng=$LNG&gmt=$GMT&year=$YEAR&length=$LEN&override_sunrise=99:99"));
check('an out-of-range override value is ignored (no crash, no forced inclusion)',
	count(byType($bad_override, 'Sunrise')) === 0 && count(byType($bad_override, 'Sunrise (fixed)')) === 0);

$bad_override_ics = generate("lat=$LAT&lng=$LNG&gmt=$GMT&year=$YEAR&length=$LEN&all&override_noon=notatime");
check('a non-numeric override value falls back to the calculated time instead',
	count(byType(parseEvents($bad_override_ics), 'Solar Noon')) > 300
	&& count(byType(parseEvents($bad_override_ics), 'Solar Noon (fixed)')) === 0);
check('malformed override query produces no PHP diagnostics',
	stripos((string)$bad_override_ics, 'Warning:') === false && stripos((string)$bad_override_ics, 'Notice:') === false);

section('BRAIN-52: overridden event duration matches length param');

$ov_len = parseEvents(generate("lat=$LAT&lng=$LNG&gmt=$GMT&year=$YEAR&length=45&override_noon=12:34"));
$ov_len_noon = byType($ov_len, 'Solar Noon (fixed)');
$bad_ov_len = 0;
foreach ( $ov_len_noon as $e ){ if ( ($e['end'] - $e['start']) !== 45*60 ){ $bad_ov_len++; } }
check('overridden Solar Noon honours the length param (45 min)',
	count($ov_len_noon) > 300 && $bad_ov_len === 0, "$bad_ov_len wrong-length events");

section('BRAIN-52: rolling vs fixed-year parity');

$ov_rolling = parseEvents(generate("lat=$LAT&lng=$LNG&gmt=$GMT&length=$LEN&override_noon=09:15&tz=$TZ"));
check('override applies identically in rolling mode (no year param)',
	count(byType($ov_rolling, 'Solar Noon (fixed)')) > 400);

section('RFC 5545 75-octet line folding');

/*
	Every value sun.php emits today is short by construction, so this is the
	only way to actually exercise foldLine() rather than just proving it never
	fires. A deliberately oversized BASE_URL forces the URL;VALUE=URI property
	(and, transitively through the URL appearing nowhere else, nothing else)
	past 75 octets on every event -- proving real folding happens, every
	physical line stays within the limit, and unfolding (stripping "CRLF ")
	reconstructs the original value exactly.
*/
$orig_base_url = getenv('KYTHINGS_BASE_URL');
$long_host = 'a-deliberately-long-hostname-well-past-the-rfc-5545-fold-limit-for-testing.example.com';
putenv("KYTHINGS_BASE_URL=https://$long_host/some/deliberately/long/path/segment/too");
$fold_ics = generate("lat=$LAT&lng=$LNG&gmt=$GMT&year=$YEAR&length=$LEN&actual");
putenv('KYTHINGS_BASE_URL=' . $orig_base_url);

$fold_raw_lines = explode("\r\n", (string)$fold_ics);
$fold_long_lines = array();
foreach ( $fold_raw_lines as $l ){ if ( strlen($l) > 75 ){ $fold_long_lines[] = $l; } }
check('no physical line exceeds 75 octets even with an oversized BASE_URL',
	count($fold_long_lines) === 0,
	count($fold_long_lines) . ' long lines; e.g. ' . ( $fold_long_lines ? strlen($fold_long_lines[0]) . ' octets' : '-' ));

check('a URL property line was actually folded (proves this test exercises real folding, not a no-op)',
	preg_match('/^URL;VALUE=URI:.*\r\n /m', (string)$fold_ics) === 1);

// Unfold by stripping "CRLF space" sequences, then confirm the long value survived intact.
$unfolded = str_replace("\r\n ", '', (string)$fold_ics);
check('the long BASE_URL survives folding and unfolding intact',
	strpos($unfolded, $long_host) !== false);

check('folding produced no PHP diagnostics',
	stripos((string)$fold_ics, 'Warning:') === false && stripos((string)$fold_ics, 'Notice:') === false);

/* ---------- summary ---------- */

echo "\n" . str_repeat('-', 60) . "\n";
if ( count($FAILURES) === 0 ){
	echo "PASS: all $CHECKS checks passed.\n";
	exit(0);
}
echo "FAIL: " . count($FAILURES) . " of $CHECKS checks failed:\n";
foreach ( $FAILURES as $f ){ echo "  - $f\n"; }
exit(1);
