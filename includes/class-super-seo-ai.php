<?php
/**
 * Multi-provider AI client.
 *
 * Supports two wire formats:
 *  - "openai"    : POST /chat/completions  (DeepSeek, OpenAI, Kimi/Moonshot, Qwen/DashScope, 自定义)
 *  - "anthropic" : POST /v1/messages       (Claude)
 *
 * @package SuperSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates SEO metadata and image descriptions through a configurable model.
 */
final class Super_SEO_AI {
	/**
	 * Anthropic API version header value.
	 */
	const ANTHROPIC_VERSION = '2023-06-01';

	/**
	 * Main plugin instance.
	 *
	 * @var Super_SEO
	 */
	private $plugin;

	/**
	 * Token usage reported by the most recent successful request.
	 *
	 * @var array
	 */
	private $last_usage = array();

	/**
	 * Constructor.
	 *
	 * @param Super_SEO $plugin Plugin.
	 */
	public function __construct( Super_SEO $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Token usage from the last successful call, normalized across providers.
	 *
	 * @return array {input, output}
	 */
	public function last_usage() {
		return $this->last_usage;
	}

	/**
	 * Whether an error is worth retrying later rather than recording as final.
	 *
	 * Rate limits, overloads and network blips are temporary: treating them as
	 * permanent failures would silently drop those images from the queue for
	 * good, because processed images are excluded by their stored record.
	 *
	 * @param WP_Error $error Error.
	 * @return bool
	 */
	public static function is_retryable_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$code = $error->get_error_code();

		// Connection level: timeouts, DNS, TLS, resets.
		if ( in_array( $code, array( 'http_request_failed', 'http_failure' ), true ) ) {
			return true;
		}

		// A single malformed reply is usually a one-off; the next call may parse.
		if ( in_array(
			$code,
			array(
				'super_seo_ai_empty',
				'super_seo_ai_bad_json',
				'super_seo_vision_parse_failed',
				'super_seo_ai_parse_failed',
			),
			true
		) ) {
			return true;
		}

		if ( 'super_seo_ai_http_error' === $code ) {
			$status = (int) ( $error->get_error_data()['status'] ?? 0 );

			return in_array( $status, array( 408, 409, 425, 429, 500, 502, 503, 504, 529 ), true );
		}

		return false;
	}

	/**
	 * Known providers and their defaults.
	 *
	 * `vision` marks providers whose flagship model can read images.
	 * `temperature` marks providers that still accept a temperature parameter —
	 * Claude 5 / GPT-5 class models reject it, so we simply omit it there.
	 *
	 * @return array
	 */
	public static function providers() {
		return array(
			'anthropic' => array(
				'label'       => 'Claude（Anthropic）',
				'endpoint'    => 'https://api.anthropic.com/v1/messages',
				'model'       => 'claude-opus-5',
				'vision_model'=> 'claude-opus-5',
				'wire'        => 'anthropic',
				'vision'      => true,
				'temperature' => false,
				'note'        => '视觉能力最强，图片 alt 首选。批量跑图可换成 claude-haiku-4-5 省钱。',
			),
			'openai'    => array(
				'label'       => 'OpenAI GPT',
				'endpoint'    => 'https://api.openai.com/v1/chat/completions',
				'model'       => 'gpt-4o',
				'vision_model'=> 'gpt-4o',
				'wire'        => 'openai',
				'vision'      => true,
				'temperature' => false,
				'note'        => '通用视觉模型，国内需要自备代理地址。',
			),
			'moonshot'  => array(
				'label'       => 'Kimi（月之暗面）',
				'endpoint'    => 'https://api.moonshot.cn/v1/chat/completions',
				'model'       => 'kimi-latest',
				'vision_model'=> 'kimi-latest',
				'wire'        => 'openai',
				'vision'      => true,
				'temperature' => true,
				'note'        => '国内直连，中文图片描述表现不错。',
			),
			'dashscope' => array(
				'label'       => 'Qwen 通义千问（阿里云）',
				'endpoint'    => 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions',
				'model'       => 'qwen-plus',
				'vision_model'=> 'qwen-vl-max',
				'wire'        => 'openai',
				'vision'      => true,
				'temperature' => true,
				'note'        => '国内直连、价格低。图片必须用 qwen-vl 系列模型。',
			),
			'deepseek'  => array(
				'label'       => 'DeepSeek',
				'endpoint'    => 'https://api.deepseek.com/chat/completions',
				'model'       => 'deepseek-chat',
				'vision_model'=> '',
				'wire'        => 'openai',
				'vision'      => false,
				'temperature' => true,
				'note'        => '便宜好用，但不支持读图，图片 alt 请另配一个视觉服务商。',
			),
			'custom'    => array(
				'label'       => '自定义（OpenAI 兼容）',
				'endpoint'    => '',
				'model'       => '',
				'vision_model'=> '',
				'wire'        => 'openai',
				'vision'      => true,
				'temperature' => true,
				'note'        => '任何兼容 /chat/completions 的接口，图片走 image_url + base64。',
			),
		);
	}

