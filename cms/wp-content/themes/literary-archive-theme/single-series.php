<?php
/**
 * Episode order comes from a live query on _episode_number, not stored
 * links — an episode can be edited/deleted without breaking navigation
 * (brief §2).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

while ( have_posts() ) :
	the_post();
	literary_archive_breadcrumb();
	?>

	<article>
		<h1 class="la-title"><?php the_title(); ?></h1>

		<?php if ( has_post_thumbnail() ) : ?>
			<?php the_post_thumbnail( 'medium' ); ?>
		<?php endif; ?>

		<div class="la-entry-content">
			<?php the_content(); ?>
		</div>

		<?php
		$episodes = new WP_Query( [
			'post_type'      => 'literary_work',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => [ 'episode_clause' => 'ASC' ],
			'meta_query'     => [
				'relation'       => 'AND',
				'series_clause'  => [
					'key'   => '_series_id',
					'value' => get_the_ID(),
				],
				'episode_clause' => [
					'key'  => '_episode_number',
					'type' => 'NUMERIC',
				],
			],
		] );
		?>
		<?php if ( $episodes->have_posts() ) : ?>
			<h2><?php esc_html_e( 'পর্বসমূহ', 'literary-archive-theme' ); ?></h2>
			<ul class="la-related-works">
				<?php while ( $episodes->have_posts() ) : $episodes->the_post(); ?>
					<li>
						<a href="<?php the_permalink(); ?>">
							<?php echo esc_html( get_post_meta( get_the_ID(), '_episode_number', true ) ); ?> —
							<?php the_title(); ?>
						</a>
					</li>
				<?php endwhile; ?>
			</ul>
			<?php wp_reset_postdata(); ?>
		<?php endif; ?>
	</article>

<?php endwhile; ?>

<?php get_footer(); ?>
