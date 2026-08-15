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
require dirname( __DIR__ ) . '/includes/class-super-seo-ai.php';
require dirname( __DIR__ ) . '/includes/class-super-seo-vision.php';

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

// --- AI response parsing ---------------------------------------------------

$fenced = Super_SEO_AI::decode_json_payload( "```json\n{\"title\":\"Alpha\",\"description\":\"Beta\"}\n```" );

super_seo_assert_same( 'Alpha', $fenced['title'], 'Fenced JSON payloads should decode.' );

$with_thinking = Super_SEO_AI::decode_json_payload( "<thinking>let me look at the image</thinking>\n{\"alt\":\"Red mower on a slope\"}" );

super_seo_assert_true( is_array( $with_thinking ), 'Leaked thinking tags must not break JSON decoding.' );
super_seo_assert_same( 'Red mower on a slope', $with_thinking['alt'], 'Payload after a thinking block should still decode.' );

super_seo_assert_true( null === Super_SEO_AI::decode_json_payload( 'sorry, I cannot help' ), 'Non-JSON responses should decode to null.' );

// --- Image description sanitizing ------------------------------------------

$vision = Super_SEO_AI::sanitize_vision_result(
	array(
		'alt'      => '图片：一台遥控割草机正在斜坡上作业',
		'title'    => '遥控割草机',
		'keywords' => array( '遥控割草机', '遥控割草机', '斜坡' ),
	)
);

super_seo_assert_same( '一台遥控割草机正在斜坡上作业', $vision['alt'], 'Alt text should drop the "图片：" opener models keep adding.' );
super_seo_assert_same( '遥控割草机, 斜坡', $vision['keywords'], 'Vision keywords should be deduplicated.' );

$decorative = Super_SEO_AI::sanitize_vision_result(
	array(
		'alt'        => 'A decorative divider line',
		'decorative' => true,
	)
);

super_seo_assert_same( '', $decorative['alt'], 'Decorative images must get an empty alt attribute.' );

$long_alt = Super_SEO_AI::sanitize_vision_result( array( 'alt' => str_repeat( 'a', 400 ) ) );

super_seo_assert_true( mb_strlen( $long_alt['alt'], 'UTF-8' ) <= 125, 'Alt text must stay within 125 characters.' );

// --- Placeholder media titles ----------------------------------------------

super_seo_assert_true( Super_SEO_Vision::is_placeholder_title( 'IMG_4821' ), 'Camera file names count as placeholder titles.' );
super_seo_assert_true( Super_SEO_Vision::is_placeholder_title( '微信图片' ), 'WeChat export names count as placeholder titles.' );
super_seo_assert_true( Super_SEO_Vision::is_placeholder_title( '' ), 'An empty title counts as a placeholder.' );
super_seo_assert_true( ! Super_SEO_Vision::is_placeholder_title( '遥控割草机产品图' ), 'A human-written title must never be overwritten.' );

// --- Retryable vs permanent errors ------------------------------------------
// A rate limit must never be recorded as a permanent failure: processed images
// are excluded from future batches, so that would silently drop them for good.

class Super_SEO_Test_Error {
	private $code;
	private $data;

	public function __construct( $code, $data = null ) {
		$this->code = $code;
		$this->data = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof Super_SEO_Test_Error;
}

$retryable = array(
	'限流 429'      => new Super_SEO_Test_Error( 'super_seo_ai_http_error', array( 'status' => 429 ) ),
	'过载 529'      => new Super_SEO_Test_Error( 'super_seo_ai_http_error', array( 'status' => 529 ) ),
	'网关超时 504'  => new Super_SEO_Test_Error( 'super_seo_ai_http_error', array( 'status' => 504 ) ),
	'服务器错误 500' => new Super_SEO_Test_Error( 'super_seo_ai_http_error', array( 'status' => 500 ) ),
	'网络中断'      => new Super_SEO_Test_Error( 'http_request_failed' ),
	'返回体解析失败' => new Super_SEO_Test_Error( 'super_seo_vision_parse_failed' ),
);

foreach ( $retryable as $label => $error ) {
	super_seo_assert_true( Super_SEO_AI::is_retryable_error( $error ), "{$label} 必须判定为可重试（否则图片会被永久跳过）" );
}

$permanent = array(
	'密钥无效 401'  => new Super_SEO_Test_Error( 'super_seo_ai_http_error', array( 'status' => 401 ) ),
	'参数错误 400'  => new Super_SEO_Test_Error( 'super_seo_ai_http_error', array( 'status' => 400 ) ),
	'不存在 404'    => new Super_SEO_Test_Error( 'super_seo_ai_http_error', array( 'status' => 404 ) ),
	'模型拒绝'      => new Super_SEO_Test_Error( 'super_seo_ai_refused' ),
	'文件格式不支持' => new Super_SEO_Test_Error( 'super_seo_vision_unsupported_file' ),
	'未配置密钥'    => new Super_SEO_Test_Error( 'super_seo_missing_api_key' ),
);

foreach ( $permanent as $label => $error ) {
	super_seo_assert_true( ! Super_SEO_AI::is_retryable_error( $error ), "{$label} 必须判定为永久失败（否则会无限重试）" );
}

super_seo_assert_true( ! Super_SEO_AI::is_retryable_error( null ), 'null 不是错误。' );

echo "All tests passed.\n";
