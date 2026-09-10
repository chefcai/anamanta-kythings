<?php
/*
	ICS Validator: http://severinghaus.org/projects/icv/
	Another Validator: http://icalvalid.cloudapp.net/

	My favorite time of day is 11-14 minutes after sunset (when calculated with zenith 90.83). The beautiful sunset is just finishing up with its deepest colors and streetlights and other man-made lights are on.
	This is just about (slightly before) halfway between sunset as calculated above and civil sunset (zenith of 96).

	//Google Calendar updates every 12 hours (noticed at 9:30am, 9:30pm, 1:00pm, 2:30am).
*/

// Base URL this feed is served at, used for the per-event URL property below.
// Required, no default: there is no domain-agnostic value that would be
// correct to fall back to, so a missing setting fails loudly instead of
// silently emitting a wrong URL into every event.
$BASE_URL = getenv('KYTHINGS_BASE_URL');
if ( $BASE_URL === false || $BASE_URL === '' ){
	http_response_code(500);
	header('Content-Type: text/plain; charset=utf-8');
	die('Configuration error: the KYTHINGS_BASE_URL environment variable is required and is not set.');
}
$BASE_URL = rtrim($BASE_URL, '/');

$debug = 0; //Enable forced debug mode here

if ( $debug == 0 && !array_key_exists('debug', $_GET) ){
	header('Content-type: text/calendar; charset=utf-8');
	// `inline`, not `attachment`. This is a subscription feed that calendar
	// clients poll, not a file a browser should download and save.
	header('Content-Disposition: inline; filename=anamanta-kythings.ics');
} else {
	error_reporting(E_ALL); // Report all errors
	ini_set('display_errors', 1); // Display errors on the screen
}

function dateToCal($timestamp) {
	return date('Ymd\THis\Z', $timestamp); // 'Ymd\THis\Z' for UTC time
}

function escapeString($string) {
	return preg_replace('/([\,;])/','\\\$1', $string);
}

/*
	Solar transit (true solar noon) for one calendar date, as a real UTC timestamp.

	$ymd is the *event* date actually emitted (i.e. already carried forward by
	'+1 year' the way the rest of this file does it), formatted 'Y-m-d'.
	$gmt is the requested fixed UTC offset, same meaning as everywhere else here.

	Uses date_sun_info()'s 'transit' value. This is deliberately NOT clock noon:
	true solar noon drifts from 12:00 by the equation of time plus the distance
	from the timezone's central meridian, which together run to well over half
	an hour in places.

	Day selection: we anchor on 12:00 *local* time for the requested offset and
	convert that to the real UTC instant it corresponds to, then ask
	date_sun_info() about that instant. Anchoring on local noon rather than on
	UTC midnight is what keeps us on the right calendar day at large |$gmt|,
	where the local day and the UTC day are not the same day. If PHP still
	returns a transit more than 12 hours away from the anchor (possible hard up
	against the date line), we step a day either way and retry, so the value
	returned always belongs to $ymd and never to a neighbouring day.

	Returns an integer UTC timestamp, or false if no transit could be determined.
*/
function solarTransit($ymd, $lat, $lng, $gmt) {
	$anchor = strtotime($ymd . ' 12:00:00 UTC');

	if ( $anchor === false ){
		return false;
	}

	$anchor = $anchor - ($gmt * 3600);

	foreach ( array(0, -86400, 86400) as $nudge ){
		$info = date_sun_info($anchor + $nudge, $lat, $lng);

		//At extreme latitudes sunrise/sunset come back as bool; transit normally
		//survives, but guard anyway rather than emitting a malformed VEVENT.
		if ( !is_array($info) || !isset($info['transit']) || !is_int($info['transit']) ){
			continue;
		}

		if ( abs($info['transit'] - $anchor) <= 43200 ){
			return $info['transit'];
		}
	}

	return false;
}

