<?php
/**
 * Admin UI and AJAX handlers.
 *
 * @package SuperSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chinese admin experience for Super SEO.
 */
final class Super_SEO_Admin {
	/**
	 * Main plugin.
	 *
	 * @var Super_SEO
	 */
	private $plugin;

	/**
	 * AI client.
	 *
	 * @var Super_SEO_AI
	 */
	private $ai;

	/**
	 * Constructor.
	 *
	 * @param Super_SEO    $plugin Plugin.
	 * @param Super_SEO_AI $ai     AI client.
	 */
	public function __construct( Super_SEO $plugin, Super_SEO_AI $ai ) {
		$this->plugin = $plugin;
		$this->ai     = $ai;

		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_super_seo_save_settings', array( $this, 'save_settings' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_post_meta' ) );
		add_action( 'wp_ajax_super_seo_generate_meta', array( $this, 'ajax_generate_meta' ) );
		add_action( 'wp_ajax_super_seo_test_ai', array( $this, 'ajax_test_ai' ) );
		add_action( 'init', array( $this, 'register_term_hooks' ), 30 );
	}

	/**
	 * Adds the Chinese menu.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_menu_page(
			'超级SEO智能优化',
			'超级SEO',
			'manage_options',
			'super-seo',
			array( $this, 'render_settings_page' ),
			'dashicons-chart-line',
			58
		);
	}

	/**
	 * Enqueues admin assets only where needed.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$load   = 'toplevel_page_super-seo' === $hook;

		if ( $screen && in_array( $screen->base, array( 'post', 'term' ), true ) ) {
			$load = true;
		}

		if ( ! $load ) {
			return;
		}

		wp_enqueue_style( 'super-seo-admin', SUPER_SEO_URL . 'assets/admin.css', array(), SUPER_SEO_VERSION );
		wp_enqueue_script( 'super-seo-admin', SUPER_SEO_URL . 'assets/admin.js', array( 'jquery' ), SUPER_SEO_VERSION, true );
		wp_localize_script(
			'super-seo-admin',
			'SuperSEO',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'super_seo_admin' ),
				'generating'   => 'AI 正在生成，请稍等...',
				'generated'    => '已生成，可检查后保存。',
				'testing'      => '正在测试 AI 连接...',
				'testSuccess'  => 'AI 连接正常。',
				'errorPrefix'  => '出错：',
			)
		);
	}

	/**
	 * Renders settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings    = $this->plugin->settings();
		$sitemap_url = home_url( '/super-seo-sitemap.xml' );
		$robots_url  = home_url( '/robots.txt' );
		$has_key     = '' !== trim( (string) $settings['ai_api_key'] );
		?>
		<div class="wrap super-seo-wrap">
			<h1>超级SEO智能优化</h1>
			<p class="super-seo-lead">中文化、少配置、给小白客户用的 SEO + PageSpeed 工具。先保存 API Key，再到文章、页面、产品或分类里点“AI一键生成”。</p>

			<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>设置已保存。</p></div>
			<?php endif; ?>

			<div class="super-seo-status-grid">
				<div class="super-seo-status-card">
					<strong>AI 状态</strong>
					<span class="<?php echo $has_key ? 'is-ok' : 'is-warn'; ?>"><?php echo $has_key ? '已配置' : '未填写 API Key'; ?></span>
				</div>
				<div class="super-seo-status-card">
					<strong>Robots</strong>
					<a href="<?php echo esc_url( $robots_url ); ?>" target="_blank" rel="noopener">查看 robots.txt</a>
				</div>
				<div class="super-seo-status-card">
					<strong>Sitemap</strong>
					<a href="<?php echo esc_url( $sitemap_url ); ?>" target="_blank" rel="noopener">查看站点地图</a>
				</div>
				<div class="super-seo-status-card">
					<strong>PageSpeed 报告重点</strong>
					<span>Meta、Robots、图片、阻塞资源、可访问性</span>
				</div>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="super-seo-settings-form">
				<input type="hidden" name="action" value="super_seo_save_settings">
				<?php wp_nonce_field( 'super_seo_save_settings' ); ?>

				<section class="super-seo-card">
					<h2>基础 SEO</h2>
					<?php $this->checkbox_field( 'enabled', '启用插件输出', '关闭后不再输出前台 SEO 标签和性能优化。', $settings ); ?>
					<?php $this->checkbox_field( 'auto_meta_description', '自动补齐 Meta Description', '页面没有手写描述时，自动从正文、分类说明或站点信息生成。', $settings ); ?>
					<?php $this->text_field( 'site_brand_name', '品牌/站点名称', '用于 AI 和结构化数据。', $settings ); ?>
					<?php $this->textarea_field( 'default_description', '默认全站描述', '页面没有内容可提取时使用，建议 120-155 字。', $settings, 3 ); ?>
					<?php $this->textarea_field( 'default_keywords', '默认关键词', '用英文逗号或中文逗号分隔，作为兜底关键词。', $settings, 2 ); ?>
					<?php $this->checkbox_field( 'schema_enabled', '输出结构化数据 Schema', '自动输出 WebSite、Article、Product、CollectionPage 等基础 JSON-LD。', $settings ); ?>
				</section>

				<section class="super-seo-card">
					<h2>AI 大模型配置</h2>
					<div class="super-seo-two-col">
						<?php $this->select_field( 'ai_provider', '模型服务商', array( 'deepseek' => 'DeepSeek', 'openai-compatible' => 'OpenAI 兼容接口', 'custom' => '自定义' ), $settings ); ?>
						<?php $this->text_field( 'ai_model', '模型名称', 'DeepSeek 默认 deepseek-chat。', $settings ); ?>
					</div>
					<?php $this->text_field( 'ai_endpoint', '接口地址', '需兼容 /chat/completions 格式。', $settings ); ?>
					<div class="super-seo-field">
						<label for="super-seo-ai-api-key">API Key</label>
						<input id="super-seo-ai-api-key" type="password" name="ai_api_key" value="" placeholder="<?php echo $has_key ? esc_attr( '已保存，留空表示不修改' ) : esc_attr( 'sk-...' ); ?>" autocomplete="new-password">
						<p>密钥仅保存到本 WordPress 数据库，不会写入插件文件。要清空密钥，请勾选下面选项。</p>
					</div>
					<?php $this->checkbox_field( 'ai_clear_key', '清空已保存 API Key', '保存后删除当前密钥。', array( 'ai_clear_key' => 0 ) ); ?>
					<div class="super-seo-two-col">
						<?php $this->text_field( 'ai_language', '生成语言', '例如 zh-CN、en-US。', $settings ); ?>
						<?php $this->text_field( 'ai_temperature', '创造性温度', '建议 0.2-0.6。', $settings ); ?>
					</div>
					<?php $this->textarea_field( 'ai_tone', '写作语气', '例如：专业、自然、适合 Google SEO。', $settings, 2 ); ?>
					<button type="button" class="button button-secondary super-seo-test-ai">测试 AI 连接</button>
					<span class="super-seo-inline-status" data-super-seo-test-status></span>
				</section>

				<section class="super-seo-card">
					<h2>PageSpeed 优化</h2>
					<?php $this->checkbox_field( 'robots_enabled', '修复并输出有效 robots.txt', '自动加入 Sitemap，并避免 robots.txt 格式错误导致 Lighthouse 扣分。', $settings ); ?>
					<?php $this->checkbox_field( 'sitemap_enabled', '启用 XML 站点地图', '生成 /super-seo-sitemap.xml。', $settings ); ?>
					<?php $this->checkbox_field( 'performance_lazy_images', '图片懒加载与 async 解码', '为媒体库图片补 loading、decoding、alt 等属性。', $settings ); ?>
					<?php $this->checkbox_field( 'performance_fetchpriority', '首张图片高优先级', '首屏图片使用 fetchpriority=high，降低 LCP 风险。', $settings ); ?>
					<?php $this->checkbox_field( 'performance_auto_preload_image', '自动预加载首屏背景图/首图', '对报告里的 CSS 背景大图尤其有用。', $settings ); ?>
					<?php $this->text_field( 'performance_preload_hero_image', '指定首屏大图 URL', '可填写 PageSpeed 报告里的 LCP/首屏图片 URL，留空则自动识别。', $settings ); ?>
					<?php $this->checkbox_field( 'performance_webp_uploads', '新上传图片自动生成 WebP', '依赖服务器 GD/Imagick 支持 WebP。', $settings ); ?>
					<?php $this->checkbox_field( 'performance_webp_rewrite', '优先给支持的浏览器返回 WebP', '有 WebP 文件时自动替换媒体库图片 URL。', $settings ); ?>
					<?php $this->checkbox_field( 'performance_defer_js', '安全延迟非核心 JS', '自动给非排除列表脚本添加 defer，减少渲染阻塞。', $settings ); ?>
					<?php $this->textarea_field( 'performance_defer_exclusions', 'JS 延迟排除列表', '每项用逗号分隔。jQuery、WooCommerce 等默认排除，避免破坏交互。', $settings, 3 ); ?>
					<?php $this->checkbox_field( 'performance_disable_emojis', '关闭 WordPress Emoji 资源', '减少无用脚本和 DNS 请求。', $settings ); ?>
					<?php $this->checkbox_field( 'performance_disable_embeds', '关闭 Embed 资源', '不用文章嵌入预览时建议开启。', $settings ); ?>
					<?php $this->checkbox_field( 'performance_accessibility_fixes', '启用报告专项可访问性补丁', '修复空按钮名称、部分低对比度文本等常见 Lighthouse 扣分。', $settings ); ?>
					<?php $this->checkbox_field( 'performance_security_headers', '输出基础安全响应头', '补充 X-Content-Type-Options、Referrer-Policy 等轻量安全头。', $settings ); ?>
					<?php $this->checkbox_field( 'performance_minify_html', '压缩前台 HTML', '可能与少数主题冲突，建议先在测试环境开启。', $settings ); ?>
				</section>

				<?php submit_button( '保存全部设置', 'primary large' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Saves settings.
	 *
	 * @return void
	 */
	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '没有权限。' );
		}

		check_admin_referer( 'super_seo_save_settings' );

		$old      = $this->plugin->settings();
		$defaults = Super_SEO::default_settings();
		$new      = array();
		$checkbox = array(
			'enabled',
			'auto_meta_description',
			'sitemap_enabled',
			'robots_enabled',
			'schema_enabled',
			'performance_disable_emojis',
			'performance_disable_embeds',
			'performance_defer_js',
			'performance_lazy_images',
			'performance_fetchpriority',
			'performance_auto_preload_image',
			'performance_webp_uploads',
			'performance_webp_rewrite',
			'performance_minify_html',
			'performance_accessibility_fixes',
			'performance_security_headers',
		);

		foreach ( $defaults as $key => $default ) {
			if ( in_array( $key, $checkbox, true ) ) {
				$new[ $key ] = isset( $_POST[ $key ] ) ? 1 : 0;
				continue;
			}

			$new[ $key ] = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default;
		}

		$new['ai_provider']                    = sanitize_key( $new['ai_provider'] );
		$new['ai_endpoint']                    = esc_url_raw( $new['ai_endpoint'] );
		$new['ai_model']                       = sanitize_text_field( $new['ai_model'] );
		$new['ai_temperature']                 = (string) max( 0, min( 1, (float) $new['ai_temperature'] ) );
		$new['ai_language']                    = sanitize_text_field( $new['ai_language'] );
		$new['ai_tone']                        = sanitize_textarea_field( $new['ai_tone'] );
		$new['site_brand_name']                = sanitize_text_field( $new['site_brand_name'] );
		$new['default_description']            = sanitize_textarea_field( $new['default_description'] );
		$new['default_keywords']               = Super_SEO_Helpers::normalize_keywords( sanitize_textarea_field( $new['default_keywords'] ), 12 );
		$new['performance_preload_hero_image'] = esc_url_raw( $new['performance_preload_hero_image'] );
		$new['performance_defer_exclusions']   = sanitize_textarea_field( $new['performance_defer_exclusions'] );

		$posted_key = isset( $_POST['ai_api_key'] ) ? trim( (string) wp_unslash( $_POST['ai_api_key'] ) ) : '';

		if ( isset( $_POST['ai_clear_key'] ) ) {
			$new['ai_api_key'] = '';
		} elseif ( '' !== $posted_key ) {
			$new['ai_api_key'] = sanitize_text_field( $posted_key );
		} else {
			$new['ai_api_key'] = $old['ai_api_key'];
		}

		$this->plugin->update_settings( $new );
		flush_rewrite_rules();

		wp_safe_redirect( add_query_arg( 'settings-updated', '1', admin_url( 'admin.php?page=super-seo' ) ) );
		exit;
	}

	/**
	 * Adds meta boxes to public post types.
	 *
	 * @return void
	 */
	public function add_meta_boxes() {
		foreach ( Super_SEO_Helpers::public_post_types() as $post_type ) {
			add_meta_box(
				'super-seo-meta',
				'超级SEO',
				array( $this, 'render_post_metabox' ),
				$post_type,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Renders post SEO metabox.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public function render_post_metabox( $post ) {
		wp_nonce_field( 'super_seo_save_post_meta', 'super_seo_meta_nonce' );

		$title       = get_post_meta( $post->ID, '_super_seo_title', true );
		$description = get_post_meta( $post->ID, '_super_seo_description', true );
		$keywords    = get_post_meta( $post->ID, '_super_seo_keywords', true );
		?>
		<div class="super-seo-metabox" data-super-seo-box>
			<p class="super-seo-help">留空时插件会自动生成兜底内容；想更精准，可以点击 AI 生成后再微调。</p>
			<?php $this->meta_input( '_super_seo_title', 'SEO标题', $title, '建议 50-65 字符', 'title' ); ?>
			<?php $this->meta_textarea( '_super_seo_description', 'SEO描述', $description, '建议 120-155 字符', 'description' ); ?>
			<?php $this->meta_input( '_super_seo_keywords', 'SEO关键词', $keywords, '用逗号分隔，5-8 个即可', 'keywords' ); ?>
			<button type="button" class="button button-secondary super-seo-ai-button" data-object-type="post" data-object-id="<?php echo esc_attr( $post->ID ); ?>">AI一键生成</button>
			<span class="super-seo-inline-status" data-super-seo-status></span>
		</div>
		<?php
	}

	/**
	 * Saves post meta.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function save_post_meta( $post_id ) {
		if ( ! isset( $_POST['super_seo_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['super_seo_meta_nonce'] ) ), 'super_seo_save_post_meta' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$this->save_meta_value( $post_id, '_super_seo_title', 'text' );
		$this->save_meta_value( $post_id, '_super_seo_description', 'textarea' );
		$this->save_meta_value( $post_id, '_super_seo_keywords', 'keywords' );
		$this->plugin->purge_super_rocket_cache();
	}

	/**
	 * Renders fields when adding a term.
	 *
	 * @return void
	 */
	public function render_term_add_fields() {
		wp_nonce_field( 'super_seo_save_term_meta', 'super_seo_term_nonce' );
		?>
		<div class="form-field super-seo-term-fields" data-super-seo-box>
			<label for="super-seo-term-title">超级SEO标题</label>
			<input id="super-seo-term-title" name="_super_seo_title" type="text" data-super-seo-field="title">
			<p>可留空，插件会自动兜底。</p>
		</div>
		<div class="form-field super-seo-term-fields">
			<label for="super-seo-term-description">超级SEO描述</label>
			<textarea id="super-seo-term-description" name="_super_seo_description" rows="3" data-super-seo-field="description"></textarea>
		</div>
		<div class="form-field super-seo-term-fields">
			<label for="super-seo-term-keywords">超级SEO关键词</label>
			<input id="super-seo-term-keywords" name="_super_seo_keywords" type="text" data-super-seo-field="keywords">
		</div>
		<?php
	}

	/**
	 * Renders fields when editing a term.
	 *
	 * @param WP_Term $term Term.
	 * @return void
	 */
	public function render_term_edit_fields( $term ) {
		wp_nonce_field( 'super_seo_save_term_meta', 'super_seo_term_nonce' );
		$title       = get_term_meta( $term->term_id, '_super_seo_title', true );
		$description = get_term_meta( $term->term_id, '_super_seo_description', true );
		$keywords    = get_term_meta( $term->term_id, '_super_seo_keywords', true );
		?>
		<tr class="form-field super-seo-term-fields" data-super-seo-box>
			<th scope="row"><label for="super-seo-term-title">超级SEO标题</label></th>
			<td><input id="super-seo-term-title" name="_super_seo_title" type="text" value="<?php echo esc_attr( $title ); ?>" data-super-seo-field="title"></td>
		</tr>
		<tr class="form-field super-seo-term-fields">
			<th scope="row"><label for="super-seo-term-description">超级SEO描述</label></th>
			<td><textarea id="super-seo-term-description" name="_super_seo_description" rows="3" data-super-seo-field="description"><?php echo esc_textarea( $description ); ?></textarea></td>
		</tr>
		<tr class="form-field super-seo-term-fields">
			<th scope="row"><label for="super-seo-term-keywords">超级SEO关键词</label></th>
			<td>
				<input id="super-seo-term-keywords" name="_super_seo_keywords" type="text" value="<?php echo esc_attr( $keywords ); ?>" data-super-seo-field="keywords">
				<p class="description">用逗号分隔，5-8 个即可。</p>
				<button type="button" class="button button-secondary super-seo-ai-button" data-object-type="term" data-object-id="<?php echo esc_attr( $term->term_id ); ?>">AI一键生成</button>
				<span class="super-seo-inline-status" data-super-seo-status></span>
			</td>
		</tr>
		<?php
	}

	/**
	 * Saves term meta.
	 *
	 * @param int $term_id Term ID.
	 * @return void
	 */
	public function save_term_meta( $term_id ) {
		if ( ! isset( $_POST['super_seo_term_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['super_seo_term_nonce'] ) ), 'super_seo_save_term_meta' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_categories' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$this->save_term_meta_value( $term_id, '_super_seo_title', 'text' );
		$this->save_term_meta_value( $term_id, '_super_seo_description', 'textarea' );
		$this->save_term_meta_value( $term_id, '_super_seo_keywords', 'keywords' );
		$this->plugin->purge_super_rocket_cache();
	}

	/**
	 * AJAX metadata generation.
	 *
	 * @return void
	 */
	public function ajax_generate_meta() {
		check_ajax_referer( 'super_seo_admin', 'nonce' );

		$type = isset( $_POST['object_type'] ) ? sanitize_key( wp_unslash( $_POST['object_type'] ) ) : '';
		$id   = isset( $_POST['object_id'] ) ? absint( $_POST['object_id'] ) : 0;

		if ( 'post' === $type ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				wp_send_json_error( '没有权限编辑该内容。' );
			}

			$result = $this->ai->generate_for_post( $id );
		} elseif ( 'term' === $type ) {
			if ( ! current_user_can( 'manage_categories' ) && ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( '没有权限编辑该分类。' );
			}

			$result = $this->ai->generate_for_term( $id );
		} else {
			wp_send_json_error( '未知对象类型。' );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX AI test.
	 *
	 * @return void
	 */
	public function ajax_test_ai() {
		check_ajax_referer( 'super_seo_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( '没有权限。' );
		}

		$result = $this->ai->test_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Registers taxonomy form hooks after plugins have registered taxonomies.
	 *
	 * @return void
	 */
	public function register_term_hooks() {
		foreach ( $this->term_taxonomies() as $taxonomy ) {
			add_action( "{$taxonomy}_add_form_fields", array( $this, 'render_term_add_fields' ) );
			add_action( "{$taxonomy}_edit_form_fields", array( $this, 'render_term_edit_fields' ) );
			add_action( "created_{$taxonomy}", array( $this, 'save_term_meta' ) );
			add_action( "edited_{$taxonomy}", array( $this, 'save_term_meta' ) );
		}
	}

	/**
	 * Taxonomies supported by the simple UI.
	 *
	 * @return array
	 */
	private function term_taxonomies() {
		$taxonomies = array( 'category', 'post_tag' );

		if ( taxonomy_exists( 'product_cat' ) ) {
			$taxonomies[] = 'product_cat';
		}

		return $taxonomies;
	}

	/**
	 * Saves a post meta value.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param string $type    Sanitizer type.
	 * @return void
	 */
	private function save_meta_value( $post_id, $key, $type ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			return;
		}

		$value = $this->sanitize_meta_value( wp_unslash( $_POST[ $key ] ), $type );

		if ( '' === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $value );
		}
	}

	/**
	 * Saves a term meta value.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $key     Meta key.
	 * @param string $type    Sanitizer type.
	 * @return void
	 */
	private function save_term_meta_value( $term_id, $key, $type ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			return;
		}

		$value = $this->sanitize_meta_value( wp_unslash( $_POST[ $key ] ), $type );

		if ( '' === $value ) {
			delete_term_meta( $term_id, $key );
		} else {
			update_term_meta( $term_id, $key, $value );
		}
	}

	/**
	 * Sanitizes metadata.
	 *
	 * @param mixed  $value Value.
	 * @param string $type  Type.
	 * @return string
	 */
	private function sanitize_meta_value( $value, $type ) {
		if ( 'textarea' === $type ) {
			return Super_SEO_Helpers::limit_text( sanitize_textarea_field( $value ), 180 );
		}

		if ( 'keywords' === $type ) {
			return Super_SEO_Helpers::normalize_keywords( sanitize_text_field( $value ), 10 );
		}

		return Super_SEO_Helpers::limit_text( sanitize_text_field( $value ), 80 );
	}

	/**
	 * Renders a checkbox.
	 *
	 * @param string $key      Key.
	 * @param string $label    Label.
	 * @param string $help     Help.
	 * @param array  $settings Settings.
	 * @return void
	 */
	private function checkbox_field( $key, $label, $help, array $settings ) {
		?>
		<label class="super-seo-check">
			<input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( ! empty( $settings[ $key ] ) ); ?>>
			<span><?php echo esc_html( $label ); ?></span>
			<small><?php echo esc_html( $help ); ?></small>
		</label>
		<?php
	}

	/**
	 * Renders text input.
	 *
	 * @param string $key      Key.
	 * @param string $label    Label.
	 * @param string $help     Help.
	 * @param array  $settings Settings.
	 * @return void
	 */
	private function text_field( $key, $label, $help, array $settings ) {
		?>
		<div class="super-seo-field">
			<label for="super-seo-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<input id="super-seo-<?php echo esc_attr( $key ); ?>" type="text" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $settings[ $key ] ?? '' ); ?>">
			<p><?php echo esc_html( $help ); ?></p>
		</div>
		<?php
	}

	/**
	 * Renders textarea.
	 *
	 * @param string $key      Key.
	 * @param string $label    Label.
	 * @param string $help     Help.
	 * @param array  $settings Settings.
	 * @param int    $rows     Rows.
	 * @return void
	 */
	private function textarea_field( $key, $label, $help, array $settings, $rows = 3 ) {
		?>
		<div class="super-seo-field">
			<label for="super-seo-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<textarea id="super-seo-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" rows="<?php echo esc_attr( $rows ); ?>"><?php echo esc_textarea( $settings[ $key ] ?? '' ); ?></textarea>
			<p><?php echo esc_html( $help ); ?></p>
		</div>
		<?php
	}

	/**
	 * Renders select.
	 *
	 * @param string $key      Key.
	 * @param string $label    Label.
	 * @param array  $options  Options.
	 * @param array  $settings Settings.
	 * @return void
	 */
	private function select_field( $key, $label, array $options, array $settings ) {
		?>
		<div class="super-seo-field">
			<label for="super-seo-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<select id="super-seo-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>">
				<?php foreach ( $options as $value => $text ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings[ $key ] ?? '', $value ); ?>><?php echo esc_html( $text ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	}

	/**
	 * Renders a metabox text input.
	 *
	 * @param string $name        Field name.
	 * @param string $label       Label.
	 * @param string $value       Value.
	 * @param string $placeholder Placeholder.
	 * @param string $field       JS field key.
	 * @return void
	 */
	private function meta_input( $name, $label, $value, $placeholder, $field ) {
		?>
		<label class="super-seo-meta-field">
			<span><?php echo esc_html( $label ); ?></span>
			<input type="text" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" data-super-seo-field="<?php echo esc_attr( $field ); ?>">
		</label>
		<?php
	}

	/**
	 * Renders a metabox textarea.
	 *
	 * @param string $name        Field name.
	 * @param string $label       Label.
	 * @param string $value       Value.
	 * @param string $placeholder Placeholder.
	 * @param string $field       JS field key.
	 * @return void
	 */
	private function meta_textarea( $name, $label, $value, $placeholder, $field ) {
		?>
		<label class="super-seo-meta-field">
			<span><?php echo esc_html( $label ); ?></span>
			<textarea name="<?php echo esc_attr( $name ); ?>" rows="3" placeholder="<?php echo esc_attr( $placeholder ); ?>" data-super-seo-field="<?php echo esc_attr( $field ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
		</label>
		<?php
	}
}
