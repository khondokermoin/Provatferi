<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$literary_type = get_the_terms( get_the_ID(), 'literary_type' );
$writing_year  = get_post_meta( get_the_ID(), '_writing_year', true );
?>
<article class="la-card">
	<h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
	<div class="la-meta-row">
		<?php if ( $literary_type && ! is_wp_error( $literary_type ) ) : ?>
			<span class="la-badge"><?php echo esc_html( $literary_type[0]->name ); ?></span>
		<?php endif; ?>
		<?php if ( $writing_year ) : ?>
			<span><?php echo esc_html( $writing_year ); ?></span>
		<?php endif; ?>
	</div>
	<div class="la-entry-content"><?php the_excerpt(); ?></div>
</article>
