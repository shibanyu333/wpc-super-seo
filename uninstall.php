<?php
/**
 * Plugin uninstall cleanup.
 *
 * @package SuperSEO
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'super_seo_settings' );
