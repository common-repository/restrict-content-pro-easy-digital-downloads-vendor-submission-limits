<?php
/**
 * EDD FES Allowance
 *
 * @package   rcp-edd-fes-submission-limits
 * @copyright Copyright (c) 2019, Restrict Content Pro team
 * @license   GPL2+
 * @since     1.0.1
 */

class RCP_EDD_FES_Allowance {

	/**
	 * ID of the user being checked.
	 *
	 * @var int
	 */
	protected $user_id = 0;

	/**
	 * Current number of products submitted in this period.
	 *
	 * @var int
	 */
	protected $current = 0;

	/**
	 * Maximum number of products allowed to be submitted in this period.
	 *
	 * @var int
	 */
	protected $max = 0;

	/**
	 * ID of the membership being used for the maximum. This will be the membership
	 * with the highest allowance.
	 *
	 * @var int
	 */
	protected $membership_id = 0;

	/**
	 * Membership level ID being used for the maximum. This is the membership level ID
	 * for the associated membership.
	 *
	 * @var int
	 */
	protected $level_id = 0;

	/**
	 * Whether or not the user has an active membership with a product submission allowance.
	 *
	 * @var bool
	 */
	protected $has_fes_membership = false;

	/**
	 * RCP_EDD_FES_Allowance constructor.
	 *
	 * @param int $user_id
	 *
	 * @since 1.0.1
	 */
	public function __construct( $user_id = 0 ) {

		if ( empty( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		$this->user_id = $user_id;

		$this->init( $this->user_id );

	}

	/**
	 * Set the user's maximum product submission allowance and current number of submissions made in this period.
	 *
	 * @param int $user_id ID of the user to initialize with.
	 *
	 * @since 1.0.1
	 * @return void
	 */
	protected function init( $user_id ) {

		if ( function_exists( 'rcp_get_customer_by_user_id' ) ) {
			/**
			 * RCP 3.0+
			 */
			$customer = rcp_get_customer_by_user_id( $user_id );

			if ( ! empty( $customer ) && ! $customer->is_pending_verification() ) {
				$memberships = $customer->get_memberships( array(
					'status__in' => array( 'active', 'cancelled' )
				) );

				if ( ! empty( $memberships ) ) {
					// Use the highest maximum value across all the user's memberships.
					foreach ( $memberships as $membership ) {
						/**
						 * @var RCP_Membership $membership
						 */

						if ( ! $membership->is_active() ) {
							continue;
						}

						$this_level_id = $membership->get_object_id();
						$this_max      = (int) rcp_edd_fes_get_membership_level_product_limit( $this_level_id );

						if ( $this_max > $this->max ) {
							$this->max           = $this_max;
							$this->current       = (int) rcp_get_membership_meta( $membership->get_id(), 'edd_fes_products_submitted', true );
							$this->membership_id = $membership->get_id();
							$this->level_id      = $this_level_id;
						}
					}
				}
			}
		} else {

			/**
			 * RCP 2.9 and lower
			 */

			/**
			 * @var RCP_Levels $rcp_levels_db
			 */
			global $rcp_levels_db;

			$member         = new RCP_Member( $user_id );
			$this->level_id = rcp_get_subscription_id( $user_id );

			if ( ! empty( $this->level_id ) && ! $member->is_expired() && 'pending' !== $member->get_status() && ! $member->is_pending_verification() ) {
				$this->max = (int) $rcp_levels_db->get_meta( $this->level_id, 'edd_fes_products_submitted', true );
			}

		}

		if ( ! empty( $this->max ) ) {
			$this->has_fes_membership = true;
		}

		// If there's still no $current, then let's check user meta. This is where it used to be stored.
		if ( empty( $this->current ) ) {
			$this->current = (int) get_user_meta( $user_id, 'rcp_edd_fes_vendor_submission_count', true );

			if ( ! empty( $this->current ) && ! empty( $this->membership_id ) && function_exists( 'rcp_update_membership_meta' ) ) {
				// Delete the user meta and move it to membership meta.
				delete_user_meta( $user_id, 'rcp_edd_fes_vendor_submission_count' );
				rcp_update_membership_meta( $this->membership_id, 'edd_fes_products_submitted', absint( $this->current ) );
			}
		}

	}

	/**
	 * Get the number of products submitted this period.
	 *
	 * @since 1.0.1
	 * @return int
	 */
	public function get_current() {
		return absint( $this->current );
	}

	/**
	 * Get the maximum number of product submissions allowed in this period.
	 *
	 * @since 1.0.1
	 * @return int
	 */
	public function get_max() {
		return absint( $this->max );
	}

	/**
	 * Get the number of product submissions remaining in this period.
	 *
	 * @since 1.0.1
	 * @return int
	 */
	public function get_number_remaining() {

		if ( empty( $this->max ) ) {
			return 0;
		}

		$remaining = $this->max - $this->current;

		if ( $remaining < 0 ) {
			$remaining = 0;
		}

		return $remaining;

	}

	/**
	 * Increment the current product submission count
	 *
	 * @since 1.0.1
	 * @return void
	 */
	public function increment_current() {

		$this->current ++;

		if ( ! empty( $this->membership_id ) && function_exists( 'rcp_update_membership_meta' ) ) {
			/**
			 * RCP 3.0+
			 */
			rcp_update_membership_meta( $this->membership_id, 'edd_fes_products_submitted', absint( $this->current ) );
		} else {
			/**
			 * RCP 2.9 and lower
			 */
			update_user_meta( $this->user_id, 'rcp_edd_fes_vendor_submission_count', absint( $this->current ) );
		}

	}

	/**
	 * Decrement the product submission count
	 *
	 * @since 1.0.1
	 * @return void
	 */
	public function decrement_current() {

		$this->current --;

		if ( $this->current < 0 ) {
			$this->current = 0;
		}

		if ( ! empty( $this->membership_id ) && function_exists( 'rcp_update_membership_meta' ) ) {
			/**
			 * RCP 3.0+
			 */

			if ( $this->current <= 0 ) {
				rcp_delete_membership_meta( $this->membership_id, 'edd_fes_products_submitted' );
			} else {
				rcp_update_membership_meta( $this->membership_id, 'edd_fes_products_submitted', absint( $this->current ) );
			}
		} else {
			/**
			 * RCP 2.9 and lower
			 */
			if ( $this->current <= 0 ) {
				delete_user_meta( $this->user_id, 'rcp_edd_fes_vendor_submission_count' );
			} else {
				update_user_meta( $this->user_id, 'rcp_edd_fes_vendor_submission_count', absint( $this->current ) );
			}
		}

	}

	/**
	 * Whether or not the user has an active membership with a product submission allowance.
	 *
	 * @since 1.0.1
	 * @return bool
	 */
	public function has_fes_membership() {
		return $this->has_fes_membership;
	}

	/**
	 * Get the ID of the membership level being used for the allowance.
	 *
	 * @since 1.0.1
	 * @return int
	 */
	public function get_level_id() {
		return absint( $this->level_id );
	}

	/**
	 * Get the ID of the membership used for calculating the allowance.
	 *
	 * @since 1.0.1
	 * @return int
	 */
	public function get_membership_id() {
		return absint( $this->membership_id );
	}

}