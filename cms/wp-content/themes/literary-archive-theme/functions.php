<?php
/**
 * Presentation-only theme setup. All content logic lives in the
 * literary-archive plugin (brief §1, §14) — nothing here registers a CPT,
 * taxonomy, or meta field.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function literary_archive_theme_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'html5', [ 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'script', 'style' ] );

	register_nav_menus( [
		'primary' => __( 'প্রধান মেনু', 'literary-archive-theme' ),
	] );
}
add_action( 'after_setup_theme', 'literary_archive_theme_setup' );

function literary_archive_theme_enqueue_assets() {
	wp_enqueue_style(
		'literary-archive-fonts',
		'https://fonts.googleapis.com/css2?family=Noto+Serif+Bengali:wght@500;600;700&family=Noto+Sans+Bengali:wght@400;500;600&display=swap',
		[],
		null
	);

	wp_enqueue_style(
		'literary-archive-theme',
		get_stylesheet_uri(),
		[ 'literary-archive-fonts' ],
		wp_get_theme()->get( 'Version' )
	);
}
add_action( 'wp_enqueue_scripts', 'literary_archive_theme_enqueue_assets' );

/**
 * Used until an editor assigns a real menu to the 'primary' location.
 */
function literary_archive_default_nav() {
	$links = [
		[ __( 'সাহিত্যকর্ম', 'literary-archive-theme' ), get_post_type_archive_link( 'literary_work' ) ],
		[ __( 'বই', 'literary-archive-theme' ), get_post_type_archive_link( 'book' ) ],
		[ __( 'ধারাবাহিক', 'literary-archive-theme' ), get_post_type_archive_link( 'series' ) ],
	];
	echo '<ul>';
	foreach ( $links as [ $label, $url ] ) {
		if ( $url ) {
			echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
		}
	}
	echo '</ul>';
}

function literary_archive_body_class( $classes ) {
	$classes[] = 'literary-archive';
	return $classes;
}
add_filter( 'body_class', 'literary_archive_body_class' );

/**
 * Breadcrumb per brief §10/§11:
 * হোম > সাহিত্যকর্ম > {ধরন} > {শিরোনাম}
 * হোম > ধারাবাহিক > {সিরিজ নাম} > পর্ব {n}   (literary_work that belongs to a series)
 */
function literary_archive_breadcrumb() {
	$crumbs = [ [ 'label' => __( 'হোম', 'literary-archive-theme' ), 'url' => home_url( '/' ) ] ];

	if ( is_singular( 'literary_work' ) ) {
		$series_id = get_post_meta( get_the_ID(), '_series_id', true );

		if ( $series_id ) {
			$crumbs[] = [ 'label' => __( 'ধারাবাহিক', 'literary-archive-theme' ), 'url' => get_post_type_archive_link( 'series' ) ];
			$crumbs[] = [ 'label' => get_the_title( $series_id ), 'url' => get_permalink( $series_id ) ];
			$episode = get_post_meta( get_the_ID(), '_episode_number', true );
			$crumbs[] = [ 'label' => $episode ? sprintf( __( 'পর্ব %s', 'literary-archive-theme' ), $episode ) : get_the_title(), 'url' => '' ];
		} else {
			$crumbs[] = [ 'label' => __( 'সাহিত্যকর্ম', 'literary-archive-theme' ), 'url' => get_post_type_archive_link( 'literary_work' ) ];
			$terms = get_the_terms( get_the_ID(), 'literary_type' );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$crumbs[] = [ 'label' => $terms[0]->name, 'url' => get_term_link( $terms[0] ) ];
			}
			$crumbs[] = [ 'label' => get_the_title(), 'url' => '' ];
		}
	} elseif ( is_post_type_archive( 'literary_work' ) ) {
		$crumbs[] = [ 'label' => __( 'সাহিত্যকর্ম', 'literary-archive-theme' ), 'url' => '' ];
	} elseif ( is_singular( 'book' ) ) {
		$crumbs[] = [ 'label' => __( 'বই', 'literary-archive-theme' ), 'url' => get_post_type_archive_link( 'book' ) ];
		$crumbs[] = [ 'label' => get_the_title(), 'url' => '' ];
	} elseif ( is_singular( 'series' ) ) {
		$crumbs[] = [ 'label' => __( 'ধারাবাহিক', 'literary-archive-theme' ), 'url' => get_post_type_archive_link( 'series' ) ];
		$crumbs[] = [ 'label' => get_the_title(), 'url' => '' ];
	} else {
		return;
	}

	echo '<nav class="la-breadcrumb" aria-label="' . esc_attr__( 'ব্রেডক্রাম্ব', 'literary-archive-theme' ) . '">';
	foreach ( $crumbs as $index => $crumb ) {
		if ( $index > 0 ) {
			echo '<span class="sep">/</span>';
		}
		if ( $crumb['url'] ) {
			echo '<a href="' . esc_url( $crumb['url'] ) . '">' . esc_html( $crumb['label'] ) . '</a>';
		} else {
			echo '<span aria-current="page">' . esc_html( $crumb['label'] ) . '</span>';
		}
	}
	echo '</nav>';
}

