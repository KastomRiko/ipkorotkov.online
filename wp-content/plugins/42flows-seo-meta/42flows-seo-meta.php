<?php
/**
 * Plugin Name: 42flows SEO Meta
 * Description: Registers SEO meta fields (Yoast, Rank Math, AIOSEO) for WordPress REST API write access. Required for 42flows content delivery. May install or upgrade a companion plugin on request from 42flows.
 * Version: 1.2.1
 * Author: 42flows
 * Author URI: https://42flows.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.6
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * Text Domain: 42flows-seo-meta
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'flows42_register_seo_meta_fields' );
add_action( 'rest_api_init', 'flows42_register_companion_install_route' );

/**
 * Detect active SEO plugin and register its meta fields for REST API access.
 * Only registers fields for the detected plugin — no unnecessary meta keys.
 */
function flows42_register_seo_meta_fields() {
	$fields = array();

	// Yoast SEO
	if ( defined( 'WPSEO_VERSION' ) ) {
		$fields = array(
			'_yoast_wpseo_title',
			'_yoast_wpseo_metadesc',
			'_yoast_wpseo_focuskw',
		);
	}
	// Rank Math
	elseif ( class_exists( 'RankMath' ) ) {
		$fields = array(
			'rank_math_title',
			'rank_math_description',
			'rank_math_focus_keyword',
		);
	}
	// All in One SEO
	elseif ( defined( 'AIOSEO_VERSION' ) ) {
		$fields = array(
			'_aioseo_title',
			'_aioseo_description',
			'_aioseo_keywords',
		);
	}

	$post_types = array( 'post', 'page' );

	foreach ( $post_types as $post_type ) {
		foreach ( $fields as $field ) {
			register_post_meta( $post_type, $field, array(
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
			) );
		}
	}
}

/**
 * POST /wp-json/42flows/v1/install-companion
 *
 * Invoked by the 42flows backend after the site is connected, or when the
 * companion plugin needs upgrading. Downloads and activates the
 * `42flows-content` companion plugin (render-time features: Powered By footer,
 * JSON-LD schema markup, page-optimizer REST surface) from 42flows.com.
 *
 * Behavior matrix:
 *   Companion absent          → install fresh, activate
 *   Companion present, no     → return already_installed=true (idempotent)
 *     min_version param
 *   Companion present, version → if installed >= min_version, return early;
 *     >= min_version            otherwise delete + reinstall + activate
 *   Companion present, version → delete + reinstall + activate
 *     <  min_version
 *
 * Optional request body:
 *   {
 *     "min_version": "1.1.0"   // Semver string. When the installed companion
 *                              // is older than this, the plugin is deleted
 *                              // (deactivated first) and reinstalled from the
 *                              // canonical zip URL. Required for in-place
 *                              // upgrades because WP's `Plugin_Upgrader::install`
 *                              // refuses to overwrite an existing plugin folder.
 *   }
 *
 * Auth: `install_plugins` + `activate_plugins` + (when upgrading) `delete_plugins`.
 * The WP Application Password user must hold these capabilities — typically
 * an administrator role. No installation, upgrade, or deletion occurs unless
 * the request is authenticated and the user holds the required caps.
 *
 * The zip URL is hard-coded to https://42flows.com/plugins/42flows-content-latest.zip
 * — it is never user-supplied. There is no SSRF surface.
 *
 * @since 1.1.0  Initial install support.
 * @since 1.2.0  Adds optional min_version param for in-place upgrades.
 */
function flows42_register_companion_install_route() {
	register_rest_route( '42flows/v1', '/install-companion', array(
		'methods'             => 'POST',
		'callback'            => 'flows42_install_companion',
		'permission_callback' => function () {
			return current_user_can( 'install_plugins' ) && current_user_can( 'activate_plugins' );
		},
		'args'                => array(
			'min_version' => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $value ) {
					if ( '' === $value || null === $value ) {
						return true;
					}
					// Accept simple X.Y.Z semver. Reject anything else so we never
					// pass arbitrary strings to version_compare().
					return is_string( $value ) && preg_match( '/^\d+\.\d+\.\d+$/', $value );
				},
			),
		),
	) );
}

