# TAAS Loan Program

[![CI](https://github.com/joeytroy/taas-loan/actions/workflows/ci.yml/badge.svg)](https://github.com/joeytroy/taas-loan/actions/workflows/ci.yml)

A WordPress plugin that runs the [Telescope Loan Program](https://taas.org/telescope-loan-program/) for **The Albuquerque Astronomical Society (TAAS)**.

TAAS lends telescopes, large binoculars, premium eyepieces and accessories to its members free of charge. This plugin lets members browse the equipment and request a loan on the TAAS website, and lets loan volunteers track what's out, who has it, and when it came back.

## How the loan program works

- **Anyone** visiting the site can browse all the equipment and see whether each item is in or out.
- **Members** log in to check out an item.
- Loans last **30 days**.
- The **Loan Program Manager (LPM)** handles requests, pickups and returns.

It's based on the [Sharing Club](https://wordpress.org/plugins/sharing-club/) plugin by Manu Z. (netdelight).

## What it does

**For members**
- Browse the equipment by category (Telescopes, Premium Eyepieces, Accessories)
- See whether an item is available or already requested
- Request an item from its page
- Rate and review equipment after using it (optional)

**For loan volunteers (admins)**
- Add equipment with photos, descriptions and categories
- Get an email when a member requests an item
- See every loan: who borrowed what, when it went out, and when it came back
- Add private notes to an item (e.g. serial number, storage spot) or to a loan (e.g. "returned with missing lens cap")

## Requirements

- WordPress 4.0 or later (tested up to 6.1.1)
- PHP 7.4 or later
- A theme that supports featured images, so equipment photos show up in the list

## Installation

1. Download or clone this repo into `/wp-content/plugins/taas-loan`.
2. In the WordPress admin, go to **Plugins** and activate **TAAS Loan Program**.
3. Go to **Settings > TAAS Loan Program** and choose your options (see below).

## Setting up the equipment list

1. Go to **TAAS Loan Program > Categories** and create the categories, for example:
   - Telescopes
   - Premium Eyepieces
   - Accessories
2. Go to **TAAS Loan Program > Add object** for each piece of equipment:
   - **Title:** the item name (e.g. "8-inch Dobsonian")
   - **Description:** what's included, how to set it up, any care tips
   - **Featured image:** a photo of the item
   - **Excerpt:** a private note for volunteers (e.g. inventory number). Members don't see it.
3. Members can then browse the equipment at `https://your-site.com/shared_item/`.

Have a lot of equipment? Put it in a spreadsheet, save it as CSV, and import it with a plugin like [Really Simple CSV Importer](https://wordpress.org/plugins/really-simple-csv-importer/).

## Settings

Found under **Settings > TAAS Loan Program**:

| Setting | What it does | Suggested for TAAS |
| --- | --- | --- |
| Make public (visible without login) | Lets anyone see the equipment list, not just logged-in members | **On.** Anyone can browse; checking out still needs a member login. |
| Hide rating and comment form | Turns off member reviews | Your choice |

## Handling loans

1. A logged-in member clicks the request button on an item. An email goes to the site's admin email address (set in **Settings > General**), and the item shows as **requested**.
2. When the member picks it up, open **TAAS Loan Program > Lendings**, edit the request, and fill in the lending date and return date.
3. When it comes back, tick it in the list and choose **Mark as returned**. Edit it to add a note if anything's wrong (e.g. "missing lens cap"). The item shows as **available** again.

> **Note:** dates currently use the `dd/mm/yyyy` format (day first).

## Planned features

Requested by the Loan Program Manager. More details are coming.

- [ ] **In/Out status:** show a clear "In" or "Out" on every item, in the list and on the item page.
- [ ] **30-day reminders:** email the LPM when a loan reaches 30 days.
- [ ] **LPM email setting:** send request and reminder emails to the LPM, not the site's admin email.
- [ ] **Waiting list:** let members request an item that's out, and show the LPM who's waiting, in order.
- [ ] **Join link:** point "register" links to the [TAAS membership page](https://taas.org/join-taas/) instead of WordPress sign-up.
- [ ] **Privacy:** don't show the borrower's username to the public when the list is public.

## Customizing the look

The pages members see come from these template files:

| File | What it shows |
| --- | --- |
| `templates/archive-shared_item.php` | The full equipment list |
| `templates/Walker_Category_Posts.php` | How each item appears in the list |
| `templates/single-shared_item.php` | A single item page with the request form |

## For developers

### How loans are stored

Equipment items are a WordPress custom post type (`shared_item`) with their own categories. Loans are stored as WordPress comments, mapped like this:

| Loan field | `wp_comments` column |
| --- | --- |
| Member | `user_id` |
| Item | `comment_post_ID` |
| Lending date | `comment_date` |
| Return date | `comment_date_gmt` |
| Review | `comment_content` |
| Volunteer notes | `comment_agent` |
| Rating | `comment_karma` |

To add more fields to items or loans, use [Advanced Custom Fields](https://wordpress.org/plugins/advanced-custom-fields/).

Internal names (`sharing-club`, `shared_item`) are kept from the original plugin so existing data and translations keep working.

### Running the checks

Every push to `master` and every pull request is checked automatically on GitHub. To run the same checks on your computer:

```sh
npm install        # JavaScript and CSS tools
npm run lint       # check JavaScript and CSS

composer update    # PHP tools (needs PHP and Composer)
composer lint      # check PHP for security, compatibility and translation issues

bin/check-translations.sh   # check translation files (needs gettext)
```

The PHP check is in report-only mode for now while issues from the original plugin are fixed. See `PHPCS_BLOCKING` in `.github/workflows/ci.yml`.

### Translations

Translation files are in the `languages` folder (`.po` / `.mo`). Text domain: `sharing-club`. French and Dutch are included from the original plugin.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Credits

- Built for [The Albuquerque Astronomical Society](https://taas.org/).
- Based on **Sharing Club** by Manu Z. (netdelight), with contributions from jdevroed, mpol and joeytroy.
- Plugin icon based on "Hand" by Nick Abrams from the Noun Project.

## License

Licensed under the GNU General Public License v2.0 or later. See [LICENSE](LICENSE).
