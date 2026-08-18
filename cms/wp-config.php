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
define( 'DB_NAME', 'provatferi_cms' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', '' );

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
define( 'AUTH_KEY',          '*@PuD3v(^aE|>qCONXI5fCP+qB>BqtG323im$B0{w#vz8jOnZWibv|>_fe&f0FZd' );
define( 'SECURE_AUTH_KEY',   '$4KM>c8!jZK-=A[7;mKm&*F6q)3RP+yNc^;*!0T?+ q,B7=>*Cwix>K6ZrmLZ*>e' );
define( 'LOGGED_IN_KEY',     '4S1* ip).!*!g#d6v`ou<S{oiRpkXZsm y5)uiW7>QlD3|]WYvJO7)s5]nNrt/{d' );
define( 'NONCE_KEY',         'D5d5bLMYDTOe`VVKr@7*Pt}ln|9Rg.xMs**c06OGsI[(%4iNH`C+/wH;xWmD/Ts2' );
define( 'AUTH_SALT',         'YK.quRL;F@Y@tk;>j#;7fQwBQ3x.hj-BYUdB}$$?;C|~y0fAgk[/4+@)+ksGGRnz' );
define( 'SECURE_AUTH_SALT',  '=Xn#{Q=#jz-9<abP`[.)(L9y^!bq4XeWq) nFu|Tt*EX.@ht>]nU  UkCWJptS/l' );
define( 'LOGGED_IN_SALT',    '20+R^5Va.3uM?*7P_=]PV*9XKcZj}&iNG.izzeUmPkR8m,~5X0;4vFk++O&nP*D|' );
define( 'NONCE_SALT',        'KRhyhHWW }r[5uu#($]}ZQ*k#szvo6c=%L7(KjX!w>:B9Y6`%:2qvD[8x89[C(0H' );
define( 'WP_CACHE_KEY_SALT', '>Z}Qlmj^K|`@P7{[kC?B#xMW/Z6}wLp,Wm xPa.)1m8wPA4(&Z]i#N_8nzrL<ThC' );


/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';


/* Add any custom values between this line and the "stop editing" line. */

/**
 * Headless setup: URL of the Next.js frontend that is allowed to read the
 * REST API and where logged-out visitors get redirected. Local dev points
 * at the Next.js dev server; change this to https://provatferi.com on the
 * Hostinger production server.
 */
define( 'FRONTEND_URL', 'http://localhost:3000' );

/**
 * JWT Authentication for WP REST API — used for fetching draft/preview
 * content from Next.js. Generate a fresh secret per environment; this one
 * is for local dev only, do not reuse it in production.
 */
define( 'JWT_AUTH_SECRET_KEY', 'kZ4!7bQ2vN9pR1sT6uW3xY8zA5cD0eF-provatferi-local-dev' );
define( 'JWT_AUTH_CORS_ENABLE', true );

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
