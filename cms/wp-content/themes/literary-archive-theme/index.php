<?php
/**
 * Generic fallback template. The literary archive itself is served by
 * archive-literary_work.php / single-literary_work.php / single-book.php /
 * single-series.php — this only catches whatever falls through those.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<?php if ( have_posts() ) : ?>
	<div class="la-archive-grid">
		<?php while ( have_posts() ) : the_post(); ?>
			<article class="la-card">
				<h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
				<div class="la-entry-content"><?php the_excerpt(); ?></div>
			</article>
		<?php endwhile; ?>
	</div>
	<?php the_posts_pagination( [ 'class' => 'la-pagination' ] ); ?>
<?php else : ?>
	<p class="la-empty-state"><?php esc_html_e( 'কিছু পাওয়া যায়নি।', 'literary-archive-theme' ); ?></p>
<?php endif; ?>

<?php get_footer(); ?>