/*
	Convert a real UTC timestamp into the internal representation the rest of
	this file uses for event start times.

	Existing events are built as strtotime($date . '+1 year ' . 'HH:MM'), i.e. a
	wall-clock time at UTC+$gmt fed through strtotime(). We do the same, and for
	the same reason: it puts the new event types in exactly the same frame as the
	old ones whatever the server's default timezone is. Seconds are kept, where
	date_sunrise() only offers minutes.

	The one difference is that we format the whole date AND time from the
	timestamp rather than pinning a time-of-day onto a date decided in advance.
	That matters for solar midnight specifically. Sunrise and sunset always fall
	on the day they belong to, but solar midnight sits on the boundary and drifts
	across it through the year: for part of the year the midnight belonging to
	day D happens at 00:00:0x on D, and for another part at 23:59:5x on D-1.
	Forcing it onto D in that second case shunts it a full day forward and leaves
	a 48-hour hole in the feed. Taking the date from the timestamp keeps every
	event at its true instant and consecutive events a true 24 hours apart.
*/
function utcToEventStart($utc_timestamp, $gmt) {
	return strtotime(gmdate('Y-m-d H:i:s', $utc_timestamp + ($gmt * 3600)));
}

/*
	Resolve a fixed HH:MM override to this file's internal event-start
	representation for one calendar date, the same representation
	utcToEventStart() and every calculated event above produce.

	When a real IANA timezone is known, this uses PHP's own DateTimeZone to
	find the true UTC instant of that wall-clock time on that date, which
	correctly accounts for a DST transition on or near $event_date -- the
	whole reason this takes $event_date and $tz_name separately rather than
	just adding a fixed offset once. Without a valid timezone (a hand-built
	URL that sets an override but not `tz`), it falls back to the same
	fixed-$gmt convention as everything else in this file: no DST awareness,
	consistent with the project's documented approach when no real timezone
	name is available.
*/
function overrideEventStart($event_date, $hm, $gmt, $tz_name) {
	$time_string = sprintf('%02d:%02d:00', $hm['H'], $hm['M']);

	if ( $tz_name !== null ){
		try {
			$tz = new DateTimeZone($tz_name);
			$dt = new DateTime($event_date . ' ' . $time_string, $tz);
			return utcToEventStart($dt->getTimestamp(), $gmt);
		} catch ( Exception $ex ){
			// Invalid timezone name -- fall through to the approximation below.
		}
	}

	return strtotime($event_date . ' ' . $time_string);
}

$year = ( isset($_GET['year']) )? intval($_GET['year']) : date('Y');
$lat = ( isset($_GET['lat']) )? floatval($_GET['lat']) : 43.0469;
$lng = ( isset($_GET['lng']) )? floatval($_GET['lng']) : -76.1444;
$gmt = ( isset($_GET['gmt']) )? intval($_GET['gmt']) : -5;
$length = ( isset($_GET['length']) )? intval($_GET['length']) : 15;
$gmt_math = ($gmt*3600)*-1;

// Real IANA name, used ONLY to resolve override_* times below with correct DST
// handling. Everything else in this file is unaffected by it and continues to
// use only the fixed $gmt offset, exactly as before -- this is optional and
// additive, not a replacement for $gmt. The builder page always supplies it;
// a hand-built URL that sets an override without it still works, just without
// DST awareness (see overrideEventStart() below).
$tz_name = ( isset($_GET['tz']) && $_GET['tz'] !== '' ) ? $_GET['tz'] : null;

/*
	A fixed HH:MM a subscriber designates instead of the calculated time for
	one event type, independent per type. Ref BRAIN-52.

	Validated defensively since this endpoint takes raw query parameters
	directly: a malformed or absent value is simply "no override" rather than
	an error, matching how every other parameter here degrades gracefully.
*/
function parseClockTime($raw) {
	if ( !is_string($raw) || $raw === '' ){ return false; }
	if ( !preg_match('/^([0-9]{1,2}):([0-9]{2})$/', $raw, $m) ){ return false; }
	$h = (int)$m[1]; $min = (int)$m[2];
	if ( $h < 0 || $h > 23 || $min < 0 || $min > 59 ){ return false; }
	return array('H' => $h, 'M' => $min);
}

