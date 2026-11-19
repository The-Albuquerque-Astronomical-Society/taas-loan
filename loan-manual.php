<?php
/**
 * "Manual" page: how to run the TAAS Loan Program, in plain language.
 *
 * @package TAAS_Loan_Program
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TAAS Loan Program > Manual.
 */
class TAAS_Loan_Manual {

	/** Admin page slug. */
	const PAGE = 'taas_loan_manual';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
	}

	/**
	 * Add TAAS Loan Program > Manual.
	 */
	public static function add_menu() {
		add_submenu_page(
			'edit.php?post_type=shared_item',
			__( 'TAAS Loan Program Manual', 'sharing-club' ),
			__( 'Manual', 'sharing-club' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Link to an admin screen.
	 *
	 * @param string $path  Path under wp-admin.
	 * @param string $label Link text.
	 * @return string HTML.
	 */
	private static function link( $path, $label ) {
		return '<a href="' . esc_url( admin_url( $path ) ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * The manual.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$objects   = self::link( 'edit.php?post_type=shared_item', 'All objects' );
		$add       = self::link( 'post-new.php?post_type=shared_item', 'Add object' );
		$cats      = self::link( 'edit-tags.php?taxonomy=shared_item_category&post_type=shared_item', 'Categories' );
		$lendings  = self::link( 'edit.php?post_type=shared_item&page=display_lending_table', 'Lendings' );
		$checkout  = self::link( 'edit.php?post_type=shared_item&page=' . TAAS_Loan_Checkout::PAGE, 'Check out' );
		$import    = self::link( 'edit.php?post_type=shared_item&page=' . TAAS_VRI_Importer::PAGE, 'Import' );
		$settings  = self::link( 'options-general.php?page=theme-settings', 'Settings > TAAS Loan Program' );
		$days      = (int) TAAS_Loan_Checkout::DEFAULT_DAYS;
		$allowed   = array(
			'a' => array( 'href' => array() ),
		);
		$a         = function ( $html ) use ( $allowed ) {
			echo wp_kses( $html, $allowed );
		};
		?>
		<style>
			.taas-manual { max-width: 860px; }
			.taas-manual h2 { margin-top: 2em; padding-top: 1em; border-top: 1px solid #dcdcde; }
			.taas-manual h3 { margin-top: 1.5em; }
			.taas-manual p, .taas-manual li { font-size: 14px; line-height: 1.6; }
			.taas-manual ol, .taas-manual ul { margin-left: 1.5em; }
			.taas-manual ul { list-style: disc; }
			.taas-manual .taas-toc { background: #fff; border: 1px solid #dcdcde; padding: 12px 20px; columns: 2; }
			.taas-manual .taas-toc li { break-inside: avoid; }
			.taas-manual .taas-tip { background: #f0f6fc; border-left: 4px solid #72aee6; padding: 10px 14px; }
			.taas-manual .taas-warn { background: #fcf9e8; border-left: 4px solid #dba617; padding: 10px 14px; }
			.taas-manual table.widefat { max-width: 860px; }
			.taas-manual code { font-size: 13px; }
			@media (max-width: 782px) { .taas-manual .taas-toc { columns: 1; } }
		</style>
		<div class="wrap taas-manual">
			<h1>TAAS Loan Program Manual</h1>
			<p>Everything you need to run the TAAS equipment loan program: how a loan works from start to finish, what each menu does, and what to do when something goes wrong.</p>

			<ul class="taas-toc">
				<li><a href="#how">How a loan works</a></li>
				<li><a href="#status">What each status means</a></li>
				<li><a href="#request">When a request comes in</a></li>
				<li><a href="#checkout">Checking an item out</a></li>
				<li><a href="#return">When an item comes back</a></li>
				<li><a href="#damaged">Damaged items</a></li>
				<li><a href="#extend">Changing dates and late items</a></li>
				<li><a href="#menu">The menu, item by item</a></li>
				<li><a href="#items">Adding and editing items</a></li>
				<li><a href="#website">What members see on the website</a></li>
				<li><a href="#emails">Emails</a></li>
				<li><a href="#settings">Settings</a></li>
				<li><a href="#trouble">Troubleshooting</a></li>
			</ul>

			<h2 id="how">How a loan works</h2>
			<ol>
				<li><strong>A member asks.</strong> A logged-in member opens an item on the website and clicks <em>Request this item</em>.</li>
				<li><strong>You get an email.</strong> The Loan Manager gets an email with the member's name, email and phone numbers. The member gets an email saying their request arrived.</li>
				<li><strong>You arrange pickup.</strong> Reply to the email or call the member to set a time.</li>
				<li><strong>You check it out.</strong> When the member picks it up, set it to <em>Checked Out</em> (see <a href="#checkout">Checking an item out</a>). The return date is set <?php echo esc_html( $days ); ?> days after pickup, and the member and the loan team each get an email with the return date.</li>
				<li><strong>The member brings it back.</strong> Set it to <em>Returned</em>, or <em>Damaged needs repair</em> if something is wrong. A returned item is available to borrow again right away.</li>
			</ol>

			<h2 id="status">What each status means</h2>
			<table class="widefat striped">
				<thead>
					<tr><th>Status (in the admin)</th><th>Website shows</th><th>Can members request it?</th><th>Meaning</th></tr>
				</thead>
				<tbody>
					<tr><td><strong>Available</strong> / <strong>Returned</strong></td><td>In</td><td>Yes</td><td>On the shelf and ready to lend.</td></tr>
					<tr><td><strong>Requested</strong></td><td>Requested</td><td>No</td><td>A member has asked for it. Check it out to them when they pick it up.</td></tr>
					<tr><td><strong>Checked Out</strong></td><td>Out</td><td>No</td><td>A member has it. It stays Checked Out until you mark it Returned, even after the return date.</td></tr>
					<tr><td><strong>Damaged needs repair</strong></td><td>Being repaired</td><td>No</td><td>Broken or missing parts. It can't be requested or checked out until you mark it Returned.</td></tr>
				</tbody>
			</table>
			<p>The status you pick is what the <strong>Availability</strong> column shows on <?php $a( $objects ); ?> and <?php $a( $lendings ); ?>, and what members see on the website.</p>

			<h2 id="request">When a request comes in</h2>
			<ol>
				<li>Open the request email. It has the member's contact details and a <em>Check it out to them</em> button.</li>
				<li>Contact the member to arrange pickup. Replying to the email goes straight to them.</li>
				<li>Requests are listed at the top of <?php $a( $lendings ); ?>, and the item shows <em>Requested</em> on <?php $a( $objects ); ?>.</li>
				<li>When they pick it up, check it out (next section). Their request becomes the loan, so it isn't listed twice.</li>
			</ol>
			<p class="taas-tip">If a request isn't going ahead, open it on <?php $a( $lendings ); ?> (hover over the item name and click <em>Edit</em>) and set it to <em>Returned</em>, or delete it, so the item is available again.</p>

			<h2 id="checkout">Checking an item out</h2>
			<p>There are two ways. Both record the loan on <?php $a( $lendings ); ?> and can email the member and the loan team.</p>
			<h3>From All objects (quickest)</h3>
			<ol>
				<li>Go to <?php $a( $objects ); ?> and find the item.</li>
				<li>In the <strong>Availability</strong> dropdown, pick <em>Checked Out</em>. A small box opens.</li>
				<li>Pick the <strong>Member</strong> (already picked if they requested it). Click the list and start typing a name to jump to it.</li>
				<li>Check the <strong>Pickup date</strong> and <strong>Return date</strong>. Click either date to open a calendar. The return date fills in <?php echo esc_html( $days ); ?> days after pickup.</li>
				<li>Add <strong>Notes</strong> if useful (for example "includes red dot finder"). Only the loan team sees them.</li>
				<li>Leave <strong>Email the member and the loan team</strong> ticked, and click <strong>Check out</strong>.</li>
			</ol>
			<h3>From the Check out page</h3>
			<p>Go to <?php $a( $checkout ); ?>, pick the item and the member, check the dates, and click <strong>Check out</strong>. Requested items are listed first and pick their member for you. Items already out or being repaired can't be picked.</p>
			<p class="taas-tip">Editing a loan on <?php $a( $lendings ); ?> does <strong>not</strong> send emails. Use one of the two ways above for a new checkout.</p>

			<h2 id="return">When an item comes back</h2>
			<p>Check the item over, then do one of these:</p>
			<ul>
				<li>On <?php $a( $objects ); ?>, set its <strong>Availability</strong> to <em>Returned</em>. It saves straight away.</li>
				<li>On <?php $a( $lendings ); ?>, tick the loan, pick <em>Mark as returned</em> from <strong>Bulk actions</strong>, and click <strong>Apply</strong>. This is handy for several items at once.</li>
			</ul>
			<p>No email is sent when an item is returned.</p>

			<h2 id="damaged">Damaged items</h2>
			<ol>
				<li>Set the item to <em>Damaged needs repair</em>, on <?php $a( $objects ); ?> or with the <strong>Bulk actions</strong> on <?php $a( $lendings ); ?>.</li>
				<li>The website shows <em>Being repaired</em>, and nobody can request or check it out.</li>
				<li>Use <strong>Admin notes</strong> on the loan (Lendings &gt; Edit) to record what's wrong.</li>
				<li>Once it's fixed, set it to <em>Returned</em> and it's available again.</li>
			</ol>

			<h2 id="extend">Changing dates and late items</h2>
			<ul>
				<li><strong>To extend a loan or fix a date:</strong> on <?php $a( $lendings ); ?>, hover over the item name, click <em>Edit</em>, change the dates, and click <strong>Save</strong>. Changing the pickup date moves the return date to <?php echo esc_html( $days ); ?> days later; you can then change it by hand.</li>
				<li><strong>Late items:</strong> an item stays <em>Checked Out</em> after its return date until you mark it Returned. To find late items, sort <?php $a( $lendings ); ?> by <strong>Return date</strong> and look for dates in the past.</li>
			</ul>

			<h2 id="menu">The menu, item by item</h2>
			<table class="widefat striped">
				<tbody>
					<tr><th scope="row"><?php $a( $objects ); ?></th><td>Every item in the program, with its <strong>Availability</strong> dropdown. Hover over an item to edit it.</td></tr>
					<tr><th scope="row"><?php $a( $lendings ); ?></th><td>Every request and loan: who has what, pickup and return dates, status and admin notes. Requests are at the top. Hover over an item name to <em>Edit</em> or <em>Delete</em> a loan. <strong>Bulk actions:</strong> Delete, Mark as returned, Mark as damaged needs repair.</td></tr>
					<tr><th scope="row"><?php $a( $checkout ); ?></th><td>Check an item out to a member and send the emails.</td></tr>
					<tr><th scope="row"><?php $a( $add ); ?></th><td>Add a new item to the program.</td></tr>
					<tr><th scope="row"><?php $a( $cats ); ?></th><td>The groups items are sorted into on the website (Telescopes, Eyepieces, and so on).</td></tr>
					<tr><th scope="row">Restrict Access</th><td>From the Restrict Content Pro plugin, not the TAAS Loan Program. Sets which membership levels and roles can see loan items. Ask the site admin before changing it.</td></tr>
					<tr><th scope="row">Re-Order</th><td>From the Post Types Order plugin. Drag items up and down to set a custom order, then click <strong>Update</strong>.</td></tr>
					<tr><th scope="row"><?php $a( $import ); ?></th><td>Copies equipment from the old Vik Rent Items system. Only needed once (see <a href="#items">Adding and editing items</a>).</td></tr>
					<tr><th scope="row">Manual</th><td>This page.</td></tr>
				</tbody>
			</table>

			<h2 id="items">Adding and editing items</h2>
			<ol>
				<li>Go to <?php $a( $add ); ?> (or hover over an item on <?php $a( $objects ); ?> and click <em>Edit</em>).</li>
				<li><strong>Title:</strong> the item's name, for example "Apertura AD10 Dobsonian".</li>
				<li><strong>Description:</strong> the full details members see on the item's page. The first 30 words are shown in lists.</li>
				<li><strong>Featured image:</strong> the item's picture (in the right-hand column).</li>
				<li><strong>Categories:</strong> tick the group it belongs in (right-hand column).</li>
				<li>Click <strong>Publish</strong> (or <strong>Update</strong>).</li>
			</ol>
			<p><strong>Several of the same item?</strong> Add one entry per unit, named "Name #1", "Name #2" and so on, so each can be lent separately.</p>
			<p><strong>Removing an item:</strong> on <?php $a( $objects ); ?>, hover over it and click <em>Trash</em>. It disappears from the website.</p>
			<p><strong>Categories:</strong> on <?php $a( $cats ); ?> you can add, rename or describe a category. The website shows a picture for each category: the one brought over by Import, or else the picture of its first item.</p>
			<p><strong>Import:</strong> <?php $a( $import ); ?> copies names, descriptions, categories and pictures from Vik Rent Items. Running it again is safe: it skips items it already brought over and fills in missing pictures.</p>

			<h2 id="website">What members see on the website</h2>
			<ul>
				<li>The equipment list is on the page that has the <code>[taas_loan_items]</code> shortcode. It shows category cards; clicking one lists its items with a picture, a short description and the In / Out / Requested / Being repaired label.</li>
				<li>Shortcode options: <code>view="list"</code> (one list, no cards), <code>view="grid"</code> (picture cards grouped by category), <code>category="telescopes,eyepieces"</code> (only those categories, in that order), <code>status="no"</code> (hide the labels).</li>
				<li>Each item page shows the picture, the description and a <strong>Request this item</strong> button for logged-in members when the item is available. Visitors who aren't logged in get <strong>Log in to request</strong> and <strong>Join TAAS</strong> buttons instead.</li>
				<li><strong>Reviews:</strong> only members who borrowed an item can leave a written review and star rating on its page. You can turn reviews off in <?php $a( $settings ); ?> (<em>Hide the comment form</em>).</li>
			</ul>

			<h2 id="emails">Emails</h2>
			<table class="widefat striped">
				<thead><tr><th>When</th><th>Who gets it</th><th>What it says</th></tr></thead>
				<tbody>
					<tr><td>A member requests an item</td><td>The Loan Manager email(s)</td><td>Who asked, for which item, and their email, mobile and home phone. Has an <em>Open the Loan Portal</em> button and a <em>Check it out to them</em> button. Replying goes to the member.</td></tr>
					<tr><td>A member requests an item</td><td>The member</td><td>Their request arrived and the Loan Director will be in touch to arrange pickup. (Can be turned off in settings.)</td></tr>
					<tr><td>An item is checked out (with the email box ticked)</td><td>The member and the loan team</td><td>The item, the pickup date and the return date.</td></tr>
				</tbody>
			</table>
			<p>No emails are sent for returns, damage, or edits on the Lendings page.</p>

			<h2 id="settings">Settings</h2>
			<p>Found at <?php $a( $settings ); ?>.</p>
			<ul>
				<li><strong>Make TAAS Loan Program public:</strong> lets visitors browse the equipment list without logging in. Item pages are always visible.</li>
				<li><strong>Hide the comment form:</strong> turns off reviews and ratings.</li>
				<li><strong>Join page</strong> and <strong>Login page:</strong> where the Join TAAS and Log in buttons go.</li>
				<li><strong>Loan Manager email:</strong> who gets request emails and checkout emails. Separate several addresses with commas.</li>
				<li><strong>Confirm requests to members:</strong> send members the "we got your request" email.</li>
				<li><strong>Send emails from:</strong> the address emails come from. Use an address at this site's domain so emails aren't marked as spam.</li>
				<li><strong>Phone fields:</strong> which Participants Database fields hold members' phone numbers. Members are matched by their WordPress account email.</li>
				<li><strong>Send test email</strong> and <strong>Private test link:</strong> check that emails arrive (see <a href="#trouble">Troubleshooting</a>).</li>
				<li><strong>Delete all data</strong> (bottom of the page): removes every item, category and loan to start over.</li>
			</ul>
			<p class="taas-warn"><strong>Delete all data cannot be undone.</strong> Back up the site first.</p>

			<h2 id="trouble">Troubleshooting</h2>
			<h3>Request emails aren't arriving</h3>
			<ol>
				<li>Check your spam folder.</li>
				<li>In <?php $a( $settings ); ?>, check the <strong>Loan Manager email</strong> and click <strong>Send test email</strong>.</li>
				<li>Still nothing? Open the <strong>Private test link</strong> and run the <strong>email check</strong>. It sends four versions, from very basic to the real email, and shows which ones got through. Pass that to the site admin.</li>
			</ol>
			<h3>The email has no phone numbers</h3>
			<p>The member's WordPress email doesn't match an entry in Participants Database. Update one so they match.</p>
			<h3>An item shows "Out" but it's on the shelf</h3>
			<p>It was never marked Returned. Set its Availability to <em>Returned</em> on <?php $a( $objects ); ?>.</p>
			<h3>"That item is checked out or needs repair"</h3>
			<p>You're trying to check out an item that's already out or being repaired. Mark it <em>Returned</em> first.</p>
			<h3>A member can't request an item</h3>
			<p>They must be logged in, and the item must show <em>In</em>. If someone else requested it or has it, the button is hidden.</p>
		</div>
		<?php
	}
}

TAAS_Loan_Manual::init();
