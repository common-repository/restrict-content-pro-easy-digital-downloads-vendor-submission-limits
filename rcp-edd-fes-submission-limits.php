<?php
/**
 * Plugin Name: Restrict Content Pro - Easy Digital Downloads Vendor Submission Limits
 * Description: Control the number of products a vendor can publish using Frontend Submissions for Easy Digital Downloads.
 * Version: 1.0.3
 * Author: iThemes, LLC
 * Author URI: https://ithemes.com
 * Contributors: jthillithemes, layotte, ithemes
 * Text Domain: rcp-edd-fes-submission-limits
 * iThemes Package: restrict-content-pro-easy-digital-downloads-vendor-submission-limits
 */

/**
 * Include required files.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-fes-allowance.php';

/**
 * Loads the plugin textdomain.
 *
 * @since 1.0
 * @return void
 */
function rcp_edd_fes_textdomain() {
	load_plugin_textdomain( 'rcp-edd-fes-submission-limits', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}
add_action( 'init', 'rcp_edd_fes_textdomain' );

/**
 * Get the product limit for a membership level.
 *
 * This also migrates the value from wp_options to membership level meta.
 *
 * @param int $level_id ID of the membership level.
 *
 * @since 1.0.1
 * @return int
 */
function rcp_edd_fes_get_membership_level_product_limit( $level_id ) {

	/**
	 * @var RCP_Levels $rcp_levels_db
	 */
	global $rcp_levels_db;

	if ( function_exists( 'rcp_get_membership_level_meta' ) ) {
		$limit = (int) rcp_get_membership_level_meta( $level_id, 'edd_fes_product_limit', true );
	} else {
		$limit = (int) $rcp_levels_db->get_meta( $level_id, 'edd_fes_product_limit', true );
	}

	if ( ! empty( $limit ) ) {
		return $limit;
	}

	// Check in old options item, as that's where it used to be stored.
	$limit = get_option( 'rcp_subscription_fes_products_allowed_' . $level_id, 0 );

	if ( empty( $limit ) ) {
		return (int) $limit;
	}

	// If the old option exists, we need to update the meta for next time and delete it.
	if ( function_exists( 'rcp_update_membership_level_meta' ) ) {
		$result = rcp_update_membership_level_meta( $level_id, 'edd_fes_product_limit', sanitize_text_field( $limit ) );
	} else {
		$result = $rcp_levels_db->update_meta( $level_id, 'edd_fes_product_limit', sanitize_text_field( $limit ) );
	}

	if ( $result ) {
		delete_option( 'rcp_subscription_fes_products_allowed_' . $level_id );
	}

	return $limit;

}

/**
 * Adds the plugin settings form fields to the membership level form.
 *
 * @param object $level Membership level object.
 *
 * @since 1.0
 * @return void
 */
function rcp_edd_fes_level_fields( $level ) {

	if ( ! class_exists( 'EDD_Front_End_Submissions' ) ) {
		return;
	}

	$allowed = ( ! empty( $level ) ? rcp_edd_fes_get_membership_level_product_limit( $level->id ) : 0 );
?>

	<tr class="form-field">
		<th scope="row" valign="top">
			<label for="rcp-edd-fes-products"><?php printf( __( '%s Product Limit', 'rcp-edd-fes-submission-limits' ), ucfirst( EDD_FES()->helper->get_option( 'fes-vendor-constant', 'vendor' ) ) ); ?></label>
		</th>
		<td>
			<input type="number" min="0" step="1" id="rcp-edd-fes-products" name="rcp-edd-fes-products" value="<?php echo esc_attr( $allowed ); ?>" style="width: 100px;"/>
			<p class="description"><?php printf( __( 'The number of %s a %s is allowed to submit per subscription period.', 'rcp-edd-fes-submission-limits' ), strtolower( edd_get_label_plural() ), strtolower( EDD_FES()->helper->get_option( 'fes-vendor-constant', 'vendor' ) ) ); ?></p>
		</td>
	</tr>

<?php
}
add_action( 'rcp_add_subscription_form', 'rcp_edd_fes_level_fields' );
add_action( 'rcp_edit_subscription_form', 'rcp_edd_fes_level_fields' );



/**
 * Saves the membership level limit settings.
 *
 * @param int   $level_id Membership level ID.
 * @param array $args     Level arguments.
 *
 * @since 1.0
 * @return void
 */
function rcp_edd_fes_save_level_limits( $level_id = 0, $args = array() ) {

	if ( ! class_exists( 'EDD_Front_End_Submissions' ) ) {
		return;
	}

	/**
	 * @var RCP_Levels $rcp_levels_db
	 */
	global $rcp_levels_db;

	$products_allowed = absint( $_POST['rcp-edd-fes-products'] );

	if ( ! empty( $products_allowed ) ) {
		if ( function_exists( 'rcp_update_membership_level_meta' ) ) {
			rcp_update_membership_level_meta( $level_id, 'edd_fes_product_limit', sanitize_text_field( $products_allowed ) );
		} else {
			$rcp_levels_db->update_meta( $level_id, 'edd_fes_product_limit', sanitize_text_field( $products_allowed ) );
		}
	} else {
		if ( function_exists( 'rcp_delete_membership_level_meta' ) ) {
			rcp_delete_membership_level_meta( $level_id, 'edd_fes_product_limit' );
		} else {
			$rcp_levels_db->delete_meta( $level_id, 'edd_fes_product_limit' );
		}
	}

}
add_action( 'rcp_add_subscription', 'rcp_edd_fes_save_level_limits', 10, 2 );
add_action( 'rcp_edit_subscription_level', 'rcp_edd_fes_save_level_limits', 10, 2 );



/**
 * Displays a notice to the vendor on the dashboard.
 *
 * @param string $content Unfiltered content.
 *
 * @since 1.0
 * @return string
 */
function rcp_edd_fes_vendor_announcement( $content ) {

	if ( ! function_exists( 'rcp_get_subscription_id' ) ) {
		return $content;
	}

	if ( rcp_edd_fes_member_at_limit() ) {
		return wpautop( rcp_edd_fes_vendor_at_limit_message() ) . $content;
	}
	return $content;
}
add_filter( 'fes_dashboard_content', 'rcp_edd_fes_vendor_announcement' );


/**
 * Displays a notice to the vendor on the submission form screen.
 *
 * @param FES_Form $obj
 * @param int      $user_id
 * @param bool     $readonly
 *
 * @since 1.0
 * @return void
 */
function rcp_edd_fes_before_submission_form_fields( $obj, $user_id, $readonly ) {

	if ( ! function_exists( 'rcp_get_subscription_id' ) ) {
		return;
	}

	if ( rcp_edd_fes_member_at_limit() ) {
		echo rcp_edd_fes_vendor_at_limit_message();
	}
}
add_action( 'fes_render_submission_form_frontend_before_fields', 'rcp_edd_fes_before_submission_form_fields', 10, 3 );


/**
 * Constructs the vendor limit message.
 *
 * @since 1.0
 * @return string
 */
function rcp_edd_fes_vendor_at_limit_message() {
	global $rcp_options;
	return apply_filters(
		'rcp_edd_fes_vendor_at_limit_message',
		sprintf( __( 'You have published the maximum number of %s allowed by your membership. <a href="%s">Upgrade your membership</a> to publish more.', 'rcp-edd-fes-submission-limits' ), strtolower( edd_get_label_plural() ), esc_url( get_permalink( $rcp_options['registration_page'] ) ) )
	);
}


/**
 * Overrides the submission form fields when a vendor is at the submission limit.
 *
 * @param array    $fields
 * @param FES_Form @obj
 * @param int      $user_id
 * @param bool     $readonly
 *
 * @since 1.0
 * @return array
 */
function rcp_edd_fes_submission_form_override( $fields, $obj, $user_id, $readonly ) {
	if ( rcp_edd_fes_member_at_limit() && isset( $_GET['task'] ) && 'edit-product' !== $_GET['task'] ) {
		return array(); // @todo this could suck less.
	}

	return $fields;
}
add_filter( 'fes_render_submission_form_frontend_fields', 'rcp_edd_fes_submission_form_override', 10, 4 );


/**
 * Removes the New Product menu item from the vendor dashboard when the vendor is at the submission limit.
 *
 * @param array $menu_items
 *
 * @since 1.0
 * @return array
 */
function rcp_edd_fes_vendor_menu_items( $menu_items ) {

	if ( rcp_edd_fes_member_at_limit() && array_key_exists( 'new_product', $menu_items ) ) {
		unset( $menu_items['new_product'] );
	}

	return $menu_items;
}
add_filter( 'fes_vendor_dashboard_menu', 'rcp_edd_fes_vendor_menu_items' );


/**
 * Updates the vendor's total submission count.
 *
 * @param FES_Form $obj
 * @param int      $user_id
 * @param int      $save_id
 *
 * @since 1.0
 * @return void
 */
function rcp_edd_fes_save_submission_count( $obj, $user_id, $save_id ) {

	if ( ! EDD()->session->get( 'fes_is_new' ) ) {
		return;
	}

	$allowance = new RCP_EDD_FES_Allowance( $user_id );
	$allowance->increment_current();

}
add_action( 'fes_save_submission_form_values_after_save', 'rcp_edd_fes_save_submission_count', 10, 3 );


/**
 * Determines if the member is at the product submission limit.
 *
 * @param int $user_id
 *
 * @since 1.0
 * @return bool
 */
function rcp_edd_fes_member_at_limit( $user_id = 0 ) {

	if ( ! function_exists( 'rcp_get_subscription_id' ) ) {
		return false;
	}

	if ( empty( $user_id ) ) {
		$user_id = get_current_user_id();
	}

	$allowance = new RCP_EDD_FES_Allowance( $user_id );

	return $allowance->get_number_remaining() <= 0;
}


/**
 * Resets a vendor's product submission count when making a new payment.
 *
 * @deprecated 1.0.1 In favour of `rcp_edd_fes_reset_submission_limit()`
 * @see rcp_edd_fes_reset_submission_limit()
 *
 * @param int       $payment_id
 * @param array     $args
 * @param int|float $amount
 *
 * @since 1.0
 * @return void
 */
function rcp_edd_fes_reset_limit( $payment_id, $args = array(), $amount ) {

	if ( defined( 'RCP_PLUGIN_VERSION' ) && version_compare( RCP_PLUGIN_VERSION, '3.0', '>=' ) ) {
		return;
	}

	if ( ! class_exists( 'EDD_Front_End_Submissions' ) ) {
		return;
	}

	if ( ! empty( $args['user_id'] ) ) {
		delete_user_meta( $args['user_id'], 'rcp_edd_fes_vendor_submission_count' );
	}
}
add_action( 'rcp_insert_payment', 'rcp_edd_fes_reset_limit', 10, 3 );

/**
 * Resets the number of products a user has submitted when their membership is renewed.
 *
 * @param string         $expiration    New expiration date to be set.
 * @param int            $membership_id ID of the membership.
 * @param RCP_Membership $membership    Membership object.
 *
 * @since 1.0.1
 * @return void
 */
function rcp_edd_fes_reset_submission_limit( $expiration, $membership_id, $membership ) {

	$user_id = $membership->get_customer()->get_user_id();

	if ( empty( $user_id ) ) {
		return;
	}

	// Delete the count associated with this membership.
	rcp_delete_membership_meta( $membership_id, 'edd_fes_products_submitted' );

}
add_action( 'rcp_membership_post_renew', 'rcp_edd_fes_reset_submission_limit', 10, 3 );

/**
 * Add a shortcode to output the number of product submissions remaining in this membership period.
 *
 * @param array  $atts    Shortcode attributes.
 * @param string $content Shortcode content.
 *
 * @since 1.0.1
 * @return int
 */
function rcp_edd_fes_product_submissions_remaining_shortcode( $atts, $content = '' ) {

	$allowance = new RCP_EDD_FES_Allowance( get_current_user_id() );

	return $allowance->get_number_remaining();

}
add_shortcode( 'rcp_edd_fes_product_submissions_remaining', 'rcp_edd_fes_product_submissions_remaining_shortcode' );

/**
 * Add a shortcode to output the number of product submissions allowed per membership period.
 *
 * @param array  $atts    Shortcode attributes.
 * @param string $content Shortcode content.
 *
 * @since 1.0.1
 * @return int
 */
function rcp_edd_fes_product_submissions_limit_shortcode( $atts, $content = '' ) {

	$allowance = new RCP_EDD_FES_Allowance( get_current_user_id() );

	return $allowance->get_max();

}
add_shortcode( 'rcp_edd_fes_product_submissions_limit', 'rcp_edd_fes_product_submissions_limit_shortcode' );

if ( ! function_exists( 'ithemes_rcp_edd_fes_submission_limits_updater_register' ) ) {
	function ithemes_rcp_edd_fes_submission_limits_updater_register( $updater ) {
		$updater->register( 'REPO', __FILE__ );
	}
	add_action( 'ithemes_updater_register', 'ithemes_rcp_edd_fes_submission_limits_updater_register' );

	require( __DIR__ . '/lib/updater/load.php' );
}