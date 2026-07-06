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
delete_option( 'super_seo_product_profile' );
delete_option( 'super_seo_last_article_result' );
delete_transient( 'super_seo_local_audit_results' );
delete_transient( 'super_seo_meta_suggestions' );
delete_transient( 'super_seo_sitemap_entries' );

global $wpdb;

foreach ( array( '_super_seo_title', '_super_seo_description', '_super_seo_keywords', '_super_seo_webp_map', '_super_seo_previous_meta', '_super_seo_ai_article_gates' ) as $meta_key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta_key ), array( '%s' ) );
}

foreach ( array( '_super_seo_title', '_super_seo_description', '_super_seo_keywords', '_super_seo_previous_meta' ) as $meta_key ) {
	$wpdb->delete( $wpdb->termmeta, array( 'meta_key' => $meta_key ), array( '%s' ) );
}
