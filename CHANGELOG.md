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
- Request emails now come from "TAAS Loan Program" at a real address on the site's domain (new **Send emails from** setting). The return address is left to the host or a mail plugin (like WP Mail SMTP), since setting it ourselves got emails lost.
- New **Private test link** in settings: bookmark it to send a test email without logging in. The page says whether it worked and, if not, why.
- Request emails now include a plain-text copy alongside the formatted one, which spam filters expect.
- The test link page has an **email check** that sends four versions, from very basic up to the real email, to find out what's keeping emails from arriving.
- Members now get a confirmation email when they request an item: their request arrived, and the Loan Director will be in touch to arrange pickup. Replying reaches the Loan Director. Turn it off with **Confirm requests to members** in settings. Test emails also send a sample of it.
- Item pages now say "Loan Director" instead of "Loan Program Manager".
- New **TAAS Loan Program > Check out** page: the Loan Director picks an item and a member, sets the checkout and return dates, and the loan team and the member each get a professional email with the return date. Requested items are listed first and pick their requester automatically. Request emails have a **Check it out to them** button that opens this page ready to go.
- Lending form (Lendings > Edit) no longer has Reviews or Rating. It has a **Pickup date**, a **Return date** that fills in 30 days later, and a **Status**: Checked Out, Returned, or Damaged needs repair. The Check out page has the same Status and 30-day return date.
- The **Availability** column on the Lendings page now shows that Status. New bulk action **Mark as damaged needs repair**.
- Damaged items show **Being repaired** on the website and can't be requested or checked out until marked Returned.
- The admin menu now has a telescope icon, and **Import** is at the bottom of the menu.
- **All objects** has an **Availability** dropdown on each unit (Checked Out, Returned, Damaged needs repair) that saves as soon as it changes. Picking Checked Out opens a small box right there to pick the member and the pickup and return dates (with a calendar, return set 30 days out); saving it adds the loan to Lendings and emails the member and the loan team, just like the Check out page.

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