	/**
	 * Returns a single provider definition.
	 *
	 * @param string $key Provider key.
	 * @return array
	 */
	public static function provider( $key ) {
		$providers = self::providers();
		$key       = self::normalize_provider_key( $key );

		return isset( $providers[ $key ] ) ? $providers[ $key ] : $providers['custom'];
	}

	/**
	 * Maps legacy provider keys onto the current registry.
	 *
	 * @param string $key Stored provider key.
	 * @return string
	 */
	public static function normalize_provider_key( $key ) {
		$key     = sanitize_key( $key );
		$aliases = array(
			'openai-compatible' => 'custom',
			'openai_compatible' => 'custom',
			'kimi'              => 'moonshot',
			'qwen'              => 'dashscope',
			'claude'            => 'anthropic',
		);

		if ( isset( $aliases[ $key ] ) ) {
			return $aliases[ $key ];
		}

		return array_key_exists( $key, self::providers() ) ? $key : 'custom';
	}

	/**
	 * Providers usable for image understanding.
	 *
	 * @return array
	 */
	public static function vision_providers() {
		return array_filter(
			self::providers(),
			static function ( $provider ) {
				return ! empty( $provider['vision'] );
			}
		);
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
				'type'       => 'term',
				'title'      => $term->name,
				'content'    => Super_SEO_Helpers::excerpt_from_content( $term->description, 1000 ),
				'url'        => get_term_link( $term ),
				'taxonomy'   => $term->taxonomy,
				'item_count' => (int) $term->count,
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
		$content = $this->request(
			'text',
			'You are an expert SEO editor. Return only valid JSON. Do not include markdown.',
			array(
				array(
					'role'    => 'user',
					'content' => $this->build_prompt( $context ),
				),
			)
		);

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
		$content = $this->request(
			'text',
			'You are an SEO automation assistant. Return only strict valid JSON. Do not include markdown, comments, or unsupported product claims.',
			array(
				array(
					'role'    => 'user',
					'content' => $this->build_structured_prompt( $context ),
				),
			)
		);

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		return $this->parse_structured_result( $content );
	}

	/**
	 * Describes an image with the configured vision model.
	 *
	 * @param array $image   Image payload: base64 data + mime type.
	 * @param array $context Extra page/product context.
	 * @return array|WP_Error
	 */
	public function describe_image( array $image, array $context = array() ) {
		if ( empty( $image['data'] ) || empty( $image['mime'] ) ) {
			return new WP_Error( 'super_seo_vision_no_image', '没有可用的图片数据。' );
		}

		$profile = $this->channel_profile( 'vision' );

		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$prompt   = $this->build_vision_prompt( $context );
		$messages = array(
			array(
				'role'    => 'user',
				'content' => $this->vision_user_content( $profile['wire'], $prompt, $image ),
			),
		);

		$content = $this->request(
			'vision',
			'You are a senior e-commerce content editor writing image accessibility text and SEO alt attributes. Return only valid JSON. Do not include markdown or internal XML tags.',
			$messages,
			array( 'max_tokens' => 1500 )
		);

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		$result = $this->parse_vision_result( $content );

		if ( ! is_wp_error( $result ) ) {
			$result['usage'] = $this->last_usage;
		}

		return $result;
	}

