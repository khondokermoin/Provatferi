<?php
/**
 * Plugin Name: Provatferi Headless Setup
 * Description: CORS allowlist for the REST API and a frontend redirect for
 * logged-out visitors. This install is content-only for Sahittopata — public
 * visitors read at sahittopata.provatferi.org, not this WP frontend.
 *
 * 2026-09-09: corrected from an earlier version of this file that hardcoded
 * FRONTEND_URL to https://provatferi.com (wrong domain entirely) and allowed
 * only that single origin. Never deployed live in that state.
 */

if ( ! defined( 'FRONTEND_URL' ) ) {
	define( 'FRONTEND_URL', 'https://sahittopata.provatferi.org' );
}

/**
 * Explicit allowlist, not a wildcard: the public reader (Sahittopata) and the
 * main institutional site, which may also need to read literature content.
 * Add an origin here only when something real needs to call this REST API
 * from it — never widen this to '*' or to a request's Origin unconditionally.
 */
function provatferi_cors_allowed_origins(): array {
	return array(
		'https://sahittopata.provatferi.org',
		'https://provatferi.org',
	);
}

add_action( 'rest_api_init', function () {
	remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
	add_filter( 'rest_pre_serve_request', function ( $value ) {
		/*
		 * Cache directives come FIRST and are sent unconditionally, before the
		 * origin check below — they must apply to every REST response, not only
		 * the ones that get an Access-Control-Allow-Origin header.
		 *
		 * Why this exists (measured 2026-09-09, reproduced repeatedly): the
		 * platform edge cache was storing /wp-json/ GET responses under a cache
		 * key of URL + Accept-Encoding only, ignoring the `Vary: Origin` below.
		 * A response generated for one allowed origin was then replayed to a
		 * different origin — an ACAO value naming someone else's origin, which a
		 * browser rejects — and a response generated with no Origin at all was
		 * replayed (cache HIT, no ACAO) to a legitimately allowed origin. Both
		 * directions were observed on the same URL within seconds.
		 *
		 * `Vary: Origin` is the correct fix and stays, but it demonstrably was
		 * not honoured here, so correctness cannot depend on it. These directives
		 * keep origin-dependent responses out of shared caches entirely. Scoped
		 * to REST by construction: this filter only runs for /wp-json/ requests,
		 * so ordinary pages and static assets stay cacheable as before — no
		 * site-wide cache changes.
		 *
		 * The X-LiteSpeed-Cache-Control header is the vendor-specific opt-out for
		 * the LiteSpeed layer in front of PHP, which does not always defer to a
		 * standard Cache-Control on its own.
		 */
		header( 'Cache-Control: no-store, private, max-age=0' );
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		header_remove( 'Expires' );

		$origin = get_http_origin();
		if ( $origin && in_array( $origin, provatferi_cors_allowed_origins(), true ) ) {
			header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
			header( 'Vary: Origin' );
			header( 'Access-Control-Allow-Methods: GET' );
		}
		return $value;
	} );
}, 15 );

// Logged-out visitors hitting this WP frontend get sent to the public reader.
// template_redirect only fires for front-end theme templates — wp-admin,
// wp-login.php, and REST requests are unaffected, so editorial access and
// the API both keep working normally regardless of this redirect.
add_action( 'template_redirect', function () {
	if ( is_user_logged_in() ) {
		return;
	}
	if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}

	wp_redirect( FRONTEND_URL, 302 );
	exit;
} );
