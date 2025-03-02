<?php

/*
Provides an easy to use interface for communicating with the iThemes updater server.
Written by Chris Jean for iThemes.com
Version 1.1.0

Version History
	1.0.0 - 2013-04-11 - Chris Jean
		Release ready
	1.0.1 - 2013-06-21 - Chris Jean
		Updated the http_build_query call to force a separator of & in order to avoid issues with servers that change the arg_separator.output php.ini value.
	1.0.2 - 2013-09-19 - Chris Jean
		Updated ithemes-updater-object to ithemes-updater-settings.
	1.0.3 - 2013-12-18 - Chris Jean
		Updated the way that the site URL is generated to ensure consistency across multisite sites.
	1.0.4 - 2014-01-31 - Chris Jean
		Updated to normalize the site URL used for password hash generation and for sending to the server.
	1.1.0 - 2014-10-23 - Chris Jean
		Updated auth token generation to use new password hashing.
		Added CA patch code.
		Updated code to meet WordPress coding standards.
*/


class Ithemes_Updater_Server {
	private static $secure_server_url = 'https://api.ithemes.com/updater';
	private static $insecure_server_url = 'http://api.ithemes.com/updater';

	private static $password_iterations = 8;


	public static function activate_package( $username, $password, $packages ) {
		$query = array(
			'user' => $username
		);

		$data = array(
			'auth_token' => self::get_password_hash( $username, $password ),
			'packages'   => $packages,
		);

		return Ithemes_Updater_Server::request( 'package-activate', $query, $data );
	}

	public static function deactivate_package( $username, $password, $packages ) {
		$query = array(
			'user' => $username
		);

		$data = array(
			'auth_token' => self::get_password_hash( $username, $password ),
			'packages'   => $packages,
		);

		return Ithemes_Updater_Server::request( 'package-deactivate', $query, $data );
	}

	public static function get_licensed_site_url( $packages ) {
		$query = array();

		$data = array(
			'packages' => $packages
		);

		return Ithemes_Updater_Server::request( 'licensed-site-url-get', $query, $data );
	}

	public static function set_licensed_site_url( $username, $password, $site_url, $keys ) {
		$query = array(
			'user' => $username
		);

		$data = array(
			'auth_token' => self::get_password_hash( $username, $password ),
			'site_url'   => $site_url,
			'keys'       => $keys,
		);

		return Ithemes_Updater_Server::request( 'licensed-site-url-set', $query, $data );
	}

	public static function get_package_details( $packages ) {
		global $rcp_options;

		$query = array();

		$data = array(
			'packages' => $packages
		);

		if ( ! empty( $rcp_options ) && is_array( $rcp_options ) && ! empty( $rcp_options['license_key'] ) ) {
			$data['rcp_license_key'] = $rcp_options['license_key'];
		}

		if ( isset( $packages['wpcomplete'] ) ) {
			$data['wpcomplete_license_key'] = get_option( 'wpcomplete_license_key' );
		}

		if ( isset( $packages['pmpro-ultimate-member'] ) ) {
			$data['skillful_license_keys']['pmpro-ultimate-member'] = trim( get_option( 'pmpro_um_license_key' ) );
		}
		if ( isset( $packages['rcp-custom-renew'] ) ) {
			$data['skillful_license_keys']['rcp-custom-renew'] = trim( get_option( 'rcpcr_license_key' ) );
		}
		if ( isset( $packages['rcp-resume-manager'] ) ) {
			$settings = get_option( 'rcprm', array() );

			if ( isset( $settings['license'] ) ) {
				$data['skillful_license_keys']['rcp-resume-manager'] = trim( $settings['license'] );
			}
		}
		if ( isset( $packages['rcp-ultimate-member'] ) ) {
			$data['skillful_license_keys']['rcp-ultimate-member'] = trim( get_option( 'rcpum_license_key' ) );
		}
		if ( isset( $packages['rcp-view-limit'] ) ) {
			$settings = get_option( 'rcpvl', array() );

			if ( isset( $settings['license'] ) ) {
				$data['skillful_license_keys']['rcp-view-limit'] = trim( $settings['license'] );
			}
		}
		if ( isset( $packages['restrict-content-pro-buddypress'] ) ) {
			$data['skillful_license_keys']['restrict-content-pro-buddypress'] = trim( get_option( 'rcpbp_license_key' ) );
		}
		if ( isset( $packages['view-limit'] ) ) {
			$settings = get_option( 'view_limit', array() );

			if ( isset( $settings['license'] ) ) {
				$data['skillful_license_keys']['view-limit'] = trim( $settings['license'] );
			}
		}

		return Ithemes_Updater_Server::request( 'package-details', $query, $data );
	}

