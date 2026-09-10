# Changelog

All notable changes to this project are documented here.

## Unreleased

### Changed

- Each event type's "Fixed time instead" field is now hidden and disabled
  until its own new "Use a fixed time instead" checkbox is checked, instead
  of always being visible and editable regardless of intent. Checking it
  reveals and enables that event's time field (and focuses it); unchecking
  it hides and disables the field again without clearing whatever was
  typed, so switching it back on restores the value. A disabled field is
  never part of a GET form's submitted data, so an event left off no longer
  contributes an override parameter at all -- the UI-side counterpart to
  #20's `app.js` fix, addressing the same symptom by construction rather
  than by cleanup. Server-side rendering already gets the initial state
  right for any URL a person arrives with (checked+visible when a value is
  present, unchecked+hidden otherwise); the new script in `app.js` is only
  needed for switching the toggle after the page has loaded. Ref #21.

### Fixed

- The "Skip to main content" link's off-screen hiding relied on a fixed
  `top:-40px`, which was shorter than the link's own rendered height
  (~44.8px padding + line-height). The ~5px difference stayed inside the
  viewport permanently -- a visible sliver of accent-green background,
  rounded corner included, in the top-left of every page load, focused or
  not. Switched to `transform:translateY(-100%)` on `.skip-link` (matched by
  `translateY(0)` on `:focus`), which hides by the element's own rendered
  height instead of a guessed pixel value, so it can't reopen this gap if
  the link's padding or font size changes later. Ref #23.

- The builder page's GET form no longer submits an empty `override_*` param
  (e.g. `override_noon=`) for a fixed-time field left blank. `app.js` now
  disables any empty `override_*` input right before the form reads its
  fields for submission -- a disabled control is the one kind of field a GET
  submission always omits, so a checked-but-unset event type's built and
  subscribe URLs no longer carry no-op parameters. Purely cosmetic (the
  server already ignored an empty override), but it keeps generated URLs
  shorter and cleaner. Ref #20.

### Added

- Added `foldLine()` and applied it to every variable-length event property
  (`UID`, `DESCRIPTION`, `URL;VALUE=URI`, `SUMMARY`) so a physical content
  line can never exceed RFC 5545's 75-octet limit. Every value emitted today
  is short by construction (event names top out at "Solar Midnight", the
  description sentences are fixed), so this is a no-op now -- confirmed by
  `tests/regression.sh` staying byte-identical -- but it removes the
  unstated assumption that a future longer `BASE_URL`, description, or
  editable event name would stay short too. Splits are byte-safe against
  UTF-8 (never cuts a multi-byte character across two folded lines). Covered
  by 4 new acceptance checks in `tests/validate.php` (82 total) that force a
  fold with a deliberately oversized `BASE_URL` and confirm every physical
  line stays within the limit and the value survives unfolding intact.

