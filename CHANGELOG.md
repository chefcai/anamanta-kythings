# Changelog

All notable changes to this project are documented here.

## Unreleased

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