	public static function request( $action, $query = array(), $data = array() ) {
		require_once( $GLOBALS['ithemes_updater_path'] . '/settings.php' );

		if ( false !== ( $timeout_start = get_site_option( 'ithemes-updater-server-timed-out' ) ) ) {
			// Hold off updates for 30 minutes.
			$time_remaining = 1800 - ( time() - $timeout_start );
			$minutes_remaining = ceil( $time_remaining / 60 );

			if ( $time_remaining < 0 ) {
				delete_site_option( 'ithemes-updater-server-timed-out' );
			} else {
				return new WP_Error( 'ithemes-updater-timed-out-server', sprintf( _n( 'The server could not be contacted. Requests are delayed for %d minute to allow the server to recover.', 'The server could not be contacted. Requests are delayed for %d minutes to allow the server to recover.', $minutes_remaining, 'it-l10n-restrict-content-pro-easy-digital-downloads-vendor-submission-limits' ), $minutes_remaining ) );
			}
		}


		if ( isset( $data['auth_token'] ) ) {
			$data['iterations'] = self::$password_iterations;
		}


		$site_url = self::get_site_url();


		$default_query = array(
			'wp'           => $GLOBALS['wp_version'],
			'site'         => $site_url,
			'timestamp'    => time(),
			'auth_version' => '2',
		);

		if ( is_multisite() ) {
			$default_query['ms'] = 1;
		}

		$query = array_merge( $default_query, $query );
		$request = "/$action/?" . http_build_query( $query, '', '&' );

		$post_data = array(
			'request' => json_encode( $data ),
		);

		$remote_post_args = array(
			'timeout' => 10,
			'body'    => $post_data,
		);


		$options = array(
			'use_ca_patch' => false,
			'use_ssl'      => true,
		);

		$patch_enabled = $GLOBALS['ithemes-updater-settings']->get_option( 'use_ca_patch' );

		if ( $patch_enabled ) {
			self::disable_ssl_ca_patch();
		}


		$response = wp_remote_post( self::$secure_server_url . $request, $remote_post_args );

		if ( is_wp_error( $response ) && ( 'connect() timed out!' != $response->get_error_message() ) ) {
			self::enable_ssl_ca_patch();
			$response = wp_remote_post( self::$secure_server_url . $request, $remote_post_args );

			if ( ! is_wp_error( $response ) ) {
				$options['use_ca_patch'] = true;
			}
		}

		if ( is_wp_error( $response ) && ( 'connect() timed out!' != $response->get_error_message() ) && defined( 'ITHEMES_ALLOW_HTTP_FALLBACK' ) && ITHEMES_ALLOW_HTTP_FALLBACK ) {
			$response = wp_remote_post( self::$insecure_server_url . $request, $remote_post_args );

			$options['use_ssl'] = false;
		}

		if ( ! $options['use_ca_patch'] ) {
			self::disable_ssl_ca_patch();
		}

		$GLOBALS['ithemes-updater-settings']->update_options( $options );

		if ( is_wp_error( $response ) ) {
			if ( 'connect() timed out!' == $response->get_error_message() ) {
				// Set option to delay server checks for a period of time.
				update_site_option( 'ithemes-updater-server-timed-out', time() );

				return new WP_Error( 'http_request_failed', __( 'The server was unable to be contacted.', 'it-l10n-restrict-content-pro-easy-digital-downloads-vendor-submission-limits' ) );
			}

			return $response;
		}


		$body = json_decode( $response['body'], true );

		if ( ! empty( $body['error'] ) ) {
			return new WP_Error( $body['error']['type'], sprintf( __( 'An error occurred when communicating with the iThemes update server: %s (%s)', 'it-l10n-restrict-content-pro-easy-digital-downloads-vendor-submission-limits' ), $body['error']['message'], $body['error']['code'] ) );
		}


		return $body;
	}

	private static function get_site_url() {
		require_once( $GLOBALS['ithemes_updater_path'] . '/settings.php' );

		// Ensure that a fatal error isn't triggered on upgrade.
		if ( is_callable( array( $GLOBALS['ithemes-updater-settings'], 'get_licensed_site_url' ) ) ) {
			$site_url = $GLOBALS['ithemes-updater-settings']->get_licensed_site_url();
		}

		if ( empty( $site_url ) ) {
			if ( is_callable( 'network_home_url' ) ) {
				$site_url = network_home_url( '', 'http' );
			} else {
				$site_url = get_bloginfo( 'url' );
			}
		}

		$site_url = preg_replace( '/^https/', 'http', $site_url );
		$site_url = preg_replace( '|/$|', '', $site_url );

		return $site_url;
	}

	private static function get_password_hash( $username, $password ) {
		require_once( ABSPATH . WPINC . '/class-phpass.php' );
		require_once( dirname( __FILE__ ) . '/class-ithemes-credentials.php' );

		$password = iThemes_Credentials::get_password_hash( $username, $password );

		$salted_password = $password . $username . self::get_site_url() . $GLOBALS['wp_version'];
		$salted_password = substr( $salted_password, 0, max( strlen( $password ), 512 ) );

		$hasher = new PasswordHash( self::$password_iterations, true );
		$auth_token = $hasher->HashPassword( $salted_password );

		return $auth_token;
	}

	public static function enable_ssl_ca_patch() {
		add_action( 'http_api_curl', array( __CLASS__, 'add_ca_patch_to_curl_opts' ) );
	}

	public static function disable_ssl_ca_patch() {
		remove_action( 'http_api_curl', array( __CLASS__, 'add_ca_patch_to_curl_opts' ) );
	}

	public static function add_ca_patch_to_curl_opts( $handle ) {
		$url = curl_getinfo( $handle, CURLINFO_EFFECTIVE_URL );

		if ( ! preg_match( '{^https://(api|downloads)\.ithemes\.com}', $url ) ) {
			return;
		}

		curl_setopt( $handle, CURLOPT_CAINFO, $GLOBALS['ithemes_updater_path'] . '/ca/roots.crt' );
	}
}
