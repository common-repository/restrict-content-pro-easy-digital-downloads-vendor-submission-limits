<?php

/*
Provides an easy to use interface for communicating with the iThemes updater server.
Written by Chris Jean for iThemes.com
Version 1.1.1

Version History
	1.0.0 - 2013-04-11 - Chris Jean
		Release ready
	1.0.1 - 2013-09-19 - Chris Jean
		Updated requires to not use dirname().
		Updated ithemes-updater-object to ithemes-updater-settings.
	1.1.0 - 2013-10-02 - Chris Jean
		Added get_package_changelog().
	1.1.1 - 2014-11-13 - Chris Jean
		Improved caching.
		Updated code to meet WordPress coding standards.
*/


class Ithemes_Updater_API {
	public static function activate_package( $username, $password, $packages ) {
		return self::get_response( 'activate_package', compact( 'username', 'password', 'packages' ), false );
	}

	public static function deactivate_package( $username, $password, $packages ) {
		return self::get_response( 'deactivate_package', compact( 'username', 'password', 'packages' ), false );
	}

	public static function get_licensed_site_url() {
		$packages = array();

		return self::get_response( 'get_licensed_site_url', compact( 'packages' ), false );
	}

	public static function set_licensed_site_url( $username, $password, $site_url ) {
		$packages = array();

		return self::get_response( 'set_licensed_site_url', compact( 'username', 'password', 'site_url', 'packages' ), false );
	}

	public static function get_package_details( $cache = true ) {
		$packages = array();

		return self::get_response( 'get_package_details', compact( 'packages' ), $cache );
	}

	public static function get_package_changelog( $package, $cur_version = false ) {
		$response = wp_remote_get( 'https://api.ithemes.com/product/changelog?package=' . urlencode( $package ) );

		if ( is_wp_error( $response ) && ( 'connect() timed out!' != $response->get_error_message() ) && defined( 'ITHEMES_ALLOW_HTTP_FALLBACK' ) && ITHEMES_ALLOW_HTTP_FALLBACK ) {
			$response = wp_remote_get( 'http://api.ithemes.com/product/changelog?package=' . urlencode( $package ) );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! isset( $response['body'] ) ) {
			return new WP_Error( 'ithemes-updater-changelog-bad-wp-remote-get-response', __( 'Unrecognized response from <code>wp_remote_get</code>.', 'it-l10n-restrict-content-pro-easy-digital-downloads-vendor-submission-limits' ) );
		}

		if ( isset( $response['response']['code'] ) && ( '200' != $response['response']['code'] ) ) {
			return new WP_Error( 'ithemes-updater-wp-remote-get-error-' . $response['response']['code'], $response['response']['message'] );
		}


		$body = $response['body'];

		if ( '{' === substr( $body, 0, 1 ) ) {
			$error = json_decode( $body, true );

			if ( is_array( $error ) && isset( $error['error'] ) && is_array( $error['error'] ) && isset( $error['error']['type'] ) && isset( $error['error']['message'] ) ) {
				return new WP_Error( 'ithemes-updater-json-decode-error-' . $error['error']['type'], $error['error']['message'] );
			} else {
				return new WP_Error( 'ithemes-updater-changelog-bad-json-decode-result', __( 'Unrecognized response from iThemes API server.', 'it-l10n-restrict-content-pro-easy-digital-downloads-vendor-submission-limits' ) );
			}
		}


		$versions = array();
		$version = false;
		$depth = 0;

		$lines = preg_split( '/[\n\r]+/', $body );

		foreach ( $lines as $line ) {
			if ( preg_match( '/^\d/', $line ) ) {
				if ( ! empty( $version ) && ( $depth > 0 ) ) {
					while ( $depth-- > 0 ) {
						$versions[$version] .= "</ul>\n";
					}
				}

				$depth = 0;

				$parts = preg_split( '/\s+-\s+/', $line );
				$version = $parts[0];

				if ( version_compare( $version, $cur_version, '<=' ) ) {
					$version = '';
					continue;
				}

				$versions[$version] = '';

				continue;
			} else if ( preg_match( '/^\S/', $line ) ) {
				$version = '';
				continue;
			} else if ( empty( $version ) ) {
				continue;
			}

			$line = str_replace( '    ', "\t", $line );
			$line = str_replace( "\t", '', $line, $count );
			$line = preg_replace( '/^\s+/', '', $line );

			if ( empty( $line ) ) {
				continue;
			}

			$details = '';

			if ( $count > $depth ) {
				$details .= "<ul>\n";
				$depth++;
			} else if ( $count < $depth ) {
				$details .= "</ul>\n";
				$depth--;
			}

			$details .= "<li>$line</li>\n";


			$versions[$version] .= $details;
		}

		if ( ! empty( $version ) && ( $depth > 0 ) ) {
			while ( $depth-- > 0 ) {
				$versions[$version] .= "</ul>\n";
			}
		}


		uksort( $versions, 'version_compare' );
		$versions = array_reverse( $versions );


		$changelog = '';

		foreach ( $versions as $version => $details ) {
			$changelog .= "<h4>$version</h4>\n$details\n";
		}

		$changelog = preg_replace( '/\s+$/', '', $changelog );


		return $changelog;
	}

