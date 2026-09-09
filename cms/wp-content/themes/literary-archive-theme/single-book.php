<?php
/**
 * "This book's works" is never stored — queried back from _related_books
 * on literary_work, per brief §3 (avoids two-way sync).
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
		$works_in_book = new WP_Query( [
			'post_type'      => 'literary_work',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_query'     => [ [
				'key'     => '_related_books',
				'value'   => get_the_ID(),
				'compare' => 'LIKE',
			] ],
		] );
		?>
		<?php if ( $works_in_book->have_posts() ) : ?>
			<h2><?php esc_html_e( 'এই বইয়ের লেখা', 'literary-archive-theme' ); ?></h2>
			<ul class="la-related-works">
				<?php while ( $works_in_book->have_posts() ) : $works_in_book->the_post(); ?>
					<li><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></li>
				<?php endwhile; ?>
			</ul>
			<?php wp_reset_postdata(); ?>
		<?php endif; ?>
	</article>

<?php endwhile; ?>

<?php get_footer(); ?>
