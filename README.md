# Anamanta Kythings — solar calendar feed

A self-hosted calendar you can subscribe to that marks the four Anamanta solar times each day, for a location you designate:

**Sunrise · Solar noon · Sunset · Solar midnight**

Solar noon here is the real thing — the moment the sun actually crosses the meridian, from PHP's
`date_sun_info()` transit. It is not 12:00 on the clock, and depending on your longitude and the time of year the
two can differ by half an hour. Solar midnight is the anti-transit, the midpoint between one day's transit and the
next, so it stays correct across a year boundary rather than drifting.

---

## Using it

Go to the builder page, type a town, and it builds the subscription address for you. You should never need to
hand-edit a query string.

The page defaults to a five-minute event length, which is what the practice calls for, and to all four times
enabled. A town is enough — solar times vary by seconds across a town, so a street address gives an identical
calendar.

### Subscribing

> **Google Calendar: use a desktop browser.** Google does not allow subscribing to an outside calendar from the
> Google Calendar app on Android, iPhone or iPad. That is Google's limitation, not this project's. Subscribe once
> on a computer and it syncs to all your devices afterwards.

The builder page gives you a one-click Google link, a `webcal://` link for Apple Calendar, and the raw address for
anything else.

Two things worth knowing once subscribed:

- **Updates are not instant.** Google refreshes subscribed calendars roughly every 12–24 hours.
- **Set your own notification if you want an alert.** Google generally ignores reminders built into a subscribed
  calendar. Set a default notification on the subscribed calendar itself, once, in your calendar app's settings.

---

## The feed directly

If you would rather build the URL yourself, `sun.php` takes these query parameters:

| Parameter | Meaning | Default |
| --- | --- | --- |
| `lat` | Latitude, decimal degrees | `43.0469` |
| `lng` | Longitude, decimal degrees | `-76.1444` |
| `gmt` | UTC offset, **whole hours only** | `-5` |
| `length` | Event duration in minutes | `15` |
| `months` | How far ahead the rolling window reaches, 1–36 | `18` |
| `back` | Days of history kept in the window, 0–365 | `30` |
| `year` | Generate one fixed calendar year instead of a rolling window | — |
| `tz` | IANA timezone name (e.g. `America/New_York`), used only to resolve `override_*` times with correct daylight-saving awareness | — |
| `override_sunrise` | Fixed clock time (`HH:MM`, 24-hour) to use instead of calculated sunrise, every day | — |
| `override_sunset` | Fixed clock time instead of calculated sunset | — |
| `override_noon` | Fixed clock time instead of calculated solar noon | — |
| `override_midnight` | Fixed clock time instead of calculated solar midnight | — |

### The window rolls; it does not stop at New Year

By default the feed covers roughly **a month behind to 18 months ahead of today**, recalculated on every request, so
the window slides forward each time a calendar app refreshes. On 31 December you can already see January.

That matters more than it sounds. A feed pinned to one calendar year dies at midnight on 31 December and does not
recover until the client next refetches — which, for Google, can be up to 24 hours into the new year, exactly when
somebody wants to look ahead.

Event UIDs are derived from the event's own date, so a given day keeps the same UID as the window slides. Calendar
clients update in place rather than churning the whole feed on every refresh.

Passing `?year=NNNN` selects that single calendar year instead, with byte-identical output to upstream. Note that
in fixed-year mode upstream computes each sunrise and sunset from the *previous* year's date and relabels it, so a
few dozen events differ by up to a minute from the rolling window, which uses the correct date. Rolling mode is the
more accurate of the two; fixed-year mode is kept unchanged for compatibility.

Event types are flags — their presence is what counts, so `?noon` and `?noon=1` behave identically:

| Flag | Events |
| --- | --- |
| `actual` | Sunrise **and** sunset |
| `noon` | Solar noon |
| `midnight` | Solar midnight |
| `civil` | Civil twilight, morning and evening |
| `nautical` | Nautical twilight |
| `astronomical` | Astronomical twilight |
| `all` | All six of the above |

Add `?debug` to read the output in a browser instead of downloading it. Do not leave `?debug` in a URL you
subscribe to — with it present the response is not declared as a calendar file.

Example, the four Anamanta times for Worthington, Massachusetts:

```
https://example.com/sun.php?lat=42.396&lng=-72.936&gmt=-5&length=5&actual&noon&midnight
```

### Fixed-time overrides

