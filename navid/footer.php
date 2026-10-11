<?php
/**
 * Site footer.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;
?>
</main>

<footer class="navid-footer">
	<div class="navid-wrap">
		<p>&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?></p>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