$override_sunrise  = isset($_GET['override_sunrise'])  ? parseClockTime($_GET['override_sunrise'])  : false;
$override_sunset   = isset($_GET['override_sunset'])   ? parseClockTime($_GET['override_sunset'])   : false;
$override_noon     = isset($_GET['override_noon'])     ? parseClockTime($_GET['override_noon'])     : false;
$override_midnight = isset($_GET['override_midnight']) ? parseClockTime($_GET['override_midnight']) : false;

/*
	Rolling window versus a fixed year.

	A feed pinned to one calendar year is broken every New Year: it stops dead on
	31 December, and because calendar clients only refetch every 12-24 hours it
	does not even self-heal until some time on 1 January. Anyone looking ahead on
	New Year's Eve sees nothing.

	Since this file is generated per request, the fix is to generate a window
	relative to today and let it slide forward on every fetch. It never runs out,
	and the problem stops existing rather than being documented.

	Passing ?year=NNNN still selects that exact calendar year, with byte-identical
	output to before, so anything already relying on it is unaffected.

	  (default)     today - 30 days .. today + 18 months
	  ?months=N     window length forward, 1-36
	  ?back=N       days of history to keep, 0-365
	  ?year=NNNN    legacy fixed-year mode

	The '+1 year' shift below is upstream's: its loop runs over the *previous*
	year and every event is built as strtotime($date . '+1 year ' . $time). That
	is preserved exactly for fixed-year mode. In rolling mode the shift is empty
	and $date is simply the event's own date, which also sidesteps the leap-day
	special-casing at the bottom of the loop.
*/
$rolling = !isset($_GET['year']);

if ( $rolling ){
	$months = ( isset($_GET['months']) ) ? intval($_GET['months']) : 18;
	$months = max(1, min(36, $months));
	$back   = ( isset($_GET['back']) ) ? intval($_GET['back']) : 30;
	$back   = max(0, min(365, $back));

	$year_shift = '';
	$loop_start = date('Y-m-d', strtotime("-{$back} days"));
	$loop_end   = date('Y-m-d', strtotime("+{$months} months"));
} else {
	$year_shift = '+1 year';
	$loop_start = $year-1 . '-01-01';
	$loop_end   = $year-1 . '-12-31';
}

$syracuse = ( $lat == 43.0469 && $lng == -76.1444 ) ? 1: 0;
if ( $syracuse ){
	date_default_timezone_set('America/New_York'); //This is only used for the "Last Updated" date
}

