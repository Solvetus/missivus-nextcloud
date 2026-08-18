# Contributing

Pull requests are welcome. A few ground rules:

- **The vendored transport is off limits here.** Everything under
  `lib/Vendor/Solvetus/Missivus/` is shipped byte-identical from
  [missivus-matomo](https://github.com/Solvetus/missivus-matomo). Fixes to it go upstream there
  first, never here — each release proves the tree unchanged with `diff -r`.
- Run `composer install` once, then `composer test` and `composer psalm` before opening a PR.
- Non-English strings in this repository (`l10n/*`) are AI-produced and human-reviewed, not
  written by native speakers of every language. Corrections are welcome and appreciated.
- Security reports go to `security@missivus.com`, not the issue tracker.

No CLA, no DCO — the GPLv3 covers it.
