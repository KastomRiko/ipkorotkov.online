<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the web site, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * Localized language
 * * ABSPATH
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'u3679875_baza' );

/** Database username */
define( 'DB_USER', 'u3679875_admin' );

/** Database password */
define( 'DB_PASSWORD', 'mjx-2ae-uYd-C2G' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',          '_<Ak9twOZPhCt,z!L cEtX} ||*LWpIdq0`302Qtl]t$d=OrkTqMKVIwI=Estb2}' );
define( 'SECURE_AUTH_KEY',   'MNcRvSb5S. ZCu6{z|#y2zehg5_nuo@-fQy-FvP3cR=I8tg:rPif$FK]y0<24?fr' );
define( 'LOGGED_IN_KEY',     'k:Zn1@cXt{BKt@1z~O+iycZ3:!#)PB pF#UB:ENE./>o#;vIQ$Z]yHc*axa~Zc)`' );
define( 'NONCE_KEY',         '::dF>&{` ZcKn;IrTyqkPHoXD?~EL;~_T]zoB0)+!GT|6dGpx-{-r?g:XA9rWvmK' );
define( 'AUTH_SALT',         '~Mm{GMixJjc3[~o%[n:{1doIgI_<e;%:J]J~*~<^9=^&^fkLg+<#16B^T:#9/QS<' );
define( 'SECURE_AUTH_SALT',  'CyUwZ^J:-+txN`7dDYpfl+iB<Tu`jf(WI]JX_^4:{`X!iq.8g7[$>M`YVkK>e;=,' );
define( 'LOGGED_IN_SALT',    'yD*}jLi!3Eznj/MAM)8q+g1;+Gtf*xt5N7}L4coj5D~IG8+cQBLL>CdC36Dqi_.!' );
define( 'NONCE_SALT',        ',F^^SWFI|.4B#>P]ZOmBG3/Xk]FA*<A:g<s{,>B5]mzrlU&%hA{2J<]<JiuKK)W_' );
define( 'WP_CACHE_KEY_SALT', 'E(M6k>p2>hr`^hcTjUA~IOrN&%^$9oO;_viFNNcpdqw>MR(pqolWXz8?rC)! O6O' );


/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';


/* Add any custom values between this line and the "stop editing" line. */

define('FORCE_SSL_ADMIN', true); define('FORCE_SSL_LOGIN', true);

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://wordpress.org/support/article/debugging-in-wordpress/
 */
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
