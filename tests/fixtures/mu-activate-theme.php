<?php
/**
 * Forces Pediment to be the active theme during wp-env.
 */

add_action( 'init', function () {
	if ( wp_get_theme()->get_stylesheet() !== 'pediment' ) {
		switch_theme( 'pediment' );
	}
}, 1 );