	/**
	 * Tests text connectivity.
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
	 * Returns the resolved configuration for a channel.
	 *
	 * @param string $channel text|vision.
	 * @return array|WP_Error
	 */
	public function channel_profile( $channel ) {
		$settings = $this->plugin->settings();

		if ( 'vision' === $channel ) {
			$provider_key = self::normalize_provider_key( $settings['vision_provider'] );
			$definition   = self::provider( $provider_key );
			$endpoint     = trim( (string) $settings['vision_endpoint'] );
			$model        = trim( (string) $settings['vision_model'] );
			$api_key      = trim( (string) $settings['vision_api_key'] );

			if ( '' === $api_key && $provider_key === sanitize_key( $settings['ai_provider'] ) ) {
				$api_key = trim( (string) $settings['ai_api_key'] );
			}

			if ( '' === $endpoint ) {
				$endpoint = $definition['endpoint'];
			}

			if ( '' === $model ) {
				$model = '' !== $definition['vision_model'] ? $definition['vision_model'] : $definition['model'];
			}

			if ( empty( $definition['vision'] ) ) {
				return new WP_Error(
					'super_seo_vision_unsupported',
					sprintf( '%s 不支持读图，请在“图片 ALT 智能识别”里换一个支持视觉的服务商。', $definition['label'] )
				);
			}
		} else {
			$provider_key = self::normalize_provider_key( $settings['ai_provider'] );
			$definition   = self::provider( $provider_key );
			$endpoint     = trim( (string) $settings['ai_endpoint'] );
			$model        = trim( (string) $settings['ai_model'] );
			$api_key      = trim( (string) $settings['ai_api_key'] );

			if ( '' === $endpoint ) {
				$endpoint = $definition['endpoint'];
			}

			if ( '' === $model ) {
				$model = $definition['model'];
			}
		}

		if ( '' === $api_key ) {
			return new WP_Error( 'super_seo_missing_api_key', '请先在“超级SEO”设置里填写对应的 API Key。' );
		}

		if ( '' === $endpoint ) {
			return new WP_Error( 'super_seo_missing_endpoint', 'AI 接口地址不能为空。' );
		}

		$endpoint = esc_url_raw( $endpoint );
		$valid    = $this->validate_endpoint( $endpoint );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		return array(
			'provider'    => $provider_key,
			'label'       => $definition['label'],
			'endpoint'    => $endpoint,
			'model'       => sanitize_text_field( $model ),
			'api_key'     => $api_key,
			'wire'        => $definition['wire'],
			'temperature' => ! empty( $definition['temperature'] ),
		);
	}

