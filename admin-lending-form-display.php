<?php
/**
 * Lendings > Edit: the Loan Director sets the pickup date, the return date
 * and the status (Checked Out, Returned, Damaged needs repair).
 *
 * @package TAAS_Loan_Program
 */

if ( ! defined( 'ABSPATH' ) || ! is_user_logged_in() ) exit; // Exit if accessed directly

global $wpdb;

$message = '';
$notice  = '';
$today   = current_datetime();
$days    = TAAS_Loan_Checkout::DEFAULT_DAYS;

// Blank lending, used for new records.
$default = array(
    'comment_ID'      => 0,
    'comment_post_ID' => 0,
    'user_id'         => 0,
    'lent'            => '',
    'due'             => '',
    'comment_agent'   => '',
    'state'           => 'out',
);

/**
 * Read a YYYY-MM-DD date, or null if it isn't one.
 */
function scwp_parse_form_date( $value ) {
    $date = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $value, wp_timezone() );
    return $date && $date->format( 'Y-m-d' ) === $value ? $date : null;
}

if ( isset( $_POST['nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['nonce'] ), basename( __FILE__ ) ) ) {
    $item = array(
        'comment_ID'      => isset( $_POST['comment_ID'] ) ? absint( $_POST['comment_ID'] ) : 0,
        'comment_post_ID' => isset( $_POST['comment_post_ID'] ) ? absint( $_POST['comment_post_ID'] ) : 0,
        'user_id'         => isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0,
        'lent'            => isset( $_POST['lent'] ) ? sanitize_text_field( wp_unslash( $_POST['lent'] ) ) : '',
        'due'             => isset( $_POST['due'] ) ? sanitize_text_field( wp_unslash( $_POST['due'] ) ) : '',
        'comment_agent'   => isset( $_POST['comment_agent'] ) ? sanitize_text_field( wp_unslash( $_POST['comment_agent'] ) ) : '',
        'state'           => isset( $_POST['state'] ) ? sanitize_key( $_POST['state'] ) : '',
    );
    $lent = scwp_parse_form_date( $item['lent'] );
    $due  = scwp_parse_form_date( $item['due'] );

    if ( ! $lent || ! $due ) {
        $notice = __( 'Enter both a pickup date and a return date.', 'sharing-club' );
    } elseif ( $due < $lent ) {
        $notice = __( 'The return date can\'t be before the pickup date.', 'sharing-club' );
    } elseif ( ! isset( TAAS_Loan_Shortcodes::states()[ $item['state'] ] ) ) {
        $notice = __( 'Pick a status.', 'sharing-club' );
    } else {
        $fields = array(
            'comment_post_ID'  => $item['comment_post_ID'],
            'user_id'          => $item['user_id'],
            'comment_date'     => $lent->format( 'Y-m-d 00:00:00' ),
            // End of the day, so the item shows as out through the return date.
            'comment_date_gmt' => $due->format( 'Y-m-d 23:59:59' ),
            'comment_agent'    => $item['comment_agent'],
            'comment_type'     => 'lending',
        );
        if ( 0 === $item['comment_ID'] ) {
            $result             = $wpdb->insert( $wpdb->comments, $fields );
            $item['comment_ID'] = (int) $wpdb->insert_id;
        } else {
            $result = $wpdb->update( $wpdb->comments, $fields, array( 'comment_ID' => $item['comment_ID'] ) );
        }
        if ( false !== $result ) {
            TAAS_Loan_Shortcodes::set_state( $item['comment_ID'], $item['state'] );
            $message = __( 'Lending saved.', 'sharing-club' );
        } else {
            $notice = __( 'There was an error while saving. Please try again.', 'sharing-club' );
        }
    }
} else {
    // Not a save: load the lending to edit, or start a new one.
    $item = $default;
    if ( isset( $_REQUEST['ID'] ) ) {
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT comment_ID, user_id, comment_post_ID, comment_date, comment_date_gmt, comment_agent FROM {$wpdb->comments} WHERE comment_ID = %d",
                absint( $_REQUEST['ID'] )
            ),
            ARRAY_A
        );
        if ( $row ) {
            $state = TAAS_Loan_Shortcodes::lending_state( $row['comment_date'], $row['comment_date_gmt'], (string) get_comment_meta( $row['comment_ID'], TAAS_Loan_Shortcodes::STATE_META, true ) );
            $item  = array(
                'comment_ID'      => (int) $row['comment_ID'],
                'comment_post_ID' => (int) $row['comment_post_ID'],
                'user_id'         => (int) $row['user_id'],
                'lent'            => 'requested' === $state ? '' : substr( $row['comment_date'], 0, 10 ),
                'due'             => 'requested' === $state || 0 === strpos( $row['comment_date_gmt'], '0000' ) ? '' : substr( $row['comment_date_gmt'], 0, 10 ),
                'comment_agent'   => $row['comment_agent'],
                'state'           => 'requested' === $state ? 'out' : $state,
            );
        } else {
            $notice = __( 'Item not found', 'sharing-club' );
        }
    }
}

