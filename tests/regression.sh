#!/bin/sh
# Regression check for BRAIN-44.
#
# Generates the same set of requests from the unmodified upstream sun.php and
# from the modified sun.php, and diffs them. Output must be identical apart from
# the two genuinely non-deterministic fields:
#   DTSTAMP        - time() at generation
#   LAST-MODIFIED  - filemtime(__FILE__), which differs simply because the two
#                    files are different files
#
# Requests deliberately exercise every pre-existing flag on its own, `all`,
# both length values, several GMT offsets, and a leap year vs a non-leap year.
#
# Usage (inside the php container):  sh /app/tests/regression.sh

set -e
cd /app

NEW=/app/sun.php
OLD=/app/sun_upstream.php
OUT=/tmp/regression
rm -rf "$OUT"; mkdir -p "$OUT"

normalise() {
	# Deprecation notices name the file they came from, so upstream and modified
	# can never match textually. They are dropped here only so the diff compares
	# calendar content. They are NOT harmless - see tests/deprecations.sh, which
	# checks them deliberately.
	sed -e 's/^DTSTAMP:.*/DTSTAMP:<normalised>/' \
	    -e 's/^LAST-MODIFIED:.*/LAST-MODIFIED:<normalised>/' \
	    -e '/^Deprecated: Function date_sun\(rise\|set\)() is deprecated in /d'
}

# `all` legitimately gains Solar Noon and Solar Midnight events - that is the
# requested behaviour change, tracked on BRAIN-44. To prove nothing ELSE moved,
# strip whole VEVENT blocks for the two new SUMMARY values before diffing. If a
# single pre-existing event shifted by a second, the diff still catches it.
strip_new_events() {
	awk '
		/^BEGIN:VEVENT/ { buf = $0 ORS; inev = 1; isnew = 0; next }
		inev {
			buf = buf $0 ORS
			if ($0 ~ /^SUMMARY:Solar (Noon|Midnight)/) { isnew = 1 }
			if ($0 ~ /^END:VEVENT/) {
				if (!isnew) printf "%s", buf
				inev = 0; buf = ""
			}
			next
		}
		{ print }
	'
}

CASES="
actual|lat=45.5152&lng=-122.6784&gmt=-8&year=2026&actual
civil|lat=45.5152&lng=-122.6784&gmt=-8&year=2026&civil
nautical|lat=45.5152&lng=-122.6784&gmt=-8&year=2026&nautical
astronomical|lat=45.5152&lng=-122.6784&gmt=-8&year=2026&astronomical
all|lat=45.5152&lng=-122.6784&gmt=-8&year=2026&all
all-len5|lat=45.5152&lng=-122.6784&gmt=-8&year=2026&all&length=5
all-len45|lat=45.5152&lng=-122.6784&gmt=-8&year=2026&all&length=45
defaults|
gmt0|lat=51.4779&lng=-0.0015&gmt=0&year=2026&all
gmt-plus12|lat=-41.2865&lng=174.7762&gmt=12&year=2026&all
gmt-plus5|lat=28.6139&lng=77.209&gmt=5&year=2026&all
leapyear|lat=45.5152&lng=-122.6784&gmt=-8&year=2024&all
nonleap|lat=45.5152&lng=-122.6784&gmt=-8&year=2025&all
southern|lat=-33.8688&lng=151.2093&gmt=10&year=2026&all
highlat|lat=69.6492&lng=18.9553&gmt=1&year=2026&all
noflags|lat=45.5152&lng=-122.6784&gmt=-8&year=2026
badlat|lat=notanumber&lng=&gmt=-8&year=2026&all
"

FAIL=0
COUNT=0

echo "$CASES" | while IFS='|' read -r NAME QS; do
	[ -z "$NAME" ] && continue
	COUNT=$((COUNT+1))

	php /app/tests/generate.php "$QS" "$OLD" 2>"$OUT/$NAME.old.err" | normalise > "$OUT/$NAME.old" || true
	php /app/tests/generate.php "$QS" "$NEW" 2>"$OUT/$NAME.new.err" | normalise > "$OUT/$NAME.new.raw" || true
	strip_new_events < "$OUT/$NAME.new.raw" > "$OUT/$NAME.new"

	if diff -q "$OUT/$NAME.old" "$OUT/$NAME.new" >/dev/null 2>&1; then
		OLDERR=$(wc -c < "$OUT/$NAME.old.err")
		NEWERR=$(wc -c < "$OUT/$NAME.new.err")
		if [ "$OLDERR" -eq 0 ] && [ "$NEWERR" -ne 0 ]; then
			echo "FAIL  $NAME  - identical output but NEW emitted diagnostics upstream did not:"
			head -5 "$OUT/$NAME.new.err" | sed 's/^/        /'
			echo "fail" >> "$OUT/.failed"
		else
			echo "ok    $NAME  (pre-existing $(grep -c '^BEGIN:VEVENT' "$OUT/$NAME.new"), new-type $(( $(grep -c '^BEGIN:VEVENT' "$OUT/$NAME.new.raw") - $(grep -c '^BEGIN:VEVENT' "$OUT/$NAME.new") )))"
		fi
	else
		echo "FAIL  $NAME  - output differs from upstream:"
		diff "$OUT/$NAME.old" "$OUT/$NAME.new" | head -20 | sed 's/^/        /'
		echo "fail" >> "$OUT/.failed"
	fi
done

if [ -f "$OUT/.failed" ]; then
	echo
	echo "REGRESSION FAILURES: $(wc -l < "$OUT/.failed")"
	exit 1
fi

echo
echo "All regression cases byte-identical to upstream (DTSTAMP/LAST-MODIFIED normalised)."
