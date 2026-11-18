# TAAS Loan Program

A WordPress plugin for sharing books, DVDs, tools, toys or any other object with your community. Members can borrow, comment on and rate items, and admins can track who borrowed what and when.

This is a fork of [Sharing Club](https://wordpress.org/plugins/sharing-club/) by [netdelight](http://netdelight.be/) (Manu Z.).

## Features

- Keep a list of items you lend out (books, DVDs, tools…)
- Members can request items, leave comments and give ratings
- Admins can see what's lent out, who has it, and when it's due back
- Email notification when someone requests an item
- Admin notes on items and on each lending (e.g. "returned damaged")
- Option to show items to everyone, without logging in
- Option to hide the rating and comment form
- Translations: English, French and Dutch

Items are standard WordPress posts (a custom post type with its own categories), so they work with search, themes and other plugins.

## Requirements

- WordPress 4.0 or later (tested up to 6.1.1)
- A theme that supports featured images, if you want item pictures in the list

## Installation

1. Download or clone this repo into `/wp-content/plugins/taas-loan`.
2. In the WordPress admin, go to **Plugins** and activate **TAAS Loan Program**.
3. Go to **TAAS Loan Program > Add object** to add your first item.
4. Manage lendings from **TAAS Loan Program > Lendings**.

Your items list will be at `https://your-site.com/shared_item/`.

> Dates use the `dd/mm/yyyy` format.

## Customizing the look

You can change how items are shown by editing the template files:

| File | What it does |
| --- | --- |
| `templates/archive-shared_item.php` | The list of all items |
| `templates/Walker_Category_Posts.php` | How each item in the list is drawn (a WordPress [walker](https://developer.wordpress.org/reference/classes/walker/)) |
| `templates/single-shared_item.php` | A single item page with the booking form |

## FAQ

### Can I add more fields to items or lendings?

Yes. Use [Advanced Custom Fields](https://wordpress.org/plugins/advanced-custom-fields/) for extra fields.

Lendings are stored as WordPress comments, mapped like this:

| Lending field | `wp_comments` column |
| --- | --- |
| User | `user_id` |
| Object | `comment_post_ID` |
| Lending date | `comment_date` |
| Return date | `comment_date_gmt` |
| Review | `comment_content` |
| Admin notes | `comment_agent` |
| Rating | `comment_karma` |

Other columns in `wp_comments` are free to use for extra data.

### Can members share their own items?

Yes. For non-admin users, use a plugin like [Ultimate Member](https://wordpress.org/plugins/ultimate-member/) to give them limited admin access. To show the owner's name next to an item, uncomment `<?php the_author(); ?>` in `templates/single-shared_item.php` (or edit `templates/Walker_Category_Posts.php` for the list).

### Does it work with BuddyPress?

Yes — it was originally built for a BuddyPress community.

### Can I import many items at once?

Yes. Save a spreadsheet as CSV and import it with a plugin like [Really Simple CSV Importer](https://wordpress.org/plugins/really-simple-csv-importer/).

## Translating

Translation files live in the `languages` folder (`.po` / `.mo`). Text domain: `sharing-club`.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Credits

- Original plugin: **Sharing Club** by netdelight (Manu Z.), with contributions from jdevroed, mpol and joeytroy.
- Plugin icon based on "Hand" by Nick Abrams from the Noun Project.

## License

Licensed under the GNU General Public License v2.0 or later. See [LICENSE](LICENSE).
