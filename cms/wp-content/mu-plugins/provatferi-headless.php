<?php
/**
 * Plugin Name: Provatferi Headless Setup
 * Description: CORS for the REST API and a frontend redirect for logged-out visitors, per DEVELOPER_GUIDE.md section 3.2 / 3.5.
 */

if ( ! defined( 'FRONTEND_URL' ) ) {
	define( 'FRONTEND_URL', 'https://provatferi.com' );
}

// 3.5 — allow the Next.js frontend to read the REST API cross-origin.
add_action( 'rest_api_init', function () {
	remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
	add_filter( 'rest_pre_serve_request', function ( $value ) {
		header( 'Access-Control-Allow-Origin: ' . FRONTEND_URL );
		header( 'Access-Control-Allow-Methods: GET' );
		return $value;
	} );
}, 15 );

/**
 * The literary-archive plugin/theme is deliberately NOT headless — it's
 * meant to be browsed directly on this WP install via its own theme
 * (LITERARY_ARCHIVE_THEME_BRIEF.md §0). Only the Provatferi magazine
 * (standard posts/pages) redirects to the Next.js frontend.
 */
function provatferi_is_literary_archive_request() {
	$post_types = [ 'literary_work', 'book', 'series' ];
	$taxonomies = [ 'literary_type', 'literary_tag', 'literary_status', 'publication_venue' ];

	return is_singular( $post_types )
		|| is_post_type_archive( $post_types )
		|| is_tax( $taxonomies );
}

// 3.2 — logged-out visitors hitting the WP frontend get sent to the Next.js site.
add_action( 'template_redirect', function () {
	if ( is_user_logged_in() ) {
		return;
	}
	if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	if ( provatferi_is_literary_archive_request() ) {
		return;
	}

	wp_redirect( FRONTEND_URL, 302 );
	exit;
} );
