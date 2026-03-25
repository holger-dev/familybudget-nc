# Changelog

## 0.4.2 - 2026-03-25
- Fixed controller access metadata by adding current Nextcloud PHP attributes for authenticated and OCS routes.
- Restored mobile app login and API access on Nextcloud 32–33 installations affected by the 0.4.1 regression.

## 0.4.0 - 2026-03-25
- Added support for creating and editing expenses on behalf of users who are shared into the selected book.
- Updated the app compatibility range to Nextcloud 32–33 and moved the local Docker setup to Nextcloud 33.
- Fixed Nextcloud 33 runtime issues in the book, member, and expense endpoints so newly created books and related data load correctly again.
- Updated the sharing flow so invited members appear immediately in the payer selection without requiring a manual reload.

## 0.3.5 - 2026-02-08
- Added GitHub Actions release automation to build, sign, attach the app tarball to the GitHub Release, and publish it to the Nextcloud App Store.
