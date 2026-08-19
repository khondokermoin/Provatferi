<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="la-skip-link" href="#la-main"><?php esc_html_e( 'মূল কনটেন্টে যান', 'literary-archive-theme' ); ?></a>

<header class="la-site-header">
	<div class="la-container">
		<a class="la-site-title" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php bloginfo( 'name' ); ?>
		</a>
		<nav class="la-main-nav" aria-label="<?php esc_attr_e( 'প্রধান মেনু', 'literary-archive-theme' ); ?>">
			<?php
			wp_nav_menu( [
				'theme_location' => 'primary',
				'container'      => false,
				'fallback_cb'    => 'literary_archive_default_nav',
			] );
			?>
		</nav>
	</div>
</header>

<main id="la-main" class="la-container">