// A new loan or a request: start with pickup today, return in 30 days.
if ( '' === $item['lent'] ) {
    $item['lent'] = $today->format( 'Y-m-d' );
}
if ( '' === $item['due'] ) {
    $start       = scwp_parse_form_date( $item['lent'] );
    $item['due'] = ( $start ? $start : $today )->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
}

add_meta_box( 'form_meta_box', __( 'Lending data', 'sharing-club' ), 'scwp_meta_box_handler', 'product', 'normal', 'default' );

?>
<div class="wrap">
    <h2><?php esc_html_e( 'Lendings', 'sharing-club' ); ?> <a class="add-new-h2" href="<?php echo esc_url( get_admin_url( get_current_blog_id(), 'edit.php?post_type=shared_item&page=display_lending_table' ) ); ?>"><?php esc_html_e( 'Back to list', 'sharing-club' ); ?></a></h2>

    <?php if ( ! empty( $notice ) ) : ?>
    <div id="notice" class="error"><p><?php echo esc_html( $notice ); ?></p></div>
    <?php endif; ?>
    <?php if ( ! empty( $message ) ) : ?>
    <div id="message" class="updated"><p><?php echo esc_html( $message ); ?></p></div>
    <?php endif; ?>

    <form id="form" method="POST">
        <input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( basename( __FILE__ ) ) ); ?>"/>
        <input type="hidden" name="comment_ID" value="<?php echo esc_attr( $item['comment_ID'] ); ?>"/>

        <div class="metabox-holder" id="post-shared_item">
            <div id="post-body">
                <div id="post-body-content">
                    <?php do_meta_boxes( 'product', 'normal', $item ); ?>
                    <input type="submit" value="<?php esc_attr_e( 'Save', 'sharing-club' ); ?>" id="submit" class="button-primary" name="submit">
                </div>
            </div>
        </div>
    </form>
</div>
<?php
TAAS_Loan_Checkout::due_date_script( 'taas-lending-lent', 'taas-lending-due' );

function scwp_meta_box_handler( $item ) {
    ?>
<table class="form-table" role="presentation">
    <tbody>
    <tr class="form-field">
        <th scope="row"><label for="user_id"><?php esc_html_e( 'User', 'sharing-club' ); ?></label></th>
        <td><?php scwp_generate_select( 'user_id', 'users', intval( $item['user_id'] ), 'user_nicename' ); ?></td>
    </tr>
    <tr class="form-field">
        <th scope="row"><label for="comment_post_ID"><?php esc_html_e( 'Object', 'sharing-club' ); ?></label></th>
        <td><?php scwp_generate_select( 'comment_post_ID', 'posts', intval( $item['comment_post_ID'] ), 'post_title', 'post_type = \'shared_item\'' ); ?></td>
    </tr>
    <tr>
        <th scope="row"><label for="taas-lending-lent"><?php esc_html_e( 'Pickup date', 'sharing-club' ); ?></label></th>
        <td><input type="date" id="taas-lending-lent" name="lent" value="<?php echo esc_attr( $item['lent'] ); ?>" required /></td>
    </tr>
    <tr>
        <th scope="row"><label for="taas-lending-due"><?php esc_html_e( 'Return date', 'sharing-club' ); ?></label></th>
        <td>
            <input type="date" id="taas-lending-due" name="due" value="<?php echo esc_attr( $item['due'] ); ?>" required />
            <p class="description">
                <?php
                /* translators: %d: number of days. */
                echo esc_html( sprintf( __( 'Set to %d days after the pickup date. Change it if needed.', 'sharing-club' ), TAAS_Loan_Checkout::DEFAULT_DAYS ) );
                ?>
            </p>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="taas-lending-state"><?php esc_html_e( 'Status', 'sharing-club' ); ?></label></th>
        <td>
            <select id="taas-lending-state" name="state">
                <?php foreach ( TAAS_Loan_Shortcodes::states() as $key => $label ) : ?>
                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $item['state'], $key ); ?>><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="description"><?php esc_html_e( 'Shown as Availability on the Lendings page. "Damaged needs repair" shows the item as being repaired on the website.', 'sharing-club' ); ?></p>
        </td>
    </tr>
    <tr class="form-field">
        <th scope="row"><label for="comment_agent"><?php esc_html_e( 'Admin notes', 'sharing-club' ); ?></label></th>
        <td><input id="comment_agent" name="comment_agent" type="text" style="width: 95%" value="<?php echo esc_attr( $item['comment_agent'] ); ?>"/></td>
    </tr>
    </tbody>
</table>
    <?php
}
