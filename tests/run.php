<?php
/**
 * Lightweight regression tests for pure Super SEO helpers.
 *
 * @package SuperSEO
 */

define( 'ABSPATH', __DIR__ );

function get_bloginfo( $show = '' ) {
	return 'UTF-8';
}

function wp_strip_all_tags( $text ) {
	return strip_tags( (string) $text );
}

function strip_shortcodes( $content ) {
	return $content;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
	return json_encode( $value, $flags, $depth );
}

$GLOBALS['super_seo_test_post_meta'] = array(
	123 => array(
		'_super_seo_title'       => 'Old SEO title',
		'_super_seo_description' => 'Old SEO description',
		'_super_seo_keywords'    => 'old keyword',
	),
);

function get_post_meta( $post_id, $key, $single = true ) {
	return $GLOBALS['super_seo_test_post_meta'][ $post_id ][ $key ] ?? '';
}

require dirname( __DIR__ ) . '/includes/class-super-seo-helpers.php';
require dirname( __DIR__ ) . '/includes/class-super-seo-audit.php';
require dirname( __DIR__ ) . '/includes/class-super-seo-automation.php';

function super_seo_assert_true( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function super_seo_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$message}\nExpected: {$expected}\nActual: {$actual}\n" );
		exit( 1 );
	}
}

$json = Super_SEO_Helpers::json_for_html_script(
	array(
		'headline' => '</script><script>alert(1)</script>',
		'brand'    => '中文品牌',
	)
);

super_seo_assert_true( false === stripos( $json, '</script>' ), 'JSON for inline scripts must not contain a raw closing script tag.' );
super_seo_assert_true( false !== strpos( $json, '中文品牌' ), 'JSON for inline scripts should keep Unicode readable.' );

$keywords = Super_SEO_Helpers::normalize_keywords( array( ' mower ', 'mower', ' slope ', '' ), 3 );

super_seo_assert_same( 'mower, slope', $keywords, 'Keywords should be trimmed, deduplicated, and limited.' );

$deduped = Super_SEO_Helpers::dedupe_head_seo_tags(
	'<html><head><meta name="description" content="Old"><link rel="canonical" href="https://old.test"><meta name="description" content="New"><link rel="canonical" href="https://new.test"></head><body></body></html>'
);

super_seo_assert_true( false === strpos( $deduped, 'content="Old"' ), 'Head de-dupe should remove earlier meta descriptions.' );
super_seo_assert_true( false !== strpos( $deduped, 'content="New"' ), 'Head de-dupe should keep the latest meta description.' );
super_seo_assert_true( false === strpos( $deduped, 'https://old.test' ), 'Head de-dupe should remove earlier canonicals.' );
super_seo_assert_true( false !== strpos( $deduped, 'https://new.test' ), 'Head de-dupe should keep the latest canonical.' );

$audit = Super_SEO_Audit::audit_html(
	'https://example.test/product/alpha',
	'<html><head><title>Alpha</title><link rel="canonical" href="https://example.test/product/alpha"></head><body><img src="/alpha.jpg"><script src="/theme.js"></script></body></html>'
);

super_seo_assert_true( $audit['score'] < 100, 'Local audit should lower the score for missing SEO/PageSpeed signals.' );
super_seo_assert_same( 'missing_meta_description', $audit['checks'][0]['id'], 'Audit should detect a missing meta description first.' );

$article = array(
	'title'       => 'Remote Control Lawn Mower Buying Guide',
	'content'     => str_repeat( 'Remote control lawn mower slope orchard brush cutter maintenance safety ', 20 ),
	'description' => 'A practical guide for choosing remote control lawn mowers for slopes and orchards.',
	'keywords'    => array( 'remote control lawn mower', 'slope mower' ),
);

$profile = array(
	'core_keywords'       => array( 'remote control lawn mower', 'slope mower', 'orchard mower' ),
	'forbidden_claims'    => array( 'free worldwide shipping' ),
	'unsupported_claims'  => array( 'certified explosion proof' ),
);

$gates = Super_SEO_Automation::validate_article_payload( $article, $profile, 80, 'strict' );

super_seo_assert_true( $gates['passed'], 'Article gate should pass relevant product-focused content.' );

$bad_article            = $article;
$bad_article['content'] = 'Free worldwide shipping for every machine. ' . $bad_article['content'];
$bad_gates              = Super_SEO_Automation::validate_article_payload( $bad_article, $profile, 80, 'strict' );

super_seo_assert_true( ! $bad_gates['passed'], 'Article gate should reject forbidden product claims.' );
super_seo_assert_same( 'forbidden_claim', $bad_gates['errors'][0]['id'], 'Article gate should report forbidden claims with a stable id.' );

$snapshot = Super_SEO_Automation::post_meta_snapshot( 123 );

super_seo_assert_same( 'Old SEO title', $snapshot['title'], 'Rollback snapshot should capture the current SEO title.' );
super_seo_assert_same( 'Old SEO description', $snapshot['description'], 'Rollback snapshot should capture the current SEO description.' );
super_seo_assert_same( 'old keyword', $snapshot['keywords'], 'Rollback snapshot should capture the current focus keywords.' );

echo "All tests passed.\n";
