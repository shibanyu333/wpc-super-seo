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
delete_option( 'super_seo_credentials' );
delete_option( 'super_seo_product_profile' );
delete_option( 'super_seo_last_article_result' );
delete_option( 'super_seo_last_meta_apply' );
delete_transient( 'super_seo_local_audit_results' );
delete_transient( 'super_seo_meta_suggestions' );
delete_transient( 'super_seo_sitemap_entries' );

wp_unschedule_hook( 'super_seo_run_article_cron' );
wp_unschedule_hook( 'super_seo_vision_generate' );

global $wpdb;

$post_meta_keys = array(
	'_super_seo_title',
	'_super_seo_description',
	'_super_seo_keywords',
	'_super_seo_noindex',
	'_super_seo_webp_map',
	'_super_seo_previous_meta',
	'_super_seo_ai_article_gates',
	'_super_seo_vision',
);

foreach ( $post_meta_keys as $meta_key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta_key ), array( '%s' ) );
}

$term_meta_keys = array(
	'_super_seo_title',
	'_super_seo_description',
	'_super_seo_keywords',
	'_super_seo_noindex',
	'_super_seo_previous_meta',
);

foreach ( $term_meta_keys as $meta_key ) {
	$wpdb->delete( $wpdb->termmeta, array( 'meta_key' => $meta_key ), array( '%s' ) );
}
