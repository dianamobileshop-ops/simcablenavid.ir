<?php
/**
 * Login / registration page: the shared login card.
 *
 * @package Navid
 * @version 9.9.0
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_customer_login_form' );
?>
<div class="navid-auth">
	<?php get_template_part( 'template-parts/login-card', null, array( 'id' => 'page', 'redirect' => '' ) ); ?>
</div>
<?php do_action( 'woocommerce_after_customer_login_form' ); ?>
