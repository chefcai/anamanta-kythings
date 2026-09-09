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
  smoke test now confirms every header survives on a 404, not just a 200
  (an `add_header` without `always` disappears exactly there), and that
  `script-src` stayed a strict `'self'`.
- HTTP visits are now 301'd to HTTPS. The container has no TLS of its own, so
  this reads `X-Forwarded-Proto` (set by Cloudflare Tunnel, and by most other
  TLS-terminating proxies, to the scheme the *visitor* used — not the scheme
  of the proxy's own hop to the container) and redirects only when it is
  exactly `http`. No header at all — a direct, non-proxied request — is left
  alone rather than redirected, since there is nothing to redirect *to* in
  that case. Verified locally against all three cases (`http`, `https`, and
  no header) before this went anywhere near the live site.

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
