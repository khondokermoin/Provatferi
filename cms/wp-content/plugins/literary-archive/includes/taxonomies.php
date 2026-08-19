<?php
/**
 * Taxonomies for literary_work. literary_status and publication_venue are
 * metadata for filtering only — never access control (see brief §2, §17).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function literary_archive_register_taxonomies() {
	register_taxonomy( 'literary_type', 'literary_work', [
		'labels' => [
			'name'          => __( 'ধরন', 'literary-archive' ),
			'singular_name' => __( 'ধরন', 'literary-archive' ),
			'search_items'  => __( 'ধরন খুঁজুন', 'literary-archive' ),
			'all_items'     => __( 'সব ধরন', 'literary-archive' ),
			'edit_item'     => __( 'ধরন এডিট করুন', 'literary-archive' ),
			'add_new_item'  => __( 'নতুন ধরন যোগ করুন', 'literary-archive' ),
		],
		'hierarchical' => true,
		'show_in_rest' => true,
		'show_in_menu' => true,
	] );

	register_taxonomy( 'literary_tag', 'literary_work', [
		'labels' => [
			'name'          => __( 'বিষয় ট্যাগ', 'literary-archive' ),
			'singular_name' => __( 'ট্যাগ', 'literary-archive' ),
			'search_items'  => __( 'ট্যাগ খুঁজুন', 'literary-archive' ),
			'all_items'     => __( 'সব ট্যাগ', 'literary-archive' ),
			'edit_item'     => __( 'ট্যাগ এডিট করুন', 'literary-archive' ),
			'add_new_item'  => __( 'নতুন ট্যাগ যোগ করুন', 'literary-archive' ),
			'menu_name'     => __( 'ট্যাগ', 'literary-archive' ),
		],
		'hierarchical' => false,
		'show_in_rest' => true,
		'show_in_menu' => true,
	] );

	// Publication/editorial status, not WP visibility — filtering only (§2).
	register_taxonomy( 'literary_status', 'literary_work', [
		'labels' => [
			'name'          => __( 'প্রকাশনার অবস্থা', 'literary-archive' ),
			'singular_name' => __( 'প্রকাশনার অবস্থা', 'literary-archive' ),
		],
		'hierarchical' => false,
		'show_in_rest' => true,
		'show_in_menu' => false,
		'show_ui'      => true,
	] );

	register_taxonomy( 'publication_venue', 'literary_work', [
		'labels' => [
			'name'          => __( 'প্রকাশনা মাধ্যম', 'literary-archive' ),
			'singular_name' => __( 'প্রকাশনা মাধ্যম', 'literary-archive' ),
		],
		'hierarchical' => false,
		'show_in_rest' => true,
		'show_in_menu' => false,
		'show_ui'      => true,
	] );
}
add_action( 'init', 'literary_archive_register_taxonomies' );