	public static function get_response( $action, $args, $cache ) {
		require_once( $GLOBALS['ithemes_updater_path'] . '/server.php' );
		require_once( $GLOBALS['ithemes_updater_path'] . '/updates.php' );


		if ( isset( $args['packages'] ) ) {
			$args['packages'] = self::get_request_package_details( $args['packages'] );
		}


		$response = false;
		$source = '';
		$cached = true;

		$md5 = substr( md5( serialize( $args ) ), 0, 5 );
		$time = time();
		$cache_var = "it-updater-$action-$md5";


		if ( $cache ) {
			if ( isset( $GLOBALS[$cache_var] ) ) {
				$response = $GLOBALS[$cache_var];
				$source = 'GLOBALS';
			} else if ( false !== ( $transient = get_site_transient( $cache_var ) ) ) {
				$response = $transient;
				$source = 'transient';
			}
		}


		if ( false === $response ) {
			$response = call_user_func_array( array( 'Ithemes_Updater_Server', $action ), $args );
			$source = 'server';

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$cache_length = 86400 * $GLOBALS['ithemes-updater-settings']->get_option( 'timeout-multiplier' );

			set_site_transient( $cache_var, $response, $cache_length );

			$cached = false;
		}


		Ithemes_Updater_Updates::process_server_response( $response, $cached );

		$GLOBALS[$cache_var] = $response;

		return $response;
	}

