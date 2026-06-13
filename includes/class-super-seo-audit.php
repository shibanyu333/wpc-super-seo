<?php
/**
 * Local SEO and PageSpeed heuristic audit.
 *
 * @package SuperSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scans local pages for SEO/PageSpeed signals that Super SEO can improve.
 */
final class Super_SEO_Audit {
	/**
	 * Cached report transient.
	 */
	const TRANSIENT_KEY = 'super_seo_local_audit_results';

	/**
	 * Main plugin instance.
	 *
	 * @var Super_SEO
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Super_SEO $plugin Plugin.
	 */
	public function __construct( Super_SEO $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Returns the latest cached report.
	 *
	 * @return array
	 */
	public function cached_report() {
		$report = get_transient( self::TRANSIENT_KEY );

		return is_array( $report ) ? $report : array();
	}

	/**
	 * Runs a local audit for configured and representative URLs.
	 *
	 * @param array $urls Optional URLs.
	 * @return array|WP_Error
	 */
	public function run( array $urls = array() ) {
		$urls = empty( $urls ) ? $this->default_urls() : $urls;
		$urls = $this->normalize_urls( $urls );

		if ( is_wp_error( $urls ) ) {
			return $urls;
		}

		if ( empty( $urls ) ) {
			return new WP_Error( 'super_seo_audit_no_urls', '没有可检测的站内 URL。' );
		}

		$results = array();

		foreach ( array_slice( $urls, 0, 20 ) as $url ) {
			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'     => 20,
					'redirection' => 3,
					'headers'     => array(
						'Accept' => 'text/html,application/xhtml+xml',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				$results[] = array(
					'url'    => $url,
					'score'  => 0,
					'checks' => array(
						self::check( 'fetch_failed', 'theme/manual', '无法抓取页面进行检测：' . $response->get_error_message(), 'high' ),
					),
				);
				continue;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$html   = (string) wp_remote_retrieve_body( $response );

			if ( $status < 200 || $status >= 400 || '' === trim( $html ) ) {
				$results[] = array(
					'url'    => $url,
					'score'  => 0,
					'checks' => array(
						self::check( 'bad_http_status', 'theme/manual', sprintf( '页面返回 HTTP %d，无法用于 SEO/PageSpeed 检测。', $status ), 'high' ),
					),
				);
				continue;
			}

			$results[] = self::audit_html( $url, $html );
		}

		$results[] = $this->audit_site_config();

		$report = array(
			'generated_at' => time(),
			'mode'         => $this->plugin->setting( 'pagespeed_mode', 'balanced' ),
			'summary'      => $this->summarize( $results ),
			'results'      => $results,
		);

		set_transient( self::TRANSIENT_KEY, $report, 6 * HOUR_IN_SECONDS );

		return $report;
	}

	/**
	 * Audits a single HTML document.
	 *
	 * @param string $url  URL.
	 * @param string $html HTML.
	 * @return array
	 */
	public static function audit_html( $url, $html ) {
		$html   = (string) $html;
		$checks = array();
		$title  = self::first_match( '/<title\b[^>]*>(.*?)<\/title>/is', $html );

		if ( ! preg_match( '/<meta\b[^>]*\bname=["\']description["\'][^>]*\bcontent=["\'][^"\']{50,180}["\'][^>]*>/i', $html ) ) {
			$checks[] = self::check( 'missing_meta_description', 'auto-fixable', '缺少有效 meta description，或长度明显不足。', 'high' );
		}

		if ( '' === Super_SEO_Helpers::clean_text( $title ) || self::text_length( $title ) < 15 || self::text_length( $title ) > 80 ) {
			$checks[] = self::check( 'weak_title', 'needs review', '页面标题为空、过短或过长。', 'medium' );
		}

		if ( ! preg_match( '/<link\b[^>]*\brel=["\']canonical["\'][^>]*>/i', $html ) ) {
			$checks[] = self::check( 'missing_canonical', 'auto-fixable', '缺少 canonical URL。', 'medium' );
		}

		if ( ! preg_match( '/<meta\b[^>]*\bproperty=["\']og:title["\'][^>]*>/i', $html ) ) {
			$checks[] = self::check( 'missing_og_title', 'auto-fixable', '缺少 Open Graph 标题。', 'low' );
		}

		if ( ! preg_match( '/<script\b[^>]*type=["\']application\/ld\+json["\'][^>]*>/i', $html ) ) {
			$checks[] = self::check( 'missing_schema', 'auto-fixable', '缺少 JSON-LD 结构化数据。', 'medium' );
		}

		$image_tags = self::tags( 'img', $html );
		$first_lcp  = '';

		foreach ( $image_tags as $index => $tag ) {
			if ( '' === $first_lcp && ! preg_match( '/logo|avatar|icon|sprite|placeholder|tracking|pixel|spinner|related|sidebar|thumbnail|thumb|payment|social/i', $tag ) ) {
				$first_lcp = $tag;
			}

			if ( ! preg_match( '/\balt=["\'][^"\']+["\']/i', $tag ) ) {
				$checks[] = self::check( 'image_missing_alt', 'auto-fixable', '图片缺少 alt 文本。', 'medium', array( 'index' => $index + 1 ) );
				break;
			}
		}

		foreach ( $image_tags as $tag ) {
			if ( ! preg_match( '/\bloading=["\'](?:lazy|eager)["\']/i', $tag ) || ! preg_match( '/\bdecoding=["\']async["\']/i', $tag ) ) {
				$checks[] = self::check( 'image_lazy_missing', 'auto-fixable', '图片缺少 loading/decoding 加载属性。', 'medium' );
				break;
			}
		}

		foreach ( $image_tags as $tag ) {
			if ( ! preg_match( '/\bwidth=["\']?\d+/i', $tag ) || ! preg_match( '/\bheight=["\']?\d+/i', $tag ) ) {
				$checks[] = self::check( 'image_dimensions_missing', 'theme/manual', '图片缺少 width/height，可能导致 CLS。', 'medium' );
				break;
			}
		}

		if ( '' !== $first_lcp && ! preg_match( '/\bfetchpriority=["\']high["\']|\bloading=["\']eager["\']/i', $first_lcp ) && ! preg_match( '/<link\b[^>]*\brel=["\']preload["\'][^>]*\bas=["\']image["\']/i', $html ) ) {
			$checks[] = self::check( 'lcp_priority_missing', 'auto-fixable', '首张主要图片没有 preload、eager 或 fetchpriority=high。', 'medium' );
		}

		foreach ( self::tags( 'script', $html ) as $tag ) {
			if ( preg_match( '/\bsrc=["\'][^"\']+["\']/i', $tag ) && ! preg_match( '/\b(?:defer|async|type=["\']module["\'])/i', $tag ) && ! preg_match( '/jquery|woocommerce|cart-fragments|wp-polyfill/i', $tag ) ) {
				$checks[] = self::check( 'render_blocking_script', 'needs review', '发现可能阻塞渲染的前台脚本。', 'medium' );
				break;
			}
		}

		if ( false !== strpos( $html, '<!-- wp:' ) || preg_match( '/>\s{2,}</', $html ) ) {
			$checks[] = self::check( 'html_minification_opportunity', 'needs review', 'HTML 存在可压缩空间。', 'low' );
		}

		return array(
			'url'    => (string) $url,
			'score'  => self::score( $checks ),
			'checks' => $checks,
		);
	}

	/**
	 * Builds default URLs from home, WooCommerce products/categories and settings.
	 *
	 * @return array
	 */
	private function default_urls() {
		$urls = array( home_url( '/' ) );
		$raw  = (string) $this->plugin->setting( 'audit_urls', '' );

		if ( '' !== trim( $raw ) ) {
			$urls = array_merge( $urls, preg_split( '/[\r\n,，]+/u', $raw ) );
		}

		if ( post_type_exists( 'product' ) ) {
			$product_ids = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => 5,
					'fields'         => 'ids',
					'orderby'        => 'modified',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				)
			);

			foreach ( $product_ids as $product_id ) {
				$urls[] = get_permalink( $product_id );
			}
		}

		if ( taxonomy_exists( 'product_cat' ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => true,
					'number'     => 5,
				)
			);

			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$link = get_term_link( $term );

					if ( ! is_wp_error( $link ) ) {
						$urls[] = $link;
					}
				}
			}
		}

		return $urls;
	}

	/**
	 * Normalizes and restricts URLs to the current site host.
	 *
	 * @param array $urls URLs.
	 * @return array|WP_Error
	 */
	private function normalize_urls( array $urls ) {
		$home_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$clean     = array();

		foreach ( $urls as $url ) {
			$url = Super_SEO_Helpers::absolute_url( $url );

			if ( '' === $url ) {
				continue;
			}

			$url  = esc_url_raw( $url );
			$host = wp_parse_url( $url, PHP_URL_HOST );

			if ( empty( $host ) || strtolower( (string) $host ) !== strtolower( (string) $home_host ) ) {
				return new WP_Error( 'super_seo_audit_external_url', '本地检测只允许抓取当前网站域名下的 URL。' );
			}

			$clean[] = $url;
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Summarizes a report.
	 *
	 * @param array $results Results.
	 * @return array
	 */
	private function summarize( array $results ) {
		$count  = count( $results );
		$scores = array_map(
			static function ( $result ) {
				return isset( $result['score'] ) ? (int) $result['score'] : 0;
			},
			$results
		);

		$checks = array();

		foreach ( $results as $result ) {
			foreach ( $result['checks'] ?? array() as $check ) {
				$type = $check['type'] ?? 'needs review';
				$checks[ $type ] = isset( $checks[ $type ] ) ? $checks[ $type ] + 1 : 1;
			}
		}

		return array(
			'urls'          => $count,
			'average_score' => $count ? (int) round( array_sum( $scores ) / $count ) : 0,
			'checks'        => $checks,
		);
	}

	/**
	 * Audits site-level settings and plugin overlap.
	 *
	 * @return array
	 */
	private function audit_site_config() {
		$checks = array();

		if ( ! $this->plugin->setting( 'robots_enabled', 1 ) ) {
			$checks[] = self::check( 'robots_disabled', 'auto-fixable', 'robots.txt 修复未启用。', 'medium' );
		}

		if ( ! $this->plugin->setting( 'sitemap_enabled', 1 ) ) {
			$checks[] = self::check( 'sitemap_disabled', 'auto-fixable', 'XML 站点地图未启用。', 'medium' );
		}

		if ( defined( 'SUPER_ROCKET_OPTION' ) || defined( 'SUPER_ROCKET_VERSION' ) ) {
			$checks[] = self::check( 'super_rocket_detected', 'needs review', '检测到 Super Rocket，缓存/WebP/JS/HTML 压缩应避免重复开启。', 'low' );
		}

		if ( 'aggressive' === $this->plugin->setting( 'pagespeed_mode', 'balanced' ) ) {
			$checks[] = self::check( 'aggressive_mode_enabled', 'theme/manual', '当前为激进模式，建议上线前人工复测前台交互。', 'low' );
		}

		return array(
			'url'    => home_url( '/' ) . '#super-seo-site-config',
			'score'  => self::score( $checks ),
			'checks' => $checks,
		);
	}

	/**
	 * Builds a stable check object.
	 *
	 * @param string $id       ID.
	 * @param string $type     Type.
	 * @param string $message  Message.
	 * @param string $severity Severity.
	 * @param array  $data     Extra data.
	 * @return array
	 */
	private static function check( $id, $type, $message, $severity = 'medium', array $data = array() ) {
		return array(
			'id'       => $id,
			'type'     => $type,
			'message'  => $message,
			'severity' => $severity,
			'data'     => $data,
		);
	}

	/**
	 * Scores checks from 0 to 100.
	 *
	 * @param array $checks Checks.
	 * @return int
	 */
	private static function score( array $checks ) {
		$deduct = 0;

		foreach ( $checks as $check ) {
			if ( 'high' === $check['severity'] ) {
				$deduct += 14;
			} elseif ( 'medium' === $check['severity'] ) {
				$deduct += 8;
			} else {
				$deduct += 4;
			}
		}

		return max( 0, 100 - $deduct );
	}

	/**
	 * Returns tags of a type.
	 *
	 * @param string $tag  Tag.
	 * @param string $html HTML.
	 * @return array
	 */
	private static function tags( $tag, $html ) {
		if ( ! preg_match_all( '/<' . preg_quote( $tag, '/' ) . '\b[^>]*>/i', $html, $matches ) ) {
			return array();
		}

		return $matches[0];
	}

	/**
	 * Returns first regex match.
	 *
	 * @param string $pattern Pattern.
	 * @param string $value   Value.
	 * @return string
	 */
	private static function first_match( $pattern, $value ) {
		return preg_match( $pattern, $value, $matches ) ? html_entity_decode( wp_strip_all_tags( $matches[1] ), ENT_QUOTES, 'UTF-8' ) : '';
	}

	/**
	 * Returns text length.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function text_length( $text ) {
		$text = Super_SEO_Helpers::clean_text( $text );

		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}
}
