<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

while ( have_posts() ) :
	the_post();

	literary_archive_breadcrumb();

	$literary_type  = get_the_terms( get_the_ID(), 'literary_type' );
	$literary_tags  = get_the_terms( get_the_ID(), 'literary_tag' );
	$writing_year   = get_post_meta( get_the_ID(), '_writing_year', true );
	$writing_place  = get_post_meta( get_the_ID(), '_writing_place', true );
	$series_id      = get_post_meta( get_the_ID(), '_series_id', true );
	?>

	<article>
		<h1 class="la-title"><?php the_title(); ?></h1>

		<div class="la-meta-row">
			<?php if ( $literary_type && ! is_wp_error( $literary_type ) ) : ?>
				<span class="la-badge"><?php echo esc_html( $literary_type[0]->name ); ?></span>
			<?php endif; ?>
			<?php if ( $writing_year ) : ?>
				<span><?php echo esc_html( $writing_year ); ?></span>
			<?php endif; ?>
			<?php if ( $writing_place ) : ?>
				<span><?php echo esc_html( $writing_place ); ?></span>
			<?php endif; ?>
		</div>

		<div class="la-entry-content">
			<?php the_content(); ?>
		</div>

		<?php literary_archive_render_publications( get_the_ID() ); ?>

		<?php if ( $literary_tags && ! is_wp_error( $literary_tags ) ) : ?>
			<p class="la-meta-row">
				<?php foreach ( $literary_tags as $tag ) : ?>
					<span class="la-badge"><?php echo esc_html( $tag->name ); ?></span>
				<?php endforeach; ?>
			</p>
		<?php endif; ?>

		<?php if ( $series_id ) : ?>
			<?php
			$episode_number = get_post_meta( get_the_ID(), '_episode_number', true );
			$episodes_query = new WP_Query( [
				'post_type'      => 'literary_work',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => [ 'episode_clause' => 'ASC' ],
				'meta_query'     => [
					'relation'       => 'AND',
					'series_clause'  => [
						'key'   => '_series_id',
						'value' => $series_id,
					],
					'episode_clause' => [
						'key'  => '_episode_number',
						'type' => 'NUMERIC',
					],
				],
			] );

			$ids     = wp_list_pluck( $episodes_query->posts, 'ID' );
			$pos     = array_search( get_the_ID(), $ids, true );
			$prev_id = ( false !== $pos && $pos > 0 ) ? $ids[ $pos - 1 ] : null;
			$next_id = ( false !== $pos && $pos < count( $ids ) - 1 ) ? $ids[ $pos + 1 ] : null;
			?>
			<nav class="la-meta-row" aria-label="<?php esc_attr_e( 'পর্ব নেভিগেশন', 'literary-archive-theme' ); ?>">
				<?php if ( $prev_id ) : ?>
					<a href="<?php echo esc_url( get_permalink( $prev_id ) ); ?>">&larr; <?php esc_html_e( 'আগের পর্ব', 'literary-archive-theme' ); ?></a>
				<?php endif; ?>
				<?php if ( $next_id ) : ?>
					<a href="<?php echo esc_url( get_permalink( $next_id ) ); ?>"><?php esc_html_e( 'পরের পর্ব', 'literary-archive-theme' ); ?> &rarr;</a>
				<?php endif; ?>
			</nav>
		<?php endif; ?>
	</article>

<?php endwhile; ?>

<?php get_footer(); ?>
