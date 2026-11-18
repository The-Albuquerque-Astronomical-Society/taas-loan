# Changelog

All notable changes to this project are listed here.

## [Unreleased]

- Renamed the plugin to TAAS Loan Program (forked from Sharing Club by netdelight).
- Turned off automatic updates from WordPress.org so the original plugin can't overwrite this fork.
- Added README, CHANGELOG and LICENSE files.
- Added an importer that copies equipment (names, descriptions, categories and pictures) from Vik Rent Items. Find it at TAAS Loan Program > Import, or run `wp taas-loan import-vri`.
- Added the `[taas_loan_items]` shortcode to show the equipment on any page, with In / Out / Requested labels.
- The importer now creates categories in the same order as Vik Rent Items.
- The importer now creates one entry per unit for items with more than one ("Name #1", "Name #2"), sharing the same pictures.
- The importer now runs in small batches with a progress message, so big imports don't hit the web host's time limit (504 Gateway Timeout). Re-running also adds missing pictures to items that were already imported.
- New look modelled on the old Vik Rent Items pages: `[taas_loan_items]` shows category cards, clicking one lists its items (picture, short description, status, View details), and item pages show a clean card with a big picture and full description.
- Item pages are visible to everyone, even when the site shows a members-only notice. Visitors get **Log in to request** / **Join TAAS** buttons instead of a WordPress "register" link. New **Join page** and **Login page** settings.
- The importer now also brings over each item's short description and each category's picture and description, and fills these in on items imported earlier.
- Security: members can only review their own loans (before, the review form could change any comment on the site), and an item that's already out can't be requested again.
- Added **Delete all data** at the bottom of Settings > TAAS Loan Program (and `wp taas-loan reset`) to start over.
- Loan requests now send the Loan Manager a clean, professional email: who asked, for which item, their email, mobile and home phone (looked up in Participants Database by their WordPress account email), and an **Open the Loan Portal** button. Replying goes straight to the member. Set the **Loan Manager email**, pick the phone fields, and **Send test email** in Settings > TAAS Loan Program.

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