/**
 * Server-side archive filtering (brief §6 MVP: query string, no JS needed).
 * type/status = taxonomy slugs, year = _writing_year meta, s = native search.
 */
function literary_archive_filter_archive_query( $query ) {
	if ( is_admin() || ! $query->is_main_query() || ! is_post_type_archive( 'literary_work' ) ) {
		return;
	}

	$tax_query = [];
	if ( ! empty( $_GET['type'] ) ) {
		$tax_query[] = [
			'taxonomy' => 'literary_type',
			'field'    => 'slug',
			'terms'    => sanitize_title( wp_unslash( $_GET['type'] ) ),
		];
	}
	if ( ! empty( $_GET['status'] ) ) {
		$tax_query[] = [
			'taxonomy' => 'literary_status',
			'field'    => 'slug',
			'terms'    => sanitize_title( wp_unslash( $_GET['status'] ) ),
		];
	}
	if ( $tax_query ) {
		$query->set( 'tax_query', $tax_query );
	}

	if ( ! empty( $_GET['year'] ) ) {
		$query->set( 'meta_query', [ [
			'key'     => '_writing_year',
			'value'   => absint( $_GET['year'] ),
			'compare' => '=',
		] ] );
	}

	$query->set( 'posts_per_page', 12 );
}
add_action( 'pre_get_posts', 'literary_archive_filter_archive_query' );

/**
 * Renders the _publications repeater (plugin-owned meta, brief §2/§3).
 */
function literary_archive_render_publications( $post_id ) {
	$publications = get_post_meta( $post_id, '_publications', true );
	if ( empty( $publications ) || ! is_array( $publications ) ) {
		return;
	}
	?>
	<h2><?php esc_html_e( 'প্রকাশনার ইতিহাস', 'literary-archive-theme' ); ?></h2>
	<ul class="la-publications">
		<?php foreach ( $publications as $entry ) : ?>
			<li>
				<?php if ( ! empty( $entry['venue'] ) ) : ?>
					<strong><?php echo esc_html( $entry['venue'] ); ?></strong>
				<?php endif; ?>
				<?php if ( ! empty( $entry['issue'] ) ) : ?>
					· <?php echo esc_html( $entry['issue'] ); ?>
				<?php endif; ?>
				<?php if ( ! empty( $entry['date'] ) ) : ?>
					· <?php echo esc_html( $entry['date'] ); ?>
				<?php endif; ?>
				<?php if ( ! empty( $entry['page'] ) ) : ?>
					· <?php
					/* translators: %s: page number */
					echo esc_html( sprintf( __( 'পৃষ্ঠা %s', 'literary-archive-theme' ), $entry['page'] ) ); ?>
				<?php endif; ?>
				<?php if ( ! empty( $entry['url'] ) ) : ?>
					· <a href="<?php echo esc_url( $entry['url'] ); ?>"><?php esc_html_e( 'লিংক', 'literary-archive-theme' ); ?></a>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php
}
