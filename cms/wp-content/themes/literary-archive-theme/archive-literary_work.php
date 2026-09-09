<?php
/**
 * Archive + filter for literary_work. Filtering is server-side via query
 * string (brief §6 MVP) — works with JS disabled, no separate REST endpoint
 * needed for this phase.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
literary_archive_breadcrumb();

$types    = get_terms( [ 'taxonomy' => 'literary_type', 'hide_empty' => false ] );
$statuses = get_terms( [ 'taxonomy' => 'literary_status', 'hide_empty' => false ] );
?>

<h1 class="la-title"><?php post_type_archive_title(); ?></h1>

<form class="la-filter-bar" method="get">
	<label>
		<?php esc_html_e( 'ধরন', 'literary-archive-theme' ); ?>
		<select name="type">
			<option value=""><?php esc_html_e( 'সব ধরন', 'literary-archive-theme' ); ?></option>
			<?php foreach ( $types as $term ) : ?>
				<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $_GET['type'] ?? '', $term->slug ); ?>>
					<?php echo esc_html( $term->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</label>

	<label>
		<?php esc_html_e( 'প্রকাশনার অবস্থা', 'literary-archive-theme' ); ?>
		<select name="status">
			<option value=""><?php esc_html_e( 'সব', 'literary-archive-theme' ); ?></option>
			<?php foreach ( $statuses as $term ) : ?>
				<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $_GET['status'] ?? '', $term->slug ); ?>>
					<?php echo esc_html( $term->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</label>

	<label>
		<?php esc_html_e( 'সাল', 'literary-archive-theme' ); ?>
		<input type="number" name="year" value="<?php echo esc_attr( $_GET['year'] ?? '' ); ?>" placeholder="২০২৪">
	</label>

	<label>
		<?php esc_html_e( 'খুঁজুন', 'literary-archive-theme' ); ?>
		<input type="search" name="s" value="<?php echo esc_attr( $_GET['s'] ?? '' ); ?>">
	</label>

	<button type="submit"><?php esc_html_e( 'ফিল্টার করুন', 'literary-archive-theme' ); ?></button>
</form>

<?php if ( have_posts() ) : ?>
	<div class="la-archive-grid">
		<?php while ( have_posts() ) : the_post(); ?>
			<?php get_template_part( 'template-parts/content-literary-work', 'card' ); ?>
		<?php endwhile; ?>
	</div>
	<?php the_posts_pagination( [ 'class' => 'la-pagination' ] ); ?>
<?php else : ?>
	<p class="la-empty-state"><?php esc_html_e( 'এই ফিল্টারে কোনো লেখা পাওয়া যায়নি।', 'literary-archive-theme' ); ?></p>
<?php endif; ?>

<?php get_footer(); ?>