function flows42_install_companion( WP_REST_Request $request ) {
	$companion_slug = '42flows-content';
	$companion_file = $companion_slug . '/42flows-content.php';
	$zip_url        = 'https://42flows.com/plugins/42flows-content-latest.zip';

	$min_version = $request->get_param( 'min_version' );
	if ( ! is_string( $min_version ) || '' === $min_version ) {
		$min_version = null;
	}

	$plugin_path = WP_PLUGIN_DIR . '/' . $companion_file;

	// Already installed branch — either return early (idempotent) or upgrade
	// in place if the caller asked for a min_version newer than what's there.
	if ( file_exists( $plugin_path ) ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed_version = flows42_get_companion_version( $companion_file );

		if ( null !== $min_version && null !== $installed_version
			&& version_compare( $installed_version, $min_version, '<' ) ) {
			// Version-aware upgrade path. Caller MUST hold delete_plugins
			// because we're removing the existing companion folder before
			// reinstalling the latest from the canonical zip.
			if ( ! current_user_can( 'delete_plugins' ) ) {
				return new WP_Error(
					'flows42_upgrade_forbidden',
					'Authenticated user lacks delete_plugins capability — required for in-place upgrade.',
					array( 'status' => 403 )
				);
			}
			$result = flows42_upgrade_companion( $companion_file, $companion_slug, $zip_url, $installed_version, $min_version );
			flows42_ensure_auto_updates_enabled( $companion_file );
			return $result;
		}

		// No min_version OR installed >= min_version: idempotent ensure-active.
		if ( ! is_plugin_active( $companion_file ) ) {
			$activate = activate_plugin( $companion_file );
			if ( is_wp_error( $activate ) ) {
				return new WP_Error( 'flows42_activate_failed', $activate->get_error_message(), array( 'status' => 500 ) );
			}
		}
		$auto_updates_enabled = flows42_ensure_auto_updates_enabled( $companion_file );
		return rest_ensure_response( array(
			'success'              => true,
			'already_installed'    => true,
			'activated'            => true,
			'upgraded'             => false,
			'version'              => $installed_version,
			'min_version'          => $min_version,
			'auto_updates_enabled' => $auto_updates_enabled,
		) );
	}

	// Fresh install path — companion not present yet.
	$installed_version = flows42_perform_companion_install( $zip_url, $companion_file );
	if ( is_wp_error( $installed_version ) ) {
		return $installed_version;
	}

	$auto_updates_enabled = flows42_ensure_auto_updates_enabled( $companion_file );

	return rest_ensure_response( array(
		'success'              => true,
		'already_installed'    => false,
		'activated'            => true,
		'upgraded'             => false,
		'version'              => $installed_version,
		'min_version'          => $min_version,
		'auto_updates_enabled' => $auto_updates_enabled,
	) );
}

/**
 * Add the 42flows plugins to WordPress's `auto_update_plugins` site option so
 * future feature/security/fix releases reach the customer site without a
 * manual click in `Plugins > Enable auto-updates`. Idempotent — safe to call
 * on every site test / connect.
 *
 * Why this is the right primitive: customers connect their site to 42flows
 * specifically because they want 42flows to manage the meta + render layer
 * for them. Asking them to also manually toggle auto-updates per plugin is
 * onboarding friction with no upside (every WP-side plugin update of these
 * two plugins comes from us anyway). Customers retain control: WP admin →
 * Plugins → "Disable auto-updates" link is one click away.
 *
 * Returns an array { '42flows-content/42flows-content.php' => bool, ... }
 * indicating whether each plugin's auto-update state was set after this call.
 *
 * @since 1.2.1
 */