- Each of the four Anamanta times (sunrise, solar noon, sunset, solar
  midnight) can now be pinned to a fixed daily clock time instead of the
  calculated solar time, via new `override_sunrise`, `override_sunset`,
  `override_noon` and `override_midnight` query parameters (`sun.php`) and a
  matching clock-selection field under each checkbox on the builder page
  (`index.php`), labeled identically end to end. Ref BRAIN-52.
  - Sunrise and sunset are now independent at the architecture level — each
    has its own trigger condition, so either can be fixed while the other
    stays calculated, or included via its override alone without needing
    `actual`. The builder page's existing paired sunrise/sunset checkbox UI
    is unchanged; only the backend gained independence.
  - Setting an override forces that event's inclusion even if its checkbox
    or flag is not otherwise set — a filled-in override is treated as clear
    intent.
  - An overridden event is relabeled in the output (`SUMMARY` gains a
    `(fixed)` suffix, `DESCRIPTION` says "a fixed time, not calculated")
    so it is never mistaken for a calculated solar time.
  - A new `tz` parameter (a real IANA name, already collected by the
    builder page's timezone field) resolves override times with correct
    daylight-saving awareness via PHP's `DateTimeZone`. Every *calculated*
    event continues to use only the existing fixed-`gmt` convention,
    unaffected by `tz`; without `tz`, an override falls back to that same
    fixed-offset approximation.
  - An override's event length still comes from the existing `length`
    parameter, same as a calculated event of that type.
  - A malformed or out-of-range override value is silently ignored (falls
    back to the calculated time, or to no event at all), consistent with
    this endpoint's existing no-error-output design.
  - `tests/validate.php` gained 17 new acceptance checks covering DST-aware
    resolution, relabeling, sunrise/sunset independence, forced inclusion,
    graceful degradation on malformed input, duration, and rolling-vs-fixed
    parity. `tests/regression.sh`'s existing 17 cases stay byte-identical
    to upstream, since none of them pass an `override_*` parameter.

### Security

- Removed the deployment's own hostname, which had been hardcoded in six
  places (`index.php`'s `$BASE_URL` default and its `GEOCODER_UA` string,
  `sun.php`'s per-event `UID` and `URL` properties, a `Dockerfile` comment,
  and four spots in `README.md`), so the public repo no longer names where
  any particular instance is actually served. `KYTHINGS_BASE_URL` is now
  **required, with no fallback default** — `index.php` and `sun.php` both
  return a `500` with a plain-text message if it is unset, rather than
  silently defaulting to (now nobody's) URL. `GEOCODER_UA`'s contact URL
  now points at this repository instead of a specific deployment. The
  per-event `UID`'s namespacing suffix changed from the old hostname to
  `anamanta-kythings.invalid` — the RFC 2606 reserved TLD for exactly this
  purpose, a stable string that is not meant to resolve — which is a
  one-time change: any calendar already subscribed will see the whole feed
  as new UIDs on its next refresh, but nothing else about the events
  changes. CI now passes `KYTHINGS_BASE_URL` explicitly to every container
  invocation that exercises either file, and the example `docker-compose`
  block in the README sets it too.
- Added the standard hardening response headers a security scan flags by
  default: `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
  `Strict-Transport-Security` (one year, `includeSubDomains`, no `preload`),
  and a `Content-Security-Policy` covering `default-src`, `script-src`,
  `style-src`, `font-src`, `img-src`, `object-src`, `base-uri`,
  `form-action`, `frame-src` and `frame-ancestors`. Set in
  `docker/default.conf`, which replaces the base image's nginx server block
  (see the Dockerfile for why), so it applies uniformly to `sun.php`,
  `index.php` and the new `app.js`.
- To get a `script-src` with no `'unsafe-inline'`, the builder page's two
  small inline behaviours — the timezone field's focus/blur handling (from
  the fix above) and the copy-address button — moved out of inline
  attributes and a `<script>` block into a same-origin `app.js`. No behaviour
  change; same handlers, same guards, just not inline.
- Both are asserted in CI, matching this project's existing convention of
  checking the actual served response rather than trusting a setting: the
  smoke test confirms every header survives on a real 404, not just a 200
  (an `add_header` without `always` disappears exactly there), and that
  `script-src` stayed a strict `'self'`. The first version of this check used
  an arbitrary unmatched path as its "404" and never actually got one —
  `location / { try_files ... /index.php...; }` (inherited from the base
  image) routes anything unmatched to a 200 from `index.php`; only a
  nonexistent `.php` path hits the block that really 404s. Fixed the same day
  it was caught.
- HTTP visits are now 301'd to HTTPS. The container has no TLS of its own, so
  this reads `X-Forwarded-Proto` (set by Cloudflare Tunnel, and by most other
  TLS-terminating proxies, to the scheme the *visitor* used — not the scheme
  of the proxy's own hop to the container) and redirects only when it is
  exactly `http`. No header at all — a direct, non-proxied request — is left
  alone rather than redirected, since there is nothing to redirect *to* in
  that case. Verified locally against all three cases (`http`, `https`, and
  no header) before this went anywhere near the live site.
- Added `Cross-Origin-Opener-Policy: same-origin`, `Cross-Origin-Resource-Policy: same-origin`
  and a locked-down `Permissions-Policy` (denying camera, microphone,
  geolocation and other features this page never uses). Deliberately did
  NOT add `Cross-Origin-Embedder-Policy` — its `require-corp` mode would
  block the cross-origin Google Fonts stylesheet/font files this page
  depends on, for isolation this single-page tool has no actual need for.
  Also deliberately did NOT add `X-XSS-Protection`, `Feature-Policy`,
  `Expect-CT` or `Public-Key-Pins` — all four are deprecated, and
  `Public-Key-Pins` specifically can lock out your own domain if
  misconfigured, so a scan flagging them as "missing" is describing
  correct behavior, not a gap. Asserted in CI on both a 200 and a real 404,
  same convention as the other headers.
- A scan flagged an "AI agent readiness" checklist (MCP/A2A discovery,
  OAuth/OIDC metadata, DNS-AID records, WebMCP, an API catalog, a
  sitemap) as all failing. None of it applies: this is a single form with
  no API surface for an agent to call, not a service meant to be consumed
  by one, and the sitemap check in particular runs directly against the
  page's own `<meta name="robots" content="noindex, nofollow">`, which is
  there on purpose. Not implemented, and not planned.

### Accessibility

- Added a keyboard-focusable "Skip to main content" link as the first
  focusable element on the page, and wrapped the page's content in a
  `<main id="main">` landmark for it to target. The page had no nav or
  header to bypass, but it also had zero landmarks at all, so this fixes
  both a WCAG 2.4.1 (Bypass Blocks) finding and the missing-landmark gap
  in one change. Visually hidden off-screen until focused (`top:-40px`,
  not `display:none`, so it stays in the accessibility tree and reachable
  by keyboard).
- All 5 `target="_blank"` links (the lat/long lookup, the php.net timezone
  reference, and the Google/Outlook/alternate-Google subscribe links) now
  carry a visible "&#8599;" icon plus screen-reader-only "(opens in new
  tab)" text, addressing a WCAG 3.2.5 (Change on Request) finding that a
  new tab opening unannounced is an unexpected context change. The icon is
  `aria-hidden`; the notice text is real text in a visually-hidden
  (`.sr-only`) span, not an `aria-label` override, so it is announced in
  addition to the link's own text rather than replacing it.
- Text inputs (`input[type=text]`, `input[type=number]`) now border in
  `--dim` (`#5f6769`, ~5.8:1 against the white field background) instead
  of `--line` (`#d8d4cc`, ~1.48:1), fixing a WCAG 1.4.11 (Non-text
  Contrast) finding on form-control boundaries. Scoped to form controls
  only — `--line` is unchanged everywhere else it's used (fieldset
  outlines, `hr`, `.note`/`.url` boxes), since those are decorative
  dividers, not UI-component boundaries the criterion applies to.
- Known, deliberately out of scope: the native checkboxes under "What to
  include" and the `<details><summary>` disclosure triangle both render
  with the browser's own UI, which isn't reliably restylable with plain
  CSS alone. A custom control would fix it but adds real complexity and
  risk for a boundary most browsers already render with reasonable
  contrast by default; left alone unless it's specifically flagged.
- The 4 "What to include" checkboxes were already inside a wrapping
  `<label>` (`<label><input ...> Sunrise</label>`), which is a valid,
  spec-compliant way to associate a label under WCAG 1.3.1 — screen
  readers already announced them correctly. A scan flagged them as
  "missing labels" anyway; some automated checkers only credit the
  explicit `<label for="id">` form and don't recognise implicit
  wrapping. Added matching `id`/`for` pairs alongside the existing
  wrapping (belt and suspenders, not a behavior change) so it reads
  correctly to tools that don't credit the implicit form either.

### Fixed

- Timezone field on `index.php` appeared to offer only one option (the
  pre-filled default) because browsers filter native `<datalist>` suggestions
  against the field's current text — with a full IANA name already typed in,
  only that exact entry matched. The datalist itself was always fully
  populated (all 419 zones from `timezone_identifiers_list()`). The field now
  clears on focus, so clicking it reveals the whole list, and restores the
  prior value on blur if nothing was chosen.
- `favicon.ico` was 404ing — nothing was ever served there, and browsers
  request it by default regardless of what `<link rel="icon">` a page
  declares. Added an original sun glyph (a filled circle with eight rays,
  in the site's existing `--accent` green) as both `favicon.ico` (multi-size,
  16/32/48/64px) and `favicon.svg`, referenced from `index.php` and asserted
  reachable in CI.

### Changed

- Restyled `index.php` to align its palette and heading typeface with
  earthspirit.com: `--accent` moved from brown (`#7a4b12`) to forest green
  (`#1d5c2e`), `--bg`/`--warm` shifted from cream to a pale green, and `h1`/
  `h2` now render in **Cinzel** (Google Fonts, loaded via `<link>`) to echo
  EarthSpirit's serif headings. Body copy and form fields keep the existing
  system sans-serif stack for legibility — this is a palette/type change
  only, no layout or functional changes, and no EarthSpirit logo, wordmark
  or copy was reused. Follow-up to BRAIN-49.
