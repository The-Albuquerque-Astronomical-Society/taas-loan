# Changelog

All notable changes to this project are listed here.

## [Unreleased]

- Renamed the plugin to TAAS Loan Program (forked from Sharing Club by netdelight).
- Turned off automatic updates from WordPress.org so the original plugin can't overwrite this fork.
- Added README, CHANGELOG and LICENSE files.
- Added an importer that copies equipment (names, descriptions, categories and pictures) from Vik Rent Items. Find it at TAAS Loan Program > Import, or run `wp taas-loan import-vri`.
- Added the `[taas_loan_items]` shortcode to show the equipment on any page, with In / Out / Requested labels.
- The importer now creates categories in the same order as Vik Rent Items.

## [1.3.1] - 2022-12-02

- Fixed some missing translations (thanks joeytroy).

## [1.3] - 2019-03-13

- Fixed the user name display bug on the shared item page.
- Added an option to make shared items visible to anyone (no login needed).
- Added an option to hide the rating and comment form.

## [1.2]

- Fixed the admin capabilities bug.
- Added Dutch translation (thanks Jeroen).

## [1.1]

- Items: the "excerpt" field can hold a short admin-only note (e.g. library reference).
- Lendings: added an "admin note" field (e.g. returned damaged, broken, dirty). Thanks Jeroen for the suggestions.
- Better compatibility with other plugins such as ACF, Ultimate Member and Really Simple CSV Importer.

## [1.0]

- First public release.
