<?php
/**
 * Plugin Name: Literary Archive
 * Description: Content model, taxonomy, and archive logic for the "আমার সাহিত্য আর্কাইভ" literary archive. Presentation lives in the companion literary-archive-theme; this plugin owns everything else so data survives a theme change. See LITERARY_ARCHIVE_THEME_BRIEF.md.
 * Version: 0.1.0
 * Text Domain: literary-archive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LITERARY_ARCHIVE_PATH', plugin_dir_path( __FILE__ ) );
define( 'LITERARY_ARCHIVE_VERSION', '0.1.0' );

require_once LITERARY_ARCHIVE_PATH . 'includes/post-types.php';
require_once LITERARY_ARCHIVE_PATH . 'includes/taxonomies.php';
require_once LITERARY_ARCHIVE_PATH . 'includes/meta-fields.php';

function literary_archive_activate() {
	literary_archive_register_post_types();
	literary_archive_register_taxonomies();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'literary_archive_activate' );

function literary_archive_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'literary_archive_deactivate' );
