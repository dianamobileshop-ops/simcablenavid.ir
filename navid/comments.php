<?php
/**
 * Comments.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

if ( post_password_required() ) {
	return;
}
?>
<section id="comments" class="navid-content navid-comments">
	<?php if ( have_comments() ) : ?>
		<h2><?php echo esc_html( sprintf( '%s دیدگاه', number_format_i18n( get_comments_number() ) ) ); ?></h2>
		<ol><?php wp_list_comments( array( 'style' => 'ol', 'short_ping' => true ) ); ?></ol>
		<?php the_comments_pagination(); ?>
	<?php endif; ?>
	<?php comment_form(); ?>
</section>
