# Daylight Calendar ICS

This is a dynamically generated .ics calendar that you can host and subscribe to in Google Calendar, iCal, or other calendar software.

Not only will it provide an event each day with the appropriate sunrise and sunset time, it will show the length of the day in hours/minutes as well as in a percent (of 24 hours) and the solar noon for that day. It will also give a percentile compared to the shortest and longest days of the year!

## Options

- [Find your geo coordinates](https://www.latlong.net/)
- [Find your timezone name](https://www.php.net/manual/en/timezones.php)
- [Find your GMT offset](http://en.wikipedia.org/wiki/List_of_UTC_time_offsets#mediaviewer/File:World_Time_Zones_Map.png)

## Instructions

- Upload `daylight.php` and/or `sun.php` to your server (or skip this step and use the one hosted on [gearside.com](https://gearside.com/calendars/daylight.php))
- Point your calendar to the file and use query parameters for the options above.
  - Latitude: `lat`
  - Longitude: `lng`
  - Timezone (preferred way): `timezone`
  - GMT Offset (alternate way): `gmt`
  - Year: `year`
  - Event types (`sun.php` only):
    - `actual`
    - `civil`
    - `nautical`
    - `astronomical`
    - `all`

Use `?debug` to directly view the calendar file in a browser with events more easily readable. Be sure not to use `?debug` when subscribing to your calendar as it does not declare itself as an .ics file with that parameter present.

## Examples

#### Basic

Most reliable method:
`https://gearside.com/calendars/daylight.php?lat=43.1234&lng=-76.1234&timezone=America/New_York`

Your mileage may vary if only passing GMT offset:
`https://gearside.com/calendars/daylight.php?lat=43.1234&lng=-76.1234&gmt=-5`

`https://gearside.com/calendars/sun.php?lat=43.1234&lng=-76.1234&gmt=-5&all`

## Notes

Calendar software caches remote .ics files (like this one), so when replacing it you can "bust" the cache by adding another query parameter of random characters such as `&sdfgsfd`.

- [More information available at Gearside.com](https://gearside.com/google-daylight-calendar/)

## About this fork

This is a fork of [chrisblakley/Daylight-Calendar-ICS](https://github.com/chrisblakley/Daylight-Calendar-ICS)
by Chris Blakley / Gearside, which is the origin of `sun.php`, `daylight.php` and `nighttime.php` and of the
sunrise/sunset and twilight calculations. All credit for the original work is his.

This fork adds, for self-hosting at `kythings.walkowiaks.com`:

- `noon` and `midnight` event types, using `date_sun_info()` transit rather than clock noon
- `index.php`, a subscription URL builder
- a container image and a CI/publish pipeline

It also changes the calendar's identity to "Anamanta Kythings", gives every event a unique UID and drops the
per-event yearly `RRULE`. The last two were necessary: sharing one UID across every event on a date, plus a
recurrence rule, made Google Calendar render the subscription empty.