	/**
	 * Builds the SEO prompt sent to the model.
	 *
	 * @param array $context Context.
	 * @return string
	 */
	private function build_prompt( array $context ) {
		$settings = $this->plugin->settings();

		return wp_json_encode(
			array(
				'instructions' => array(
					'为页面生成适合 Google 搜索结果展示的 SEO 标题、描述和焦点关键词。',
					'标题控制在 50-65 个字符以内，描述控制在 120-155 个字符以内。',
					'焦点关键词给 5-8 个，用来指导标题、正文、标签和图片 alt，避免关键词堆砌。',
					'不要夸大承诺，不要编造页面没有的信息。',
					'只返回 JSON：{"title":"","description":"","keywords":[""]}',
				),
				'language'     => Super_SEO_Helpers::clean_text( $settings['ai_language'] ),
				'tone'         => Super_SEO_Helpers::clean_text( $settings['ai_tone'] ),
				'brand'        => Super_SEO_Helpers::clean_text( $settings['site_brand_name'] ),
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
	 * Builds the image description prompt.
	 *
	 * @param array $context Context.
	 * @return string
	 */
	private function build_vision_prompt( array $context ) {
		$settings = $this->plugin->settings();
		$language = Super_SEO_Helpers::clean_text( $settings['vision_language'] );

		// Alt text is read by visitors of *this* site, so the site locale is the
		// right default — not the language chosen for AI copywriting. Falling
		// back to ai_language wrote Chinese alt onto English sites.
		if ( '' === $language ) {
			$language = self::site_language();
		}

		return wp_json_encode(
			array(
				'instructions' => array(
					'先看图，再写图片的替代文本（alt）。',
					'alt 必须描述图片里真实可见的内容，控制在 125 个字符以内，读起来像人话。',
					'不要以“图片”“照片”“一张”开头，不要写“图中显示”。',
					'如果图片主体和站点主题相关，可以自然带上 1 个焦点关键词，禁止堆砌。',
					'title 是简短标题（60 字符内）；caption 是一句话说明（120 字符内），没有把握就留空。',
					'绝对不要编造型号、参数、材质、尺寸、认证、品牌或价格——看不清就用概括性描述。',
					'装饰性图片（纯背景、分隔线、图标）把 decorative 设为 true，alt 留空。',
					'只返回 JSON：{"alt":"","title":"","caption":"","keywords":[""],"decorative":false}',
				),
				'language'     => '' !== $language ? $language : 'zh-CN',
				'brand'        => Super_SEO_Helpers::clean_text( $settings['site_brand_name'] ),
				'page_context' => $context,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
	}

	/**
	 * Builds the multimodal user content block for the given wire format.
	 *
	 * @param string $wire   openai|anthropic.
	 * @param string $prompt Text prompt.
	 * @param array  $image  Image payload.
	 * @return array
	 */
	private function vision_user_content( $wire, $prompt, array $image ) {
		if ( 'anthropic' === $wire ) {
			// Anthropic recommends the image block before the text block.
			return array(
				array(
					'type'   => 'image',
					'source' => array(
						'type'       => 'base64',
						'media_type' => $image['mime'],
						'data'       => $image['data'],
					),
				),
				array(
					'type' => 'text',
					'text' => $prompt,
				),
			);
		}

		return array(
			array(
				'type'      => 'image_url',
				'image_url' => array(
					'url' => 'data:' . $image['mime'] . ';base64,' . $image['data'],
				),
			),
			array(
				'type' => 'text',
				'text' => $prompt,
			),
		);
	}

	/**
	 * Parses model output into safe SEO fields.
	 *
	 * @param string $content Raw model content.
	 * @return array|WP_Error
	 */
	private function parse_result( $content ) {
		$data = self::decode_json_payload( $content );

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
		$data = self::decode_json_payload( $content );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'super_seo_ai_structured_parse_failed', 'AI 返回的结构化 JSON 无法解析。' );
		}

		return $data;
	}

	/**
	 * Parses and sanitizes an image description response.
	 *
	 * @param string $content Raw model content.
	 * @return array|WP_Error
	 */
	private function parse_vision_result( $content ) {
		$data = self::decode_json_payload( $content );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'super_seo_vision_parse_failed', 'AI 返回的图片描述无法解析，请重试。' );
		}

		return self::sanitize_vision_result( $data );
	}

	/**
	 * Normalizes a raw vision payload into storable fields.
	 *
	 * Public and static so the regression tests can exercise it without WordPress.
	 *
	 * @param array $data Raw decoded payload.
	 * @return array
	 */
	public static function sanitize_vision_result( array $data ) {
		$decorative = ! empty( $data['decorative'] ) && 'false' !== $data['decorative'];
		$alt        = Super_SEO_Helpers::limit_text( $data['alt'] ?? '', 125 );

		// Strip the openers models keep reaching for even when told not to.
		$alt = preg_replace( '/^(图片[:：]?|照片[:：]?|一张|这是一张|图中显示了?|图为)\s*/u', '', $alt );
		$alt = is_string( $alt ) ? trim( $alt ) : '';

		return array(
			'alt'        => $decorative ? '' : $alt,
			'title'      => Super_SEO_Helpers::limit_text( $data['title'] ?? '', 60 ),
			'caption'    => Super_SEO_Helpers::limit_text( $data['caption'] ?? '', 120 ),
			'keywords'   => Super_SEO_Helpers::normalize_keywords( $data['keywords'] ?? '', 6 ),
			'decorative' => $decorative,
		);
	}

	/**
	 * Extracts a JSON object from a model response that may carry fences or prose.
	 *
	 * @param string $content Raw content.
	 * @return array|null
	 */
	public static function decode_json_payload( $content ) {
		$content = trim( (string) $content );
		$content = preg_replace( '/^```(?:json)?\s*/i', '', $content );
		$content = preg_replace( '/\s*```$/', '', (string) $content );
		$content = (string) $content;

		// Some models still leak reasoning tags around the payload.
		$stripped = preg_replace( '#<(thinking|thought|reasoning)\b[^>]*>.*?</\1>#is', '', $content );

		if ( null !== $stripped ) {
			$content = $stripped;
		}

		if ( preg_match( '/\{.*\}/s', $content, $matches ) ) {
			$content = $matches[0];
		}

		$data = json_decode( $content, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Sends a request to the configured provider for a channel.
	 *
	 * @param string $channel  text|vision.
	 * @param string $system   System prompt.
	 * @param array  $messages Chat messages (user/assistant only).
	 * @param array  $args     Optional overrides (max_tokens, timeout).
	 * @return string|WP_Error
	 */
	private function request( $channel, $system, array $messages, array $args = array() ) {
		$this->last_usage = array();

		$profile = $this->channel_profile( $channel );

		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$args = wp_parse_args(
			$args,
			array(
				'max_tokens' => 1200,
				'timeout'    => 'vision' === $channel ? 90 : 45,
			)
		);

		if ( 'anthropic' === $profile['wire'] ) {
			return $this->request_anthropic( $profile, $system, $messages, $args );
		}

		return $this->request_openai( $profile, $system, $messages, $args );
	}

	/**
	 * OpenAI-compatible /chat/completions transport.
	 *
	 * @param array  $profile  Channel profile.
	 * @param string $system   System prompt.
	 * @param array  $messages Messages.
	 * @param array  $args     Args.
	 * @return string|WP_Error
	 */
	private function request_openai( array $profile, $system, array $messages, array $args ) {
		$settings = $this->plugin->settings();
		$payload  = array(
			'model'      => $profile['model'],
			'max_tokens' => (int) $args['max_tokens'],
			'messages'   => array_merge(
				array(
					array(
						'role'    => 'system',
						'content' => $system,
					),
				),
				$messages
			),
		);

		if ( $profile['temperature'] ) {
			$payload['temperature'] = (float) $settings['ai_temperature'];
		}

		$response = wp_safe_remote_post(
			$profile['endpoint'],
			array(
				'timeout' => (int) $args['timeout'],
				'headers' => array(
					'Authorization' => 'Bearer ' . $profile['api_key'],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		$data = $this->decode_http_response( $response, $profile );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$content = $data['choices'][0]['message']['content'] ?? '';

		if ( is_array( $content ) ) {
			$content = $this->flatten_content_blocks( $content );
		}

		if ( '' === trim( (string) $content ) ) {
			return new WP_Error( 'super_seo_ai_empty', 'AI 没有返回可用内容。' );
		}

		return (string) $content;
	}

	/**
	 * Anthropic Messages API transport.
	 *
	 * @param array  $profile  Channel profile.
	 * @param string $system   System prompt.
	 * @param array  $messages Messages.
	 * @param array  $args     Args.
	 * @return string|WP_Error
	 */
	private function request_anthropic( array $profile, $system, array $messages, array $args ) {
		// Claude 5 / Opus 4.7+ reject temperature, so it is never sent.
		// max_tokens covers thinking + text, so keep some headroom.
		$payload = array(
			'model'      => $profile['model'],
			'max_tokens' => max( 1024, (int) $args['max_tokens'] ),
			'system'     => $system,
			'messages'   => $messages,
		);

		$response = wp_safe_remote_post(
			$profile['endpoint'],
			array(
				'timeout' => (int) $args['timeout'],
				'headers' => array(
					'x-api-key'         => $profile['api_key'],
					'anthropic-version' => self::ANTHROPIC_VERSION,
					'Content-Type'      => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		$data = $this->decode_http_response( $response, $profile );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( isset( $data['stop_reason'] ) && 'refusal' === $data['stop_reason'] ) {
			return new WP_Error( 'super_seo_ai_refused', 'Claude 拒绝处理该内容，请换一张图片或调整描述要求。' );
		}

		$content = $this->flatten_content_blocks( $data['content'] ?? array() );

		if ( '' === trim( $content ) ) {
			return new WP_Error( 'super_seo_ai_empty', 'AI 没有返回可用内容。' );
		}

		return $content;
	}

	/**
	 * Joins text blocks from a block-style response, ignoring thinking blocks.
	 *
	 * @param mixed $blocks Content blocks.
	 * @return string
	 */
	private function flatten_content_blocks( $blocks ) {
		if ( is_string( $blocks ) ) {
			return $blocks;
		}

		if ( ! is_array( $blocks ) ) {
			return '';
		}

		$parts = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$type = $block['type'] ?? '';

			if ( 'text' === $type && isset( $block['text'] ) ) {
				$parts[] = (string) $block['text'];
			}
		}

		return trim( implode( "\n", $parts ) );
	}

	/**
	 * Shared HTTP response handling.
	 *
	 * @param array|WP_Error $response Response.
	 * @param array          $profile  Channel profile.
	 * @return array|WP_Error
	 */
	private function decode_http_response( $response, array $profile ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( $status < 200 || $status >= 300 ) {
			$detail = '';

			if ( is_array( $data ) ) {
				$detail = $data['error']['message'] ?? ( $data['message'] ?? '' );
			}

			return new WP_Error(
				'super_seo_ai_http_error',
				$this->http_error_message( $profile, $status, $detail ),
				array(
					'status' => $status,
					'detail' => $detail,
				)
			);
		}

		$this->last_usage = $this->normalize_usage( $data['usage'] ?? array() );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'super_seo_ai_bad_json', 'AI 接口返回内容不是有效 JSON。' );
		}

		return $data;
	}

	/**
	 * Turns an HTTP status into advice a non-technical user can act on.
	 *
	 * @param array  $profile Channel profile.
	 * @param int    $status  HTTP status.
	 * @param string $detail  Provider message.
	 * @return string
	 */
	private function http_error_message( array $profile, $status, $detail ) {
		$detail = '' !== $detail ? '（' . Super_SEO_Helpers::limit_text( $detail, 140 ) . '）' : '';

		switch ( true ) {
			case 401 === $status || 403 === $status:
				$advice = 'API Key 无效或没有权限，请检查密钥是否填写正确、是否已过期。';
				break;
			case 402 === $status:
				$advice = '账户余额不足，请先充值。';
				break;
			case 404 === $status:
				$advice = '接口地址或模型名称不存在，请检查这两项。';
				break;
			case 413 === $status:
				$advice = '图片体积超出接口限制，请把「送去识别的图片边长」调小。';
				break;
			case 429 === $status:
				$advice = '请求过于频繁被限流。等几分钟再继续，或把「每批处理数量」调小。已处理的图片不会重复消耗。';
				break;
			case 400 === $status:
				$advice = '请求被拒绝。常见原因：模型名称写错，或该模型不支持读图（例如把 qwen-plus 当成视觉模型）。';
				break;
			case $status >= 500:
				$advice = '服务商暂时故障或过载，稍后重试即可，不是你的配置问题。';
				break;
			default:
				$advice = '请检查 API Key、额度、模型名称和接口地址。';
		}

		return sprintf( '%s 接口返回 HTTP %d%s。%s', $profile['label'], $status, $detail, $advice );
	}

	/**
	 * Normalizes token usage across the two wire formats.
	 *
	 * @param array $usage Raw usage object.
	 * @return array
	 */
	private function normalize_usage( $usage ) {
		if ( ! is_array( $usage ) ) {
			return array();
		}

		$input  = (int) ( $usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0 );
		$output = (int) ( $usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0 );

		if ( ! $input && ! $output ) {
			return array();
		}

		return array(
			'input'  => $input,
			'output' => $output,
		);
	}

	/**
	 * Validates an endpoint before sending an API key.
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

		if ( self::text_length( $title ) <= 65 ) {
			return $title;
		}

		$parts = preg_split( '/\s*[|｜]\s*|\s+[-–—]\s+/u', $title );

		if ( is_array( $parts ) && count( $parts ) > 1 ) {
			array_pop( $parts );
			$without_suffix = trim( implode( ' - ', $parts ) );
			$length         = self::text_length( $without_suffix );

			if ( $length >= 25 && $length <= 65 ) {
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

		if ( self::text_length( $description ) <= 155 ) {
			return $description;
		}

		$short = function_exists( 'mb_substr' ) ? mb_substr( $description, 0, 155, 'UTF-8' ) : substr( $description, 0, 155 );

		if ( preg_match( '/^(.{90,155}[.!?。！？])/u', $short, $matches ) ) {
			return trim( $matches[1] );
		}

		return Super_SEO_Helpers::limit_text( $description, 155 );
	}

	/**
	 * The site's own language tag, e.g. "en-US".
	 *
	 * @return string
	 */
	public static function site_language() {
		$locale = function_exists( 'get_locale' ) ? get_locale() : '';

		return '' !== $locale ? str_replace( '_', '-', $locale ) : 'zh-CN';
	}

	/**
	 * Text length helper.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function text_length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text, 'UTF-8' ) : strlen( (string) $text );
	}
}
