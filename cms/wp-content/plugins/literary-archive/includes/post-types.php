<?php
/**
 * Custom post types: literary_work, book, series.
 * See LITERARY_ARCHIVE_THEME_BRIEF.md §2 for the content model this implements.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function literary_archive_register_post_types() {
	register_post_type( 'literary_work', [
		'labels' => [
			'name'               => __( 'সাহিত্যকর্ম', 'literary-archive' ),
			'singular_name'      => __( 'সাহিত্যকর্ম', 'literary-archive' ),
			'menu_name'          => __( 'সাহিত্য আর্কাইভ', 'literary-archive' ),
			'add_new'            => __( 'নতুন যোগ করুন', 'literary-archive' ),
			'add_new_item'       => __( 'নতুন সাহিত্যকর্ম যোগ করুন', 'literary-archive' ),
			'edit_item'          => __( 'সাহিত্যকর্ম এডিট করুন', 'literary-archive' ),
			'all_items'          => __( 'সব লেখা', 'literary-archive' ),
			'search_items'       => __( 'সাহিত্যকর্ম খুঁজুন', 'literary-archive' ),
			'not_found'          => __( 'কোনো লেখা পাওয়া যায়নি', 'literary-archive' ),
			'not_found_in_trash' => __( 'ট্র্যাশে কোনো লেখা নেই', 'literary-archive' ),
		],
		'public'          => true,
		'show_in_rest'    => true,
		'rest_base'       => 'literary_work',
		'has_archive'     => true,
		'rewrite'         => [ 'slug' => 'sahityokormo' ],
		'supports'        => [ 'title', 'editor', 'author', 'revisions', 'custom-fields' ],
		'capability_type' => 'post',
		'menu_icon'       => 'dashicons-book-alt',
		'menu_position'   => 20,
	] );

	register_post_type( 'book', [
		'labels' => [
			'name'          => __( 'বই', 'literary-archive' ),
			'singular_name' => __( 'বই', 'literary-archive' ),
			'add_new_item'  => __( 'নতুন বই যোগ করুন', 'literary-archive' ),
			'edit_item'     => __( 'বই এডিট করুন', 'literary-archive' ),
			'all_items'     => __( 'বই', 'literary-archive' ),
			'search_items'  => __( 'বই খুঁজুন', 'literary-archive' ),
		],
		'public'       => true,
		'show_in_rest' => true,
		'rest_base'    => 'book',
		'has_archive'  => true,
		'rewrite'      => [ 'slug' => 'books' ],
		'supports'     => [ 'title', 'editor', 'thumbnail', 'custom-fields' ],
		// Nests under the literary_work top-level menu instead of its own (§8).
		'show_in_menu' => 'edit.php?post_type=literary_work',
	] );

	register_post_type( 'series', [
		'labels' => [
			'name'          => __( 'ধারাবাহিক', 'literary-archive' ),
			'singular_name' => __( 'ধারাবাহিক', 'literary-archive' ),
			'add_new_item'  => __( 'নতুন ধারাবাহিক যোগ করুন', 'literary-archive' ),
			'edit_item'     => __( 'ধারাবাহিক এডিট করুন', 'literary-archive' ),
			'all_items'     => __( 'ধারাবাহিক', 'literary-archive' ),
			'search_items'  => __( 'ধারাবাহিক খুঁজুন', 'literary-archive' ),
		],
		'public'       => true,
		'show_in_rest' => true,
		'rest_base'    => 'series',
		'has_archive'  => true,
		'rewrite'      => [ 'slug' => 'series' ],
		'supports'     => [ 'title', 'editor', 'thumbnail' ],
		'show_in_menu' => 'edit.php?post_type=literary_work',
	] );
}
add_action( 'init', 'literary_archive_register_post_types' );
