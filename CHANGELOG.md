# Changelog

All notable changes to this project are listed here.

## [Unreleased]

## [2.0.0] - 2026-11-18

First release as the TAAS Loan Program, forked from Sharing Club 1.3.1 by netdelight.

### Running loans
- New **Check out** page: pick an item and a member, set the pickup and return dates, and the loan team and the member each get an email with the return date. Requested items are listed first and pick their requester automatically.
- **All objects** has an **Availability** dropdown on each unit. Picking **Checked Out** opens a small box right there to pick the member and dates (calendar pickers, return set 30 days out), adds the loan to Lendings, and sends the same emails as the Check out page.
- Every loan has a **Status**: Checked Out, Returned, or Damaged needs repair. It drives the **Availability** column on Lendings and All objects and the label members see on the website. A Checked Out item stays out until it's marked Returned.
- **Damaged needs repair** shows **Being repaired** on the website, and the item can't be requested or checked out until it's marked Returned.
- The lending form (Lendings > Edit) has a **Pickup date**, a **Return date** that fills in 30 days later, and the Status. Reviews and Rating are gone from it.
- New bulk action on Lendings: **Mark as damaged needs repair**.
- New **Manual** page: a plain-language guide to running the program, from requests to returns, every menu item, emails, settings and troubleshooting.

### Emails
- Requests email the Loan Manager: who asked, for which item, their email, mobile and home phone (from Participants Database), with **Open the Loan Portal** and **Check it out to them** buttons. Replying goes to the member.
- Members get a confirmation email when they request an item (can be turned off).
- Emails come from "TAAS Loan Program" at an address on the site's domain (**Send emails from** setting), and include a plain-text copy so spam filters accept them.
- **Send test email**, a **Private test link** that works without logging in, and a four-step **email check** to find out what's stopping emails from arriving.

### Website
- `[taas_loan_items]` shortcode: category cards that open a list of items (picture, short description, In / Out / Requested / Being repaired label). Options for a single list, picture grid, chosen categories, or no labels.
- Item pages show a clean card with a big picture and the full description, and are visible to everyone. Visitors get **Log in to request** and **Join TAAS** buttons (new **Join page** and **Login page** settings). Pages say "Loan Director".

### Admin
- Telescope menu icon and a clearer menu order: All objects, Lendings, Check out, Add object, Categories, other plugins' items, Import, Manual.
- **Import** copies equipment from Vik Rent Items: names, descriptions, short descriptions, categories (in the same order) and pictures, with one entry per unit ("Name #1", "Name #2"). Runs in small batches so big imports don't time out, and is safe to run again. Also `wp taas-loan import-vri`.
- **Delete all data** at the bottom of Settings > TAAS Loan Program (and `wp taas-loan reset`) to start over.

### Fixes and security
- Members can only review their own loans (before, the review form could change any comment on the site).
- An item that's already out can't be requested again.
- Fixed a database error on the Lendings page ("Column 'comment_ID' in field list is ambiguous").
- Automatic updates from WordPress.org are turned off, so the original plugin can't overwrite this fork.

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
