<?php
if ( ! is_active_sidebar( 'sidebar-blog' ) ) {
	return;
}
?>

<aside class="sidebar" role="complementary">
	<?php dynamic_sidebar( 'sidebar-blog' ); ?>
</aside>