Any of the four Anamanta times can be pinned to the same clock time every day instead of the calculated solar
time — useful if your practice marks, say, sunrise at a fixed 6:00 AM rather than whenever the sun actually comes
up. Set `override_sunrise`, `override_sunset`, `override_noon` and/or `override_midnight` to a 24-hour `HH:MM`
value (the builder page's clock field handles the AM/PM ↔ 24-hour conversion for you). Each of the four is
independent — you can fix one and leave the other three calculated.

A few things follow from that:

- **Setting an override includes that event even if its flag is not set.** `?override_noon=12:00` on its own adds
  Solar Noon to the feed exactly as if `?noon` had been passed too; you don't need both.
- **An overridden event is relabeled** so it isn't mistaken for a calculated time: its calendar title gains a
  `(fixed)` suffix and its description says so.
- **Overrides need `tz` to be daylight-saving aware.** Without it, a fixed time is resolved against the plain `gmt`
  offset year-round, same as everything else in this file — correct in one season and off by an hour in the other.
  Pass `tz` as a real IANA name (e.g. `America/New_York`) and the override is resolved against that zone's actual
  clock, DST transitions included. The builder page always sends the `tz` it already collects for you.
- **An override's event length still comes from `length`**, the same as a calculated event of that type.
- **A malformed or out-of-range override value is silently ignored**, falling back to the calculated time (or to no
  event at all, if nothing else requested that type) — this endpoint never returns an error, since anything but a
  well-formed calendar file would break every subscribed client.
- Sunrise and sunset overrides are set independently of the shared `actual` flag, so `?override_sunrise=06:00` and
  `?override_sunset=20:00` can be set one without the other, or both, without needing `actual` at all.

```
https://example.com/sun.php?lat=42.396&lng=-72.936&gmt=-5&length=5&actual&noon&midnight&override_sunrise=06:00&tz=America/New_York
```

### Known limits

- **`sun.php`'s general solar calculations have no timezone awareness** — only `gmt`, in whole hours. (Upstream's
  `daylight.php` accepts an IANA name; `sun.php` never has for calculated events.) This costs nothing in practice:
  every `DTSTART` is an absolute UTC instant, so your calendar app shows correct local times all year, daylight
  saving included, for every *calculated* event. The offset only decides which local day an event is filed under.
  `tz` is the one exception — it exists solely to resolve `override_*` times correctly across a DST transition; see
  Fixed-time overrides above. The builder page takes an IANA name and sends both `gmt` and `tz` for you.
- **Calculated sunrise and calculated sunset still share the `actual` flag** — hide the one you do not want in your
  calendar app, or fix one of them to a specific time with `override_sunrise`/`override_sunset` instead.
- **Once or twice a year, one calendar day carries two solar midnights** and the next day's arrives at 23:59 the
  evening before. Successive anti-transits are not exactly 24 hours apart, so this is unavoidable; the alternative
  would be publishing a time that is deliberately wrong.

---

## Running your own

The image is public and self-contained — the code is baked in, so pulling it is the whole deployment.

```yaml
  kythings:
    image: ghcr.io/chefcai/anamanta-kythings:latest
    container_name: kythings
    restart: unless-stopped
    environment:
      - TZ=UTC
      - KYTHINGS_BASE_URL=https://your-domain.example
    ports:
      - "127.0.0.1:8087:8080"
```

```sh
docker compose pull kythings && docker compose up -d kythings
```

Three settings are not optional:

- **`KYTHINGS_BASE_URL` must be set to the URL this instance is served at** (no trailing slash needed either way).
  There is no default — both `index.php` and `sun.php` return a `500` with a plain-text explanation rather than
  silently pointing at the wrong host if it is unset.
- **`display_errors` must be Off.** `sun.php` calls `date_sunrise()`/`date_sunset()`, deprecated since PHP 8.1. On
  PHP 8.5 they emit two notices per call site — thousands for a full year — and with display on those land in the
  response body and corrupt the calendar. The image sets this; if you build your own, do the same.
- **The timezone must be UTC.** `dateToCal()` formats in the server's default timezone but labels the result `Z`.
  A non-UTC container emits wrong timestamps that still look perfectly well-formed.

The latter two are asserted in CI rather than trusted.

### Security headers

The image sends `X-Frame-Options`, `X-Content-Type-Options`, `Strict-Transport-Security`, a `Content-Security-Policy`,
`Cross-Origin-Opener-Policy`, `Cross-Origin-Resource-Policy` and a locked-down `Permissions-Policy` on every response,
set in `docker/default.conf` — see that file for the reasoning behind each one. `script-src` is a strict `'self'`: the
builder page's own JavaScript lives entirely in same-origin `app.js`, with no inline `<script>` anywhere. If you fork
this further and add inline script or a new external resource, the CSP will block it until `docker/default.conf` is
updated to match — that is the policy doing its job, not a bug.