?>
BEGIN:VCALENDAR<?php echo "\r\n"; ?>
VERSION:2.0<?php echo "\r\n"; ?>
PRODID:-//Anamanta//Kythings Solar Calendar//EN<?php echo "\r\n"; ?>
CALSCALE:GREGORIAN<?php echo "\r\n"; ?>
METHOD:PUBLISH<?php echo "\r\n"; ?>
X-WR-CALNAME:Anamanta Kythings<?php echo "\r\n"; ?>
X-WR-CALDESC:Daily solar times for your location.<?php echo "\r\n"; //Kept short: RFC 5545 folds content lines at 75 octets ?>
X-PUBLISHED-TTL:PT12H<?php echo "\r\n"; ?>
REFRESH-INTERVAL;VALUE=DURATION:PT12H<?php echo "\r\n"; ?>
<?php
$date = $loop_start; //Fixed-year mode starts a year back so events carry over the year boundary; rolling mode starts at the real first day of the window.
while ( strtotime($date) <= strtotime($loop_end) || ( !$rolling && strtotime($date) == strtotime($year . '-02-29') ) ): //The or statement is just for leap days, and only applies in fixed-year mode
	$events = array(
		'sunrise' => array(
			'start' => 0,
			'end' => 0,
			'length' => 0,
			'name' => 'Sunrise',
			'overridden' => false
		),
		'sunset' => array(
			'start' => 0,
			'end' => 0,
			'length' => 0,
			'name' => 'Sunset',
			'overridden' => false
		),
		'civil morning' => array(
			'start' => 0,
			'end' => 0,
			'length' => 0,
			'name' => 'Civil Twilight'
		),
		'civil evening' => array(
			'start' => 0,
			'end' => 0,
			'length' => 0,
			'name' => 'Civil Twilight'
		),
		'nautical morning' => array(
			'start' => 0,
			'end' => 0,
			'length' => 0,
			'name' => 'Nautical Twilight'
		),
		'nautical evening' => array(
			'start' => 0,
			'end' => 0,
			'length' => 0,
			'name' => 'Nautical Twilight'
		),
		'astronomical morning' => array(
			'start' => 0,
			'end' => 0,
			'length' => 0,
			'name' => 'Astronomical Twilight'
		),
		'astronomical evening' => array(
			'start' => 0,
			'end' => 0,
			'length' => 0,
			'name' => 'Astronomical Twilight'
		),
		//Appended deliberately at the end of this array: events are emitted in
		//array order, so adding here leaves the ordering of every pre-existing
		//event untouched. Both default to start => 0 and are therefore skipped
		//by the emitter unless their flag is passed.
		'solar noon' => array(
			'start' => 0,
			'end' => 0,
			'length' => 0,
			'name' => 'Solar Noon',
			'overridden' => false
		),
		'solar midnight' => array(
			'start' => 0,
			'end' => 0,
			'length' => 0,
			'name' => 'Solar Midnight',
			'overridden' => false
		)
	);

	/*
		Sunrise and sunset are independent event types (Ref BRAIN-52): each has
		its own trigger condition and its own override, so one can be a fixed
		time while the other stays calculated, or either can appear without the
		other via its own override alone. `actual` still requests the pair the
		way it always has -- these two blocks fire together whenever it or
		`all` is set, exactly as the single combined block used to.

		$event_date (defined below, once) is what override resolution needs;
		it is computed once and reused by noon/midnight's blocks too.
	*/
	$event_date = date('Y-m-d', strtotime($date . ' ' . $year_shift));

	if ( isset($_GET['actual']) || isset($_GET['all']) || $override_sunrise !== false ){
		if ( $override_sunrise !== false ){
			$events['sunrise']['start'] = overrideEventStart($event_date, $override_sunrise, $gmt, $tz_name);
			$events['sunrise']['overridden'] = true;
		} else {
			$events['sunrise']['start'] = strtotime($date . $year_shift . ' ' . date_sunrise(strtotime($date), SUNFUNCS_RET_STRING, $lat, $lng, 90.83, $gmt));
		}
		$events['sunrise']['length'] = $length*60; //Minutes in seconds (Default: 15 minutes)
		$events['sunrise']['end'] = $events['sunrise']['start']+$events['sunrise']['length'];
	}

	if ( isset($_GET['actual']) || isset($_GET['all']) || $override_sunset !== false ){
		if ( $override_sunset !== false ){
			$events['sunset']['start'] = overrideEventStart($event_date, $override_sunset, $gmt, $tz_name);
			$events['sunset']['overridden'] = true;
		} else {
			$events['sunset']['start'] = strtotime($date . $year_shift . ' ' . date_sunset(strtotime($date), SUNFUNCS_RET_STRING, $lat, $lng, 90.83, $gmt));
		}
		$events['sunset']['length'] = $length*60; //Minutes in seconds (Default: 15 minutes)
		$events['sunset']['end'] = $events['sunset']['start']+$events['sunset']['length'];
	}

	if ( isset($_GET['civil']) || isset($_GET['all']) ){
		$events['civil morning']['start'] = strtotime($date . $year_shift . ' ' . date_sunrise(strtotime($date), SUNFUNCS_RET_STRING, $lat, $lng, 96, $gmt));
		$events['civil evening']['start'] = strtotime($date . $year_shift . ' ' . date_sunset(strtotime($date), SUNFUNCS_RET_STRING, $lat, $lng, 96, $gmt));
		$events['civil morning']['length'] = $events['sunrise']['start']-$events['civil morning']['start'];
		$events['civil evening']['length'] = $events['civil evening']['start']-$events['sunset']['start'];
		$events['civil morning']['end'] = $events['civil morning']['start']+$events['civil morning']['length'];
		$events['civil evening']['end'] = $events['civil evening']['start']+$events['civil evening']['length'];
	}

	if ( isset($_GET['nautical']) || isset($_GET['all']) ){
		$events['nautical morning']['start'] = strtotime($date . $year_shift . ' ' . date_sunrise(strtotime($date), SUNFUNCS_RET_STRING, $lat, $lng, 102, $gmt));
		$events['nautical evening']['start'] = strtotime($date . $year_shift . ' ' . date_sunset(strtotime($date), SUNFUNCS_RET_STRING, $lat, $lng, 102, $gmt));
		$events['nautical morning']['length'] = $events['civil morning']['start']-$events['nautical morning']['start'];
		$events['nautical evening']['length'] = $events['nautical evening']['start']-$events['civil evening']['start'];
		$events['nautical morning']['end'] = $events['nautical morning']['start']+$events['nautical morning']['length'];
		$events['nautical evening']['end'] = $events['nautical evening']['start']+$events['nautical evening']['length'];
	}

	if ( isset($_GET['astronomical']) || isset($_GET['all']) ){
		$events['astronomical morning']['start'] = strtotime($date . $year_shift . ' ' . date_sunrise(strtotime($date), SUNFUNCS_RET_STRING, $lat, $lng, 108, $gmt));
		$events['astronomical evening']['start'] = strtotime($date . $year_shift . ' ' . date_sunset(strtotime($date), SUNFUNCS_RET_STRING, $lat, $lng, 108, $gmt));
		$events['astronomical morning']['length'] = $events['nautical morning']['start']-$events['astronomical morning']['start'];
		$events['astronomical evening']['length'] = $events['astronomical evening']['start']-$events['nautical evening']['start'];
		$events['astronomical morning']['end'] = $events['astronomical morning']['start']+$events['astronomical morning']['length'];
		$events['astronomical evening']['end'] = $events['astronomical evening']['start']+$events['astronomical evening']['length'];
	}

	//$event_date (the calendar date this iteration actually emits) was already
	//computed above, before the sunrise/sunset blocks, since their overrides
	//need it too. Everything builds its times with strtotime($date . '+1 year
	//...'), so the solar calculations below must resolve their day the same
	//way or they would sit on the wrong date -- including through the
	//leap-day handling at the bottom of this loop.

	if ( isset($_GET['noon']) || isset($_GET['all']) || $override_noon !== false ){
		if ( $override_noon !== false ){
			$events['solar noon']['start'] = overrideEventStart($event_date, $override_noon, $gmt, $tz_name);
			$events['solar noon']['overridden'] = true;
			$events['solar noon']['length'] = $length*60;
			$events['solar noon']['end'] = $events['solar noon']['start']+$events['solar noon']['length'];
		} else {
			$noon_transit = solarTransit($event_date, $lat, $lng, $gmt);

			if ( $noon_transit !== false ){
				$events['solar noon']['start'] = utcToEventStart($noon_transit, $gmt);
				$events['solar noon']['length'] = $length*60; //Minutes in seconds, same as sunrise/sunset (Default: 15 minutes)
				$events['solar noon']['end'] = $events['solar noon']['start']+$events['solar noon']['length'];
			}
		}
	}

	if ( isset($_GET['midnight']) || isset($_GET['all']) || $override_midnight !== false ){
		if ( $override_midnight !== false ){
			$events['solar midnight']['start'] = overrideEventStart($event_date, $override_midnight, $gmt, $tz_name);
			$events['solar midnight']['overridden'] = true;
			$events['solar midnight']['length'] = $length*60;
			$events['solar midnight']['end'] = $events['solar midnight']['start']+$events['solar midnight']['length'];
		} else {
			/*
				Solar midnight is the anti-transit: the moment the sun is furthest
				below the horizon, halfway between two successive solar noons.

				The one belonging to date D falls in D's early hours, so it is bounded
				by the transit of D-1 on one side and the transit of D on the other,
				and we take the midpoint of those two. Each transit is resolved from
				its own real calendar date, which is what stops this drifting across a
				year boundary -- 1 January's midnight correctly reaches back to 31
				December of the previous year rather than reusing January's transit or
				falling a day out.

				Note this is genuinely two-sided rather than 'transit minus 12 hours'.
				The two differ by about half the day-over-day change in the equation of
				time -- only a few seconds -- but the midpoint is the correct
				definition and costs one extra lookup.
			*/
			$midnight_prev_date = date('Y-m-d', strtotime($event_date . ' -1 day'));
			$midnight_transit_prev = solarTransit($midnight_prev_date, $lat, $lng, $gmt);
			$midnight_transit_curr = solarTransit($event_date, $lat, $lng, $gmt);

			if ( $midnight_transit_prev !== false && $midnight_transit_curr !== false ){
				$midnight_utc = (int) floor(($midnight_transit_prev + $midnight_transit_curr)/2);

				$events['solar midnight']['start'] = utcToEventStart($midnight_utc, $gmt);
				$events['solar midnight']['length'] = $length*60; //Minutes in seconds, same as sunrise/sunset (Default: 15 minutes)
				$events['solar midnight']['end'] = $events['solar midnight']['start']+$events['solar midnight']['length'];
			}
		}
	}

	$dst = ( date('I', strtotime($date . ' ' . $year_shift . ' +12 hours')) ) ? 1 : 0;

	$last_sync = ( $date == date('Y-m-d', strtotime('Today -1 Year')) && 1==2 ) ? ' [Last Sync]' : '';

	if ( $debug == 1 || array_key_exists('debug', $_GET) ){
		echo "\r\n\r\n------------------\r\n";
		echo ( $date == date('Y-m-d', strtotime('Today -1 Year')) ) ? "(Today!) " : "";
		echo "Debug Info\r\n";
		echo "Last Modified: " . date('l, F j, Y', filemtime(__FILE__)) . "\r\n";
		echo "Date (-1 Year): " . $date . "\r\n";
		echo "Timezone: Requested: " . $gmt . ", Server: " . date_default_timezone_get() . "\r\n";
		echo ( $dst ) ? "DST?: Yes\r\n" : "DST?: No\r\n";

		foreach ( $events as $event ){
			if ( $event['start'] === 0 ){
				continue; //Skip any events that do not have data
			}

			echo $event['name'] . ": " . date('g:ia', strtotime(date('F j Y g:ia', $event['start']) . ' +' . $dst . ' hours')) . ' to ' . date('g:ia', strtotime(date('F j Y g:ia', $event['end']) . ' +' . $dst . ' hours')) . "\r\n\r\n";
		}

		echo "\r\n";
	}
