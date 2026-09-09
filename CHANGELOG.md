# Changelog

All notable changes to this project are documented here.

## Unreleased

### Security

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

### Changed

- Restyled `index.php` to align its palette and heading typeface with
  earthspirit.com: `--accent` moved from brown (`#7a4b12`) to forest green
  (`#1d5c2e`), `--bg`/`--warm` shifted from cream to a pale green, and `h1`/
  `h2` now render in **Cinzel** (Google Fonts, loaded via `<link>`) to echo
  EarthSpirit's serif headings. Body copy and form fields keep the existing
  system sans-serif stack for legibility — this is a palette/type change
  only, no layout or functional changes, and no EarthSpirit logo, wordmark
  or copy was reused. Follow-up to BRAIN-49.