	private static function get_request_package_details( $desired_packages = array() ) {
		require_once( $GLOBALS['ithemes_updater_path'] . '/packages.php' );
		require_once( $GLOBALS['ithemes_updater_path'] . '/keys.php' );


		$all_packages = Ithemes_Updater_Packages::get_local_details();
		reset( $desired_packages );

		if ( empty( $desired_packages ) ) {
			$desired_packages = $all_packages;
		} else if ( is_numeric( key( $desired_packages ) ) ) {
			$new_desired_packages = array();

			foreach ( $all_packages as $path => $details ) {
				foreach ( $desired_packages as $package ) {
					if ( $package != $details['package'] ) {
						continue;
					}

					$new_desired_packages[$path] = $details;

					break;
				}
			}

			$desired_packages = $new_desired_packages;
		}


		$packages = array();
		$keys = Ithemes_Updater_Keys::get();


		$package_slugs = array();

		foreach ( $desired_packages as $data ) {
			$package_slugs[] = $data['package'];
		}

		$legacy_keys = Ithemes_Updater_Keys::get_legacy( $package_slugs );


		$active_themes = array(
			'stylesheet' => get_stylesheet_directory(),
			'template'   => get_template_directory(),
		);
		$active_themes = array_unique( $active_themes );

		foreach ( $active_themes as $index => $path ) {
			$active_themes[$index] = basename( $path );
		}


		foreach ( $desired_packages as $path => $data ) {
			$key = ( isset( $keys[$data['package']] ) ) ? $keys[$data['package']] : '';

			$package = array(
				'ver' => $data['installed'],
				'key' => $key,
			);

			if ( ! empty( $legacy_keys[$data['package']] ) ) {
				$package['old-key'] = $legacy_keys[$data['package']];
			}


			if ( 'plugins' == $data['type'] ) {
				$package['active'] = (int) is_plugin_active( $path );
			} else {
				$dir = dirname( $path );

				$package['active'] = (int) in_array( $dir, $active_themes );

				if ( $package['active'] && ( count( $active_themes ) > 1 ) ) {
					if ( $dir == $active_themes['stylesheet'] ) {
						$package['child-theme'] = 1;
					} else {
						$package['parent-theme'] = 1;
					}
				}
			}


			$package_key = $data['package'];
			$counter = 0;

			while ( isset( $packages[$package_key] ) ) {
				$package_key = "{$data['package']} ||| " . ++$counter;
			}

			$packages[$package_key] = $package;
		}


		if ( ! empty( $legacy_keys ) ) {
			Ithemes_Updater_Keys::delete_legacy( array_keys( $legacy_keys ) );
		}


		return $packages;
	}

	public static function get_error_explanation( $error, $package = '' ) {
		$code = $error->get_error_code();
		$package_name = Ithemes_Updater_Functions::get_package_name( $package );
		$message = '';

		switch( $code ) {
			case 'ITXAPI_Updater_Bad_Login':
				$message = __( 'Incorrect password. Please make sure that you are supplying your iThemes membership username and password details.', 'it-l10n-restrict-content-pro-easy-digital-downloads-vendor-submission-limits' );
				break;
			case 'ITXAPI_Updater_Username_Unknown':
			case 'ITXAPI_Updater_Username_Invalid':
				$message = __( 'Invalid username. Please make sure that you are supplying your iThemes membership username and password details.', 'it-l10n-restrict-content-pro-easy-digital-downloads-vendor-submission-limits' );
				break;
			case 'ITXAPI_Product_Package_Unknown':
				$message = sprintf( __( 'The licensing server reports that the %1$s (%2$s) product is unknown. Please contact support for assistance.', 'it-l10n-restrict-content-pro-easy-digital-downloads-vendor-submission-limits' ), $package_name, $package );
				break;
			case 'ITXAPI_Updater_Too_Many_Sites':
				$message = sprintf( __( '%1$s could not be licensed since the membership account is out of available licenses for this product. You can unlicense the product on other sites or upgrade your membership to one with a higher number of licenses in order to increase the amount of available licenses.', 'it-l10n-restrict-content-pro-easy-digital-downloads-vendor-submission-limits' ), $package_name );
				break;
			case 'ITXAPI_License_Key_Generate_Failed':
				$message = sprintf( __( '%s could not be licensed due to an internal error. Please try to license %s again at a later time. If this problem continues, please contact iThemes support.', 'it-l10n-restrict-content-pro-easy-digital-downloads-vendor-submission-limits' ), $package_name );
				break;
		}

		if ( empty( $message ) ) {
			if ( ! empty( $package ) )
				$message = sprintf( __( 'An unknown error relating to the %1$s product occurred. Please contact iThemes support. Error details: %2$s', 'it-l10n-restrict-content-pro-easy-digital-downloads-vendor-submission-limits' ), $package_name, $error->get_error_message() . " ($code)" );
			else
				$message = sprintf( __( 'An unknown error occurred. Please contact iThemes support. Error details: %s', 'it-l10n-restrict-content-pro-easy-digital-downloads-vendor-submission-limits' ), $error->get_error_message() . " ($code)" );
		}

		return $message;
	}
}
