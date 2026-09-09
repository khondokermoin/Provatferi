<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
</main>

<footer class="la-site-footer">
	<div class="la-container">
		&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