function flows42_ensure_auto_updates_enabled( $companion_file ) {
	$flows42_plugins = array(
		$companion_file,                          // 42flows-content/42flows-content.php
		plugin_basename( __FILE__ ),              // 42flows-seo-meta/42flows-seo-meta.php
	);

	$auto_update_plugins = (array) get_site_option( 'auto_update_plugins', array() );

	$changed = false;
	$result  = array();
	foreach ( $flows42_plugins as $plugin_file ) {
		if ( ! in_array( $plugin_file, $auto_update_plugins, true ) ) {
			$auto_update_plugins[] = $plugin_file;
			$changed               = true;
		}
		$result[ $plugin_file ] = true;
	}

	if ( $changed ) {
		update_site_option( 'auto_update_plugins', array_values( array_unique( $auto_update_plugins ) ) );
	}

	return $result;
}

/**
 * Delete + reinstall + activate. Used only when min_version is supplied and
 * the installed companion is older. Deactivates first to avoid a half-state,
 * deletes the plugin folder via WP's `delete_plugins()` (which uses
 * WP_Filesystem under the hood), then runs the same fresh-install path.
 */
function flows42_upgrade_companion( $companion_file, $companion_slug, $zip_url, $previous_version, $min_version ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';

	if ( is_plugin_active( $companion_file ) ) {
		deactivate_plugins( $companion_file, true );
	}

	if ( ! WP_Filesystem() ) {
		return new WP_Error( 'flows42_filesystem_readonly', 'WordPress filesystem is not writable.', array( 'status' => 500 ) );
	}

	$delete_result = delete_plugins( array( $companion_file ) );
	if ( is_wp_error( $delete_result ) ) {
		return new WP_Error( 'flows42_delete_failed', $delete_result->get_error_message(), array( 'status' => 500 ) );
	}
	if ( false === $delete_result ) {
		return new WP_Error( 'flows42_delete_failed', 'Failed to delete existing companion plugin folder.', array( 'status' => 500 ) );
	}

	$installed_version = flows42_perform_companion_install( $zip_url, $companion_file );
	if ( is_wp_error( $installed_version ) ) {
		return $installed_version;
	}

	return rest_ensure_response( array(
		'success'           => true,
		'already_installed' => false,
		'activated'         => true,
		'upgraded'          => true,
		'previous_version'  => $previous_version,
		'version'           => $installed_version,
		'min_version'       => $min_version,
	) );
}

/**
 * Download zip → install → activate. Returns the installed version string on
 * success or a WP_Error on failure. Shared by the fresh-install and upgrade
 * paths so install logic only lives in one place.
 */
function flows42_perform_companion_install( $zip_url, $companion_file ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';

	if ( ! WP_Filesystem() ) {
		return new WP_Error( 'flows42_filesystem_readonly', 'WordPress filesystem is not writable. Cannot install companion plugin automatically.', array( 'status' => 500 ) );
	}

	$skin     = new WP_Ajax_Upgrader_Skin();
	$upgrader = new Plugin_Upgrader( $skin );
	$result   = $upgrader->install( $zip_url );

	if ( is_wp_error( $result ) ) {
		return new WP_Error( 'flows42_install_failed', $result->get_error_message(), array( 'status' => 500 ) );
	}
	if ( false === $result || null === $result ) {
		$errors = $skin->get_errors();
		$msg    = is_wp_error( $errors ) ? $errors->get_error_message() : 'Plugin install failed for unknown reason.';
		return new WP_Error( 'flows42_install_failed', $msg, array( 'status' => 500 ) );
	}

	if ( ! file_exists( WP_PLUGIN_DIR . '/' . $companion_file ) ) {
		return new WP_Error( 'flows42_install_failed', 'Companion plugin zip installed but expected file not found.', array( 'status' => 500 ) );
	}

	$activate = activate_plugin( $companion_file );
	if ( is_wp_error( $activate ) ) {
		return new WP_Error( 'flows42_activate_failed', $activate->get_error_message(), array( 'status' => 500 ) );
	}

	return flows42_get_companion_version( $companion_file );
}

function flows42_get_companion_version( $companion_file ) {
	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $companion_file, false, false );
	return isset( $data['Version'] ) ? $data['Version'] : null;
}
