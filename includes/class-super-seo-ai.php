<?php
/**
 * OpenAI-compatible AI client.
 *
 * @package SuperSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates SEO metadata through DeepSeek or another compatible model.
 */
final class Super_SEO_AI {
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
	 * Generates metadata for a post.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $extra   Extra context.
	 * @return array|WP_Error
	 */
	public function generate_for_post( $post_id, array $extra = array() ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'super_seo_missing_post', '文章不存在。' );
		}

		$tags = wp_get_post_terms( $post_id, 'post_tag', array( 'fields' => 'names' ) );
		$tags = is_wp_error( $tags ) ? array() : $tags;

		$context = array_merge(
			array(
				'type'          => 'post',
				'title'         => get_the_title( $post ),
				'content'       => Super_SEO_Helpers::excerpt_from_content( $post->post_content, 1200 ),
				'url'           => get_permalink( $post ),
				'post_type'     => $post->post_type,
				'existing_tags' => $tags,
			),
			$extra
		);

		return $this->generate( $context );
	}

	/**
	 * Generates metadata for a taxonomy term.
	 *
	 * @param int   $term_id Term ID.
	 * @param array $extra   Extra context.
	 * @return array|WP_Error
	 */
	public function generate_for_term( $term_id, array $extra = array() ) {
		$term = get_term( $term_id );

		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'super_seo_missing_term', '分类不存在。' );
		}

		$context = array_merge(
			array(
				'type'        => 'term',
				'title'       => $term->name,
				'content'     => Super_SEO_Helpers::excerpt_from_content( $term->description, 1000 ),
				'url'         => get_term_link( $term ),
				'taxonomy'    => $term->taxonomy,
				'item_count'  => (int) $term->count,
			),
			$extra
		);

		return $this->generate( $context );
	}

	/**
	 * Generates SEO metadata.
	 *
	 * @param array $context Context.
	 * @return array|WP_Error
	 */
	public function generate( array $context ) {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are an expert SEO editor. Return only valid JSON. Do not include markdown.',
			),
			array(
				'role'    => 'user',
				'content' => $this->build_prompt( $context ),
			),
		);

		$content = $this->request_model( $messages );

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		return $this->parse_result( $content );
	}

	/**
	 * Generates arbitrary structured JSON for trusted internal workflows.
	 *
	 * @param array $context Context.
	 * @return array|WP_Error
	 */
	public function generate_structured( array $context ) {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are an SEO automation assistant. Return only strict valid JSON. Do not include markdown, comments, or unsupported product claims.',
			),
			array(
				'role'    => 'user',
				'content' => $this->build_structured_prompt( $context ),
			),
		);

		$content = $this->request_model( $messages );

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		return $this->parse_structured_result( $content );
	}

	/**
	 * Tests AI connectivity.
	 *
	 * @return array|WP_Error
	 */
	public function test_connection() {
		return $this->generate(
			array(
				'type'    => 'test',
				'title'   => 'Remote Control Lawn Mowers',
				'content' => 'Remote control lawn mowers for slopes, orchards, roadsides and commercial brush work.',
				'url'     => home_url( '/' ),
			)
		);
	}

	/**
	 * Builds the prompt sent to the model.
	 *
	 * @param array $context Context.
	 * @return string
	 */
	private function build_prompt( array $context ) {
		$settings = $this->plugin->settings();
		$brand    = Super_SEO_Helpers::clean_text( $settings['site_brand_name'] );
		$language = Super_SEO_Helpers::clean_text( $settings['ai_language'] );
		$tone     = Super_SEO_Helpers::clean_text( $settings['ai_tone'] );

		return wp_json_encode(
			array(
				'instructions' => array(
					'为页面生成适合 Google 搜索结果展示的 SEO 标题、描述和焦点关键词。',
					'标题控制在 50-65 个字符以内，描述控制在 120-155 个字符以内。',
					'焦点关键词给 5-8 个，用来指导标题、正文、标签和图片 alt，避免关键词堆砌。',
					'不要夸大承诺，不要编造页面没有的信息。',
					'只返回 JSON：{"title":"","description":"","keywords":[""]}',
				),
				'language'     => $language,
				'tone'         => $tone,
				'brand'        => $brand,
				'page'         => $context,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
	}

	/**
	 * Builds a structured automation prompt.
	 *
	 * @param array $context Context.
	 * @return string
	 */
	private function build_structured_prompt( array $context ) {
		$settings = $this->plugin->settings();

		return wp_json_encode(
			array(
				'instructions' => array(
					'根据站内真实产品信息生成结构化 SEO 自动化数据。',
					'不要编造规格、价格、认证、库存、保修、发货承诺或页面不存在的信息。',
					'如果信息不足，把相关表达放入 unsupported_claims 或留空。',
					'只返回 JSON，字段必须与 schema_hint 兼容。',
				),
				'language'     => Super_SEO_Helpers::clean_text( $settings['ai_language'] ),
				'tone'         => Super_SEO_Helpers::clean_text( $settings['ai_tone'] ),
				'brand'        => Super_SEO_Helpers::clean_text( $settings['site_brand_name'] ),
				'task'         => $context,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
	}

	/**
	 * Parses model output into safe fields.
	 *
	 * @param string $content Raw model content.
	 * @return array|WP_Error
	 */
	private function parse_result( $content ) {
		$content = trim( (string) $content );
		$content = preg_replace( '/^```(?:json)?\s*/i', '', $content );
		$content = preg_replace( '/\s*```$/', '', $content );

		if ( preg_match( '/\{.*\}/s', $content, $matches ) ) {
			$content = $matches[0];
		}

		$data = json_decode( $content, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'super_seo_ai_parse_failed', 'AI 返回格式无法解析，请重试或降低模型温度。' );
		}

		$title       = $this->fit_title( $data['title'] ?? '' );
		$description = $this->fit_description( $data['description'] ?? '' );
		$keywords    = Super_SEO_Helpers::normalize_keywords( $data['keywords'] ?? '', 8 );

		if ( '' === $title || '' === $description ) {
			return new WP_Error( 'super_seo_ai_incomplete', 'AI 返回缺少标题或描述，请重试。' );
		}

		return array(
			'title'       => $title,
			'description' => $description,
			'keywords'    => $keywords,
		);
	}

	/**
	 * Parses a structured AI JSON response.
	 *
	 * @param string $content Raw model content.
	 * @return array|WP_Error
	 */
	private function parse_structured_result( $content ) {
		$content = trim( (string) $content );
		$content = preg_replace( '/^```(?:json)?\s*/i', '', $content );
		$content = preg_replace( '/\s*```$/', '', $content );

		if ( preg_match( '/\{.*\}/s', $content, $matches ) ) {
			$content = $matches[0];
		}

		$data = json_decode( $content, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'super_seo_ai_structured_parse_failed', 'AI 返回的结构化 JSON 无法解析。' );
		}

		return $data;
	}

	/**
	 * Sends messages to the configured OpenAI-compatible endpoint.
	 *
	 * @param array $messages Messages.
	 * @return string|WP_Error
	 */
	private function request_model( array $messages ) {
		$settings = $this->plugin->settings();
		$api_key  = trim( (string) $settings['ai_api_key'] );
		$endpoint = esc_url_raw( $settings['ai_endpoint'] );

		if ( '' === $api_key ) {
			return new WP_Error( 'super_seo_missing_api_key', '请先在“超级SEO > AI大模型配置”里填写 API Key。' );
		}

		if ( '' === $endpoint ) {
			return new WP_Error( 'super_seo_missing_endpoint', 'AI 接口地址不能为空。' );
		}

		$endpoint_error = $this->validate_endpoint( $endpoint );

		if ( is_wp_error( $endpoint_error ) ) {
			return $endpoint_error;
		}

		$response = wp_safe_remote_post(
			$endpoint,
			array(
				'timeout' => 45,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'       => sanitize_text_field( $settings['ai_model'] ),
						'temperature' => (float) $settings['ai_temperature'],
						'messages'    => $messages,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'super_seo_ai_http_error',
				sprintf( 'AI 接口返回错误：HTTP %d，请检查 API Key、额度、模型名称或接口地址。', $status )
			);
		}

		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'super_seo_ai_bad_json', 'AI 接口返回内容不是有效 JSON。' );
		}

		$content = $data['choices'][0]['message']['content'] ?? '';

		if ( '' === $content ) {
			return new WP_Error( 'super_seo_ai_empty', 'AI 没有返回可用内容。' );
		}

		return $content;
	}

	/**
	 * Validates AI endpoint before sending an API key.
	 *
	 * @param string $endpoint Endpoint URL.
	 * @return true|WP_Error
	 */
	private function validate_endpoint( $endpoint ) {
		$scheme = wp_parse_url( $endpoint, PHP_URL_SCHEME );
		$host   = wp_parse_url( $endpoint, PHP_URL_HOST );

		if ( empty( $scheme ) || empty( $host ) ) {
			return new WP_Error( 'super_seo_ai_bad_endpoint', 'AI 接口地址格式无效。' );
		}

		if ( 'https' !== strtolower( $scheme ) ) {
			return new WP_Error( 'super_seo_ai_insecure_endpoint', 'AI 接口必须使用 HTTPS，避免 API Key 明文传输。' );
		}

		return true;
	}

	/**
	 * Keeps AI titles readable instead of blindly cutting the brand suffix.
	 *
	 * @param string $title Raw title.
	 * @return string
	 */
	private function fit_title( $title ) {
		$title = Super_SEO_Helpers::clean_text( $title );

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $title, 'UTF-8' ) <= 65 ) {
			return $title;
		}

		if ( ! function_exists( 'mb_strlen' ) && strlen( $title ) <= 65 ) {
			return $title;
		}

		$parts = preg_split( '/\s*[|｜]\s*|\s+[-–—]\s+/u', $title );

		if ( is_array( $parts ) && count( $parts ) > 1 ) {
			array_pop( $parts );
			$without_suffix = trim( implode( ' - ', $parts ) );

			if ( function_exists( 'mb_strlen' ) && mb_strlen( $without_suffix, 'UTF-8' ) >= 25 && mb_strlen( $without_suffix, 'UTF-8' ) <= 65 ) {
				return $without_suffix;
			}

			if ( ! function_exists( 'mb_strlen' ) && strlen( $without_suffix ) >= 25 && strlen( $without_suffix ) <= 65 ) {
				return $without_suffix;
			}
		}

		return Super_SEO_Helpers::limit_text( $title, 65 );
	}

	/**
	 * Keeps descriptions inside a SERP-friendly length with complete sentence endings.
	 *
	 * @param string $description Raw description.
	 * @return string
	 */
	private function fit_description( $description ) {
		$description = Super_SEO_Helpers::clean_text( $description );

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $description, 'UTF-8' ) <= 155 ) {
			return $description;
		}

		if ( ! function_exists( 'mb_strlen' ) && strlen( $description ) <= 155 ) {
			return $description;
		}

		$short = function_exists( 'mb_substr' ) ? mb_substr( $description, 0, 155, 'UTF-8' ) : substr( $description, 0, 155 );

		if ( preg_match( '/^(.{90,155}[.!?。！？])/u', $short, $matches ) ) {
			return trim( $matches[1] );
		}

		return Super_SEO_Helpers::limit_text( $description, 155 );
	}
}