?>
<?php
/*
	Two upstream behaviours were removed here, both of which broke subscription
	in Google Calendar. They are deliberate changes, not accidents:

	1. UID was md5($date . "@gearside.com") -- identical for every event on a
	   given date. RFC 5545 treats UID as the identity of a calendar component,
	   so several VEVENTs sharing one UID without a RECURRENCE-ID are not
	   separate events, they are contradictory redefinitions of one event.
	   Subscribing produced an empty calendar. UID is now unique per event per
	   day, keyed on the event type.

	2. RRULE:FREQ=YEARLY;COUNT=3 was emitted on every event, repeating each
	   day's times in the two following years. That is wrong for a solar
	   calendar -- sunrise on 3 March 2027 is not at 2026's time -- and layering
	   a recurrence rule on top of already-duplicated UIDs is what made the feed
	   unparseable. The feed now states each day once, for the requested year.
*/
?>
<?php foreach( $events as $event_key => $event ): ?>
<?php
	if ( $event['start'] === 0 ){
		continue; //Skip any events that do not have data
	}
?>
BEGIN:VEVENT<?php echo "\r\n"; ?>
CREATED:<?php echo dateToCal(strtotime($date)) . "\r\n"; ?>
DTSTART:<?php echo dateToCal($event['start']+$gmt_math) . "\r\n"; ?>
DTEND:<?php echo dateToCal($event['end']+$gmt_math) . "\r\n"; ?>
DTSTAMP:<?php echo dateToCal(time()) . "\r\n"; ?>
LAST-MODIFIED:<?php echo dateToCal(filemtime(__FILE__)) . "\r\n"; ?>
UID:<?php echo md5($event_date . '-' . $event_key . '@anamanta-kythings.invalid') . "\r\n"; /* Keyed on the event's OWN date, not the loop variable. Two reasons. Upstream used one UID for every event on a date, which RFC 5545 reads as "these are all the same event" and made Google render the feed empty. And keying on $event_date makes the UID identical whether the feed was generated in rolling or fixed-year mode, and stable as the rolling window slides -- otherwise every refetch would look like a fresh set of events and clients would churn. The '.invalid' suffix is the RFC 2606 reserved TLD for a namespacing string that is not meant to resolve -- this is a uniqueness key, not a real address. */ ?>
DESCRIPTION:<?php echo escapeString($event['name'] . ( !empty($event['overridden']) ? ' - a fixed time, not calculated.' : ' - an Anamanta solar time.' )) . "\r\n"; /* Deliberately short, same reasoning as SUMMARY below: the longest name among the four overridable event types is "Solar Midnight", well inside the 75-octet fold limit either way. !empty() rather than a bare check because civil/nautical/astronomical entries never gain an 'overridden' key at all -- they don't support overrides -- and a bare array access on a key that legitimately isn't there would warn. */ ?>
URL;VALUE=URI:<?php echo escapeString($BASE_URL . '/') . "\r\n"; ?>
SUMMARY:<?php echo escapeString($event['name'] . ( !empty($event['overridden']) ? ' (fixed)' : '' ) . $last_sync) . "\r\n"; //Shows up in the title of the event -- "(fixed)" marks a subscriber-designated time (Ref BRAIN-52) so it isn't mistaken for the calculated solar time ?>
END:VEVENT<?php echo "\r\n"; ?>
<?php endforeach; ?>
<?php
	if ( $rolling ){
		//$date is the event's own date here, so a leap day is just another day.
		$date = date("Y-m-d", strtotime("+1 day", strtotime($date)));
	} elseif ( $date == $year-1 . '-02-28' && date('L', strtotime($year . '-02-29')) ){ //If is Feb 28th and if tomorrow is a leap day
		$date = date("Y-m-d", strtotime($year . '-02-29')); //Set the year to the current year (rather than the previous year)
	} elseif ( $date == $year . '-02-29' ){ //If this *is* leap day
		$date = date("Y-m-d", strtotime($year-1 . '-03-01')); //Set the year back to the previous year on March 1
	} else {
		$date = date("Y-m-d", strtotime("+1 day", strtotime($date))); //Increment the date as normal
	}
endwhile; ?>
END:VCALENDAR<?php echo "\r\n"; ?>
<?php die; ?>

X-WR-CALDESC:Visualize daylight<?php echo "\r\n"; ?>