`Cross-Origin-Embedder-Policy` is deliberately not set — its `require-corp` mode would block the cross-origin Google
Fonts stylesheet and font files this page loads, for a header this single-page tool has no real use for (no
`SharedArrayBuffer`, nothing needing process isolation). `X-XSS-Protection`, `Feature-Policy`, `Expect-CT` and
`Public-Key-Pins` are also deliberately absent: all four are deprecated (misconfiguring `Public-Key-Pins` in
particular can lock out your own domain), so a scanner listing them as missing is describing correct behavior, not a
gap.

The container has no TLS of its own — it is always meant to sit behind something that terminates TLS for it (Cloudflare
Tunnel in production). It redirects HTTP to HTTPS by checking `X-Forwarded-Proto`, which Cloudflare (and most other
TLS-terminating reverse proxies) sets to the scheme the visitor actually used, not the scheme of the proxy's own hop to
this container. If you front this with something that doesn't set that header, the redirect simply won't fire — set
`Always Use HTTPS` (or equivalent) on your proxy as the primary fix, and treat this header check as the second layer,
not the only one.

---

## Development

```
sun.php               the feed
index.php             the subscription URL builder
Dockerfile            self-contained nginx + php-fpm image
tests/validate.php    50 acceptance checks
tests/regression.sh   17 request shapes diffed against unmodified upstream
tests/generate.php    CLI harness for running sun.php without a web server
```

Run the suite the way CI does, inside the image, so you are testing the runtime that ships:

```sh
docker build -t kythings:dev .
git show upstream/master:sun.php > sun_upstream.php
docker run --rm -v "$PWD":/app -w /app kythings:dev php  /app/tests/validate.php
docker run --rm -v "$PWD":/app -w /app kythings:dev sh   /app/tests/regression.sh
```

`tests/validate.php` covers the three date scenarios that matter for solar arithmetic — an ordinary day, both
daylight-saving transitions, and 31 December into 1 January checked at **both** ends of the feed — plus assertions
that solar noon is never clock noon, that consecutive events stay 24 hours apart in absolute time, and that no PHP
diagnostics leak into the body.

`tests/regression.sh` exists to keep this fork honest: it generates the same 17 request shapes from unmodified
upstream `sun.php` and from this one and requires the event instants to be identical. Adding features should not
move anybody's existing sunrise.

### Pipeline

- **CI** runs on every push and pull request. It builds the image and runs every check inside it, then serves the
  image and inspects the actual HTTP response.
- **Publish** runs on merge to `master`, re-runs the whole suite, and pushes to GHCR as `:latest` and
  `:sha-<short>`.
- **Deploying is a pull.** The pipeline never touches the server — nothing here holds an SSH key or any credential
  for it. Roll back by pointing the compose `image:` at an earlier `:sha-` tag and pulling again.

---

## What this fork changed

Beyond the two new event types and the builder page, three changes to the feed itself were needed to make Google
Calendar accept it. All were pre-existing upstream behaviour:

1. **Every event on a date shared one UID** (`md5($date)`). RFC 5545 treats UID as the identity of a component, so
   several events sharing one, with no `RECURRENCE-ID`, are not several events — they are one event redefined
   contradictorily. Subscribing produced a completely empty calendar. UIDs are now unique per event per day.
2. **`RRULE:FREQ=YEARLY;COUNT=3` on every event**, repeating each day's times in the two following years. That is
   simply wrong for a solar calendar — sunrise on 3 March 2027 is not at 2026's time — and on top of duplicated
   UIDs it made the feed unrenderable. Removed.
3. **`Content-Disposition: attachment`** changed to `inline`. This is a feed to poll, not a file to download.

The calendar's identity is now "Anamanta Kythings" in `X-WR-CALNAME`, `PRODID` and the per-event `DESCRIPTION` and
`URL`.

---

## Credits and licence

This is a fork of **[chrisblakley/Daylight-Calendar-ICS](https://github.com/chrisblakley/Daylight-Calendar-ICS)**
by **Chris Blakley / Gearside**. `sun.php`, `daylight.php`, `nighttime.php` and `calendar.html`, and all of the
sunrise, sunset and twilight calculation, are his work. This fork adds the solar noon and solar midnight event
types, the builder page, the container and the pipeline, and the calendar-compatibility fixes above. The original
project is documented at [gearside.com](https://gearside.com/google-daylight-calendar/).

Licensed under the **GNU General Public License v2.0**, the same licence as the upstream project — see
[LICENSE](LICENSE). Copyright notices are retained, and any derivative of this work must remain under GPL-2.0.

**Please do not report issues with this fork upstream.** The bugs are mine, not Chris Blakley's — raise them on
this repository.
