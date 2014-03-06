<?php
if ( !defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit();
}

include 'config.php';
delete_option($wpcf7ss_config['option_name']);

