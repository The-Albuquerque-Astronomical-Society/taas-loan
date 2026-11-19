# TAAS Loan Program

[![CI](https://github.com/The-Albuquerque-Astronomical-Society/taas-loan/actions/workflows/ci.yml/badge.svg)](https://github.com/The-Albuquerque-Astronomical-Society/taas-loan/actions/workflows/ci.yml)

A WordPress plugin that runs the [Telescope Loan Program](https://taas.org/telescope-loan-program/) for **The Albuquerque Astronomical Society (TAAS)**.

TAAS lends telescopes, large binoculars, premium eyepieces and accessories to its members free of charge. This plugin lets members browse the equipment and request a loan on the TAAS website, and lets loan volunteers track what's out, who has it, and when it came back.

## How the loan program works

- **Anyone** visiting the site can browse all the equipment and see whether each item is in or out.
- **Members** log in to check out an item.
- The **Loan Program Manager (LPM)** handles requests, pickups and returns.

### Loan rules

- **Loan length:** a member can keep the equipment for **at least 30 days**.
- **After 30 days:** the member returns it when the LPM asks. If the LPM doesn't ask, they can keep it as long as they like.
- **Returning early:** a member can contact the LPM at any time to arrange a return.
- **Late returns:** there are no late fees or penalties.
- **Damage:** if equipment is damaged, the borrower contacts the LPM to arrange a repair. The borrower pays for the repair if they caused the damage.

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

- WordPress 7.1 or later
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
3. Members can then browse the equipment at `https://your-site.com/shared_item/`, or on any page where you add the shortcode below.

## Showing the equipment on a page

Add this shortcode to any page or post (for example the existing Telescope Loan Program page):

```
[taas_loan_items]
```

It works like the old Vik Rent Items pages:

1. **Category cards:** a card with a picture and name for each category (Telescopes, Premium Eyepieces, Accessories).
2. **Click a category:** the same page lists its items. Each has a picture, name, short description, an **In / Out / Requested** label and a **View details** button.
3. **Click an item:** a clean item page with a big picture, the full description and its status. Members can **Request this item**. Visitors who aren't logged in can still see everything, plus a box with **Log in to request** and **Join TAAS** buttons.

Borrowers' names are never shown. The old `/shared_item/` address and category addresses forward to this page.

Category pictures and descriptions come from Vik Rent Items when you import. Categories without a picture use their first item's picture.

Options:

| Shortcode | What it shows |
| --- | --- |
| `[taas_loan_items]` | Category cards; click one to see its items |
| `[taas_loan_items view="list"]` | Every item as a list, grouped by category |
| `[taas_loan_items view="grid"]` | Every item as picture cards, grouped by category |
| `[taas_loan_items category="telescopes,accessories"]` | Only these categories (use the slugs), in this order |
| `[taas_loan_items status="no"]` | Hide the In / Out labels |

In the block editor, add a **Shortcode** block and paste it in.

### Moving over from Vik Rent Items

If the site already lists the equipment in **Vik Rent Items**, you can copy it all over instead of typing it in:

1. Install and activate this plugin on the **same WordPress site** that runs Vik Rent Items.
2. Go to **TAAS Loan Program > Import**.
3. Check the preview, then click **Import**. It works in small batches with a progress message, so keep the page open until it finishes.

For each item it copies the **name, description, categories, main picture and extra pictures**. The pictures go into the Media Library.

- Vik Rent Items itself isn't changed, so you can keep it running until you're ready to switch.
- It's safe to run again: items that were already imported are skipped, and any that are missing their picture get it.
- If it stops part way (for example the connection drops), click **Import** again to carry on.
- Items hidden in Vik Rent Items come in as **drafts**.
- An item with more than one unit (e.g. 2 identical Solar Binoculars) comes in as separate entries, "Solar Binoculars #1" and "#2", so each one can be lent out on its own. They share the same pictures.

On the command line (WP-CLI), the same import is:

```sh
wp taas-loan import-vri --dry-run   # preview only
wp taas-loan import-vri             # import
```

## Settings

Found under **Settings > TAAS Loan Program**:

| Setting | What it does | Suggested for TAAS |
| --- | --- | --- |
| Make public (visible without login) | Lets anyone see the equipment list, not just logged-in members | **On.** Anyone can browse; checking out still needs a member login. |
| Hide rating and comment form | Turns off member reviews | Your choice |
| Join page | Where the **Join TAAS** button goes | Leave empty: it finds the `/join-taas/` page |
| Login page | Where the **Log in to request** button goes | Leave empty: it finds the `/login/` page |
| Loan Manager email | Who gets the email when a member requests an item. Separate several with commas | The Loan Program Manager's address. Empty uses the site admin email |
| Mobile / Home phone field | Which [Participants Database](https://xnau.com/work/wordpress-plugins/participants-database/) fields go in the request email | Leave on **Automatic** if it shows the right fields |
| Confirm requests to members | Emails the member that their request arrived and the Loan Director will be in touch. Replying reaches the Loan Director | **On** |
| Send emails from | The address request emails come from (shown as "TAAS Loan Program") | Leave empty for `noreply@` this site's domain |
| Send test email | Sends a sample request (with you as the member) to the Loan Manager email | Try it after saving |
| Private test link | A link that sends a test email without logging in. Anyone with it can send one (at most once a minute), so keep it private. **Make a new link** turns off the old one | Bookmark it |

### Starting over

At the bottom of **Settings > TAAS Loan Program**, **Delete all data** permanently removes every item, loan record and category, plus (if ticked) the pictures attached to items. Type `DELETE` to confirm. Your settings, other pages and posts, and Vik Rent Items are not touched. Back up the site first: this can't be undone.

On the command line: `wp taas-loan reset` (add `--keep-pictures` to keep the pictures).

## Handling loans

1. A logged-in member clicks the request button on an item. The Loan Manager gets an email with the item, the member's name, email, mobile and home phone, and an **Open the Loan Portal** button, and the item shows as **requested**. Phone numbers come from the member's Participants Database record with the same email as their WordPress account. Replying to the email goes straight to the member. The member also gets a confirmation email saying the Loan Director will be in touch.
2. When the member picks it up, open **TAAS Loan Program > Lendings**, edit the request, and fill in the lending date and return date.
3. When it comes back, tick it in the list and choose **Mark as returned**. Edit it to add a note if anything's wrong (e.g. "missing lens cap"). The item shows as **available** again.

> **Note:** dates currently use the `dd/mm/yyyy` format (day first).

## Planned features

Requested by the Loan Program Manager. More details are coming.

- [x] **In/Out status:** show a clear "In" or "Out" on every item, in the list and on the item page.
- [ ] **30-day reminders:** email the LPM when a loan reaches 30 days, so they can decide whether to ask for it back. (Not an "overdue" notice: there are no late returns.)
- [ ] **No fixed return date:** let the LPM leave the return date empty, so an item stays **Out** until it's actually returned.
- [ ] **Show the loan rules on the site:** so members see them before they request an item.
- [x] **LPM email setting:** send request emails to the LPM, not the site's admin email. (Reminder emails will use it too.)
- [ ] **Waiting list:** let members request an item that's out, and show the LPM who's waiting, in order.
- [x] **Join link:** point "register" links to the [TAAS membership page](https://taas.org/join-taas/) instead of WordPress sign-up.
- [x] **Privacy:** don't show the borrower's username to the public.

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
