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
	 * Local audit service.
	 *
	 * @var Super_SEO_Audit
	 */
	private $audit;

	/**
	 * AI automation service.
	 *
	 * @var Super_SEO_Automation
	 */
	private $automation;

	/**
	 * Image description service.
	 *
	 * @var Super_SEO_Vision
	 */
	private $vision;

	/**
	 * Constructor.
	 *
	 * @param Super_SEO            $plugin     Plugin.
	 * @param Super_SEO_AI         $ai         AI client.
	 * @param Super_SEO_Audit      $audit      Audit service.
	 * @param Super_SEO_Automation $automation Automation service.
	 * @param Super_SEO_Vision     $vision     Image description service.
	 */
	public function __construct( Super_SEO $plugin, Super_SEO_AI $ai, Super_SEO_Audit $audit, Super_SEO_Automation $automation, Super_SEO_Vision $vision ) {
		$this->plugin     = $plugin;
		$this->ai         = $ai;
		$this->audit      = $audit;
		$this->automation = $automation;
		$this->vision     = $vision;

		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_super_seo_save_settings', array( $this, 'save_settings' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_post_meta' ) );
		add_action( 'wp_ajax_super_seo_generate_meta', array( $this, 'ajax_generate_meta' ) );
		add_action( 'wp_ajax_super_seo_test_ai', array( $this, 'ajax_test_ai' ) );
		add_action( 'wp_ajax_super_seo_run_local_audit', array( $this, 'ajax_run_local_audit' ) );
		add_action( 'wp_ajax_super_seo_generate_product_profile', array( $this, 'ajax_generate_product_profile' ) );
		add_action( 'wp_ajax_super_seo_generate_meta_batch', array( $this, 'ajax_generate_meta_batch' ) );
		add_action( 'wp_ajax_super_seo_apply_meta_suggestions', array( $this, 'ajax_apply_meta_suggestions' ) );
		add_action( 'wp_ajax_super_seo_generate_article', array( $this, 'ajax_generate_article' ) );
		add_action( 'wp_ajax_super_seo_run_article_cron', array( $this, 'ajax_run_article_cron' ) );
		add_action( 'wp_ajax_super_seo_undo_meta_suggestions', array( $this, 'ajax_undo_meta_suggestions' ) );
		add_action( 'wp_ajax_super_seo_vision_describe', array( $this, 'ajax_vision_describe' ) );
		add_action( 'wp_ajax_super_seo_vision_batch', array( $this, 'ajax_vision_batch' ) );
		add_action( 'wp_ajax_super_seo_vision_reset', array( $this, 'ajax_vision_reset' ) );
		add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_fields' ), 20, 2 );
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

		// Media screens need the assets too: the alt-text button lives in the
		// attachment fields, which render in the grid modal and the list view.
		if ( $screen && in_array( $screen->base, array( 'post', 'term', 'upload' ), true ) ) {
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
					'working'      => '正在处理，请稍等...',
					'actionDone'   => '操作完成，正在刷新结果...',
					'errorPrefix'  => '出错：',
					'visionDoing'  => '正在识别图片...',
					'visionDone'   => '已写入 alt 描述。',
					'batchIdle'    => '开始批量识别',
					'batchStop'    => '停止',
					'providers'    => $this->provider_js_defaults(),
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
		$has_key         = '' !== trim( (string) $settings['ai_api_key'] );
		$has_vision_key  = '' !== trim( (string) $settings['vision_api_key'] );
		$vision_stats    = $this->vision->stats();
		$audit       = $this->audit->cached_report();
		$profile     = $this->automation->product_profile();
		$suggestions = get_transient( Super_SEO_Automation::META_SUGGESTIONS_TRANSIENT );
		$suggestions = is_array( $suggestions ) ? $suggestions : array();
		$last_apply  = $this->automation->last_apply_record();
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
					<?php $this->textarea_field( 'default_keywords', '默认焦点关键词', '用于 AI 理解页面主题和生成建议，不建议堆到 meta keywords。', $settings, 2 ); ?>
					<?php $this->checkbox_field( 'output_meta_keywords', '兼容旧式 meta keywords', '默认关闭。Google 不使用该标签做网页排名，开启仅用于少数旧系统兼容。', $settings ); ?>
					<?php $this->checkbox_field( 'schema_enabled', '输出结构化数据 Schema', '自动输出 WebSite、Article、Product、CollectionPage 等基础 JSON-LD。', $settings ); ?>
					<?php $this->checkbox_field( 'noindex_search', '站内搜索页和 404 页不被收录', '输出 noindex,follow。这类页面进索引只会稀释站点质量，建议保持开启。', $settings ); ?>
				</section>

				<section class="super-seo-card">
					<h2>AI 大模型配置（文字）</h2>
					<p class="super-seo-help">用于生成标题、描述、关键词和文章。DeepSeek 便宜够用；想用一套配置同时做图片识别，选 Claude / GPT / Kimi / Qwen。</p>
					<div class="super-seo-two-col">
						<?php $this->select_field( 'ai_provider', '模型服务商', $this->provider_options(), $settings, 'text' ); ?>
						<?php $this->text_field( 'ai_model', '模型名称', '切换服务商时会自动填入默认模型，可手动修改。', $settings, 'model' ); ?>
					</div>
					<?php $this->text_field( 'ai_endpoint', '接口地址', '需使用 HTTPS。Claude 用 /v1/messages，其余用 /chat/completions。', $settings, 'endpoint' ); ?>
					<p class="super-seo-help" data-super-seo-provider-note="text"></p>
					<div class="super-seo-field">
						<label for="super-seo-ai-api-key">API Key</label>
						<input id="super-seo-ai-api-key" type="password" name="ai_api_key" value="" placeholder="<?php echo $has_key ? esc_attr( '已保存，留空表示不修改' ) : esc_attr( 'sk-...' ); ?>" autocomplete="new-password">
						<p>密钥单独存放，不随全站设置在每个前台请求里加载，也不会写入插件文件。要清空密钥，请勾选下面选项。</p>
					</div>
					<?php $this->checkbox_field( 'ai_clear_key', '清空已保存 API Key', '保存后删除当前密钥。', array( 'ai_clear_key' => 0 ) ); ?>
					<div class="super-seo-two-col">
						<?php $this->text_field( 'ai_language', '生成语言', '例如 zh-CN、en-US。', $settings ); ?>
						<?php $this->text_field( 'ai_temperature', '创造性温度', '建议 0.2-0.6。Claude 5 / GPT-5 系列不接受该参数，插件会自动跳过。', $settings ); ?>
					</div>
					<?php $this->textarea_field( 'ai_tone', '写作语气', '例如：专业、自然、适合 Google SEO。', $settings, 2 ); ?>
					<button type="button" class="button button-secondary super-seo-test-ai">测试 AI 连接</button>
					<span class="super-seo-inline-status" data-super-seo-test-status></span>
				</section>

				<section class="super-seo-card" data-super-seo-box>
					<h2>图片 ALT 智能识别（视觉模型）</h2>
					<p class="super-seo-help">用能看图的模型读懂每张图片，自动写出符合无障碍规范、也利于 Google 图片搜索的 alt 描述标签。DeepSeek 不支持读图，这里需要单独选一个视觉服务商。</p>

					<div class="super-seo-status-grid">
						<div class="super-seo-status-card">
							<strong>媒体库图片</strong>
							<span><?php echo esc_html( $vision_stats['total'] ); ?> 张</span>
						</div>
						<div class="super-seo-status-card">
							<strong>缺 alt</strong>
							<span class="<?php echo $vision_stats['missing'] ? 'is-warn' : 'is-ok'; ?>"><?php echo esc_html( $vision_stats['missing'] ); ?> 张待处理</span>
						</div>
						<div class="super-seo-status-card">
							<strong>识别失败</strong>
							<span class="<?php echo $vision_stats['failed'] ? 'is-warn' : 'is-ok'; ?>"><?php echo esc_html( $vision_stats['failed'] ); ?> 张</span>
						</div>
						<div class="super-seo-status-card">
							<strong>累计消耗</strong>
							<span><?php echo esc_html( sprintf( '%d 张 / 输入 %s、输出 %s token', $vision_stats['usage']['images'], number_format_i18n( $vision_stats['usage']['input'] ), number_format_i18n( $vision_stats['usage']['output'] ) ) ); ?></span>
						</div>
					</div>

					<?php $this->checkbox_field( 'vision_enabled', '启用图片 ALT 智能识别', '关闭后媒体库不再显示“AI 写 alt 描述”按钮。', $settings ); ?>
					<div class="super-seo-two-col">
						<?php $this->select_field( 'vision_provider', '视觉服务商', $this->provider_options( true ), $settings, 'vision' ); ?>
						<?php $this->text_field( 'vision_model', '视觉模型名称', 'Claude 建议 claude-opus-5；整站批量跑图可换 claude-haiku-4-5 省钱。Qwen 必须用 qwen-vl 系列。', $settings, 'vision-model' ); ?>
					</div>
					<?php $this->text_field( 'vision_endpoint', '视觉接口地址', '切换服务商时会自动填入默认地址。', $settings, 'vision-endpoint' ); ?>
					<p class="super-seo-help" data-super-seo-provider-note="vision"></p>
					<div class="super-seo-field">
						<label for="super-seo-vision-api-key">视觉 API Key</label>
						<input id="super-seo-vision-api-key" type="password" name="vision_api_key" value="" placeholder="<?php echo $has_vision_key ? esc_attr( '已保存，留空表示不修改' ) : esc_attr( '与文字服务商同一家时可留空，自动复用' ); ?>" autocomplete="new-password">
						<p>视觉服务商和上面的文字服务商是同一家时，这里留空即可复用上面的 Key。</p>
					</div>
					<?php $this->checkbox_field( 'vision_clear_key', '清空已保存的视觉 API Key', '保存后删除当前视觉密钥。', array( 'vision_clear_key' => 0 ) ); ?>

					<div class="super-seo-two-col">
						<?php $this->text_field( 'vision_language', 'alt 使用的语言', sprintf( '留空则跟随站点语言（当前：%s）。alt 是给访客和搜索引擎看的，语言应与站点一致。', Super_SEO_AI::site_language() ), $settings ); ?>
						<?php $this->text_field( 'vision_max_edge', '送去识别的图片边长', '默认 1024 像素。调大更清晰但更贵，一般不用改。', $settings ); ?>
					</div>
					<?php $this->checkbox_field( 'vision_write_title', '同时改写媒体标题', '仅当原标题是 IMG_1234 这类无意义文件名时才覆盖。', $settings ); ?>
					<?php $this->checkbox_field( 'vision_write_caption', '同时写入图片说明（caption）', '部分主题会显示在图片下方，默认关闭。', $settings ); ?>
					<?php $this->checkbox_field( 'vision_overwrite_existing', '覆盖已有的 alt 文本', '默认只补空白的 alt，不动人工写过的内容。', $settings ); ?>
					<?php $this->checkbox_field( 'vision_auto_on_upload', '新上传图片自动识别', '上传后约 30 秒由后台任务处理，不阻塞上传。图片多时会持续消耗 API 额度。', $settings ); ?>
					<?php $this->text_field( 'vision_batch_size', '每批处理数量', '一次处理几张，建议 3-8。', $settings ); ?>

					<div class="super-seo-actions">
						<button type="button" class="button button-primary" data-super-seo-vision-batch>开始批量识别</button>
						<?php if ( $vision_stats['failed'] ) : ?>
							<button type="button" class="button super-seo-action-button" data-super-seo-action="super_seo_vision_reset" data-super-seo-payload='{"only_failed":1}'>只重试失败的 <?php echo esc_html( $vision_stats['failed'] ); ?> 张</button>
						<?php endif; ?>
						<button type="button" class="button button-link-delete super-seo-action-button" data-super-seo-action="super_seo_vision_reset" data-super-seo-confirm="确定要清除全部识别记录吗？清除后所有图片都会被重新识别一遍，会重新消耗 API 额度。">清除全部记录（重跑所有图片）</button>
						<span class="super-seo-inline-status" data-super-seo-status></span>
					</div>
					<p class="super-seo-help">批量识别会连续调用视觉模型。请先用单张图片试效果和费用，再整站跑；运行期间不要关闭本页面。遇到限流或服务商故障时会自动暂停并保留进度（不会把图片标记成永久失败），恢复后点一下继续即可。每张图片只会被自动尝试一次，避免坏图卡住队列。</p>
					<div class="super-seo-result-box" data-super-seo-vision-log hidden></div>
				</section>

				<section class="super-seo-card" data-super-seo-box>
					<h2>AI 产品画像与内容自动化</h2>
					<p class="super-seo-help">优先读取 WooCommerce 产品、分类、属性和现有 SEO Meta，生成产品主题画像，再用于批量 Meta 和文章生成。</p>
					<div class="super-seo-actions">
						<button type="button" class="button button-secondary super-seo-action-button" data-super-seo-action="super_seo_generate_product_profile">生成/更新产品画像</button>
						<button type="button" class="button button-secondary super-seo-action-button" data-super-seo-action="super_seo_generate_meta_batch">生成批量 Meta 建议</button>
						<button type="button" class="button button-primary super-seo-action-button" data-super-seo-action="super_seo_apply_meta_suggestions">应用当前 Meta 建议</button>
						<?php if ( ! empty( $last_apply['time'] ) ) : ?>
							<button type="button" class="button button-link-delete super-seo-action-button" data-super-seo-action="super_seo_undo_meta_suggestions" data-super-seo-confirm="确定要把上次批量应用的 SEO 标题/描述/关键词全部还原成旧值吗？">撤销上次批量应用（<?php echo esc_html( date_i18n( 'm-d H:i', (int) $last_apply['time'] ) ); ?>）</button>
						<?php endif; ?>
					</div>
					<span class="super-seo-inline-status" data-super-seo-status></span>
					<?php $this->render_product_profile( $profile ); ?>
					<?php $this->render_meta_suggestions( $suggestions ); ?>

					<div class="super-seo-two-col">
						<?php $this->select_field( 'ai_article_strategy', '文章策略', array( 'strict' => '严格产品相关', 'education' => '行业科普', 'growth' => '增长长尾词' ), $settings ); ?>
						<?php $this->select_field( 'ai_publish_mode', '发布模式', array( 'draft' => '生成草稿', 'future' => '定时发布', 'publish' => '直接发布' ), $settings ); ?>
					</div>
					<div class="super-seo-two-col">
						<?php $this->select_field( 'ai_article_frequency', '自动文章频率', array( 'manual' => '手动', 'daily' => '每天', 'weekly' => '每周' ), $settings ); ?>
						<?php $this->text_field( 'ai_article_category', '文章分类 ID', '可留空；填写分类 ID 后自动文章会归入该分类。', $settings ); ?>
					</div>
					<?php $this->text_field( 'ai_article_min_words', '文章最低字数', '低于该字数会被质量门禁拒绝。', $settings ); ?>
					<?php $this->checkbox_field( 'ai_direct_publish_enabled', '允许直接发布', '开启后发布模式选择“直接发布”才会生效；否则强制阻止。', $settings ); ?>
					<div class="super-seo-actions">
						<button type="button" class="button button-secondary super-seo-action-button" data-super-seo-action="super_seo_generate_article">按当前设置生成一篇文章</button>
						<button type="button" class="button button-secondary super-seo-action-button" data-super-seo-action="super_seo_run_article_cron">手动触发一次定时任务</button>
					</div>
					<?php $this->render_last_article_result( $this->automation->last_article_result() ); ?>
				</section>

				<section class="super-seo-card">
					<h2>PageSpeed 优化</h2>
					<?php $this->select_field( 'pagespeed_mode', '优化模式', array( 'safe' => '安全：只做基础 SEO 与图片属性', 'balanced' => '平衡：WebP、JS defer、手动首图 preload', 'aggressive' => '激进：自动 preload、HTML 压缩、主题补丁' ), $settings ); ?>
					<?php $this->checkbox_field( 'robots_enabled', '修复并输出有效 robots.txt', '自动加入 Sitemap，并避免 robots.txt 格式错误导致 Lighthouse 扣分。', $settings ); ?>
					<?php $this->checkbox_field( 'sitemap_enabled', '启用 XML 站点地图', '生成 /super-seo-sitemap.xml。', $settings ); ?>
					<?php $this->checkbox_field( 'performance_lazy_images', '图片懒加载与 async 解码', '为媒体库图片补 loading、decoding、alt 等属性。', $settings ); ?>
					<?php $this->checkbox_field( 'performance_fetchpriority', '首张图片高优先级', '首屏图片使用 fetchpriority=high，降低 LCP 风险。', $settings ); ?>
					<?php $this->checkbox_field( 'performance_auto_preload_image', '自动预加载首屏背景图/首图', '会扫描前台 HTML，建议只在 PageSpeed 明确提示 LCP 图片发现较慢时开启。', $settings ); ?>
					<?php $this->text_field( 'performance_preload_hero_image', '指定首屏大图 URL', '可填写 PageSpeed 报告里的 LCP/首屏图片 URL，留空则自动识别。', $settings ); ?>
					<?php $this->checkbox_field( 'performance_webp_uploads', '新上传图片自动生成 WebP', '依赖服务器 GD/Imagick 支持 WebP。', $settings ); ?>
					<?php $this->checkbox_field( 'performance_webp_rewrite', '优先给支持的浏览器返回 WebP', '有 WebP 文件时自动替换媒体库图片 URL。', $settings ); ?>
					<?php $this->checkbox_field( 'performance_defer_js', '安全延迟非核心 JS', '自动给非排除列表脚本添加 defer，减少渲染阻塞。', $settings ); ?>
					<?php $this->textarea_field( 'performance_defer_exclusions', 'JS 延迟排除列表', '每项用逗号分隔。jQuery、WooCommerce 等默认排除，避免破坏交互。', $settings, 3 ); ?>
					<?php $this->checkbox_field( 'performance_disable_emojis', '关闭 WordPress Emoji 资源', '减少无用脚本和 DNS 请求。', $settings ); ?>
					<?php $this->checkbox_field( 'performance_disable_embeds', '关闭 Embed 资源', '不用文章嵌入预览时建议开启。', $settings ); ?>
					<?php $this->checkbox_field( 'performance_accessibility_fixes', '启用当前主题专项可访问性补丁', '包含少量站点定制 CSS/JS，通用站点建议保持关闭。', $settings ); ?>
					<?php $this->checkbox_field( 'performance_security_headers', '输出基础安全响应头', '补充 X-Content-Type-Options、Referrer-Policy 等轻量安全头。', $settings ); ?>
					<?php $this->checkbox_field( 'performance_dedupe_head', '清理重复的 SEO 标签', '仅在检测到 Yoast、Rank Math 等其它 SEO 插件时才会启用整页处理，单独使用本插件时不会有额外开销。', $settings ); ?>
					<?php $this->checkbox_field( 'performance_minify_html', '压缩前台 HTML', '可能与少数主题冲突，建议先在测试环境开启。', $settings ); ?>
					<?php $this->textarea_field( 'audit_urls', '本地检测附加 URL', '每行一个当前站点 URL。留空时自动检测首页、近期产品和产品分类。', $settings, 3 ); ?>
					<div class="super-seo-actions" data-super-seo-box>
						<button type="button" class="button button-secondary super-seo-action-button" data-super-seo-action="super_seo_run_local_audit">立即运行本地检测</button>
						<span class="super-seo-inline-status" data-super-seo-status></span>
					</div>
					<?php $this->render_audit_report( $audit ); ?>
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
			'output_meta_keywords',
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
				'performance_dedupe_head',
				'noindex_search',
				'ai_direct_publish_enabled',
				'vision_enabled',
				'vision_auto_on_upload',
				'vision_overwrite_existing',
				'vision_write_title',
				'vision_write_caption',
			);

		foreach ( $defaults as $key => $default ) {
			if ( in_array( $key, $checkbox, true ) ) {
				$new[ $key ] = isset( $_POST[ $key ] ) ? 1 : 0;
				continue;
			}

			$new[ $key ] = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default;
		}

		$new['ai_provider']                    = Super_SEO_AI::normalize_provider_key( $new['ai_provider'] );
			$new['ai_endpoint']                    = esc_url_raw( $new['ai_endpoint'] );
			$new['ai_model']                       = sanitize_text_field( $new['ai_model'] );
			$new['ai_temperature']                 = (string) max( 0, min( 1, (float) $new['ai_temperature'] ) );
			$new['ai_language']                    = sanitize_text_field( $new['ai_language'] );
			$new['ai_tone']                        = sanitize_textarea_field( $new['ai_tone'] );
			$new['ai_article_strategy']            = $this->sanitize_choice( $new['ai_article_strategy'], array( 'strict', 'education', 'growth' ), 'strict' );
			$new['ai_publish_mode']                = $this->sanitize_choice( $new['ai_publish_mode'], array( 'draft', 'future', 'publish' ), 'draft' );
			$new['ai_article_frequency']           = $this->sanitize_choice( $new['ai_article_frequency'], array( 'manual', 'daily', 'weekly' ), 'manual' );
			$new['ai_article_category']            = absint( $new['ai_article_category'] );
			$new['ai_article_min_words']           = (string) max( 80, min( 3000, (int) $new['ai_article_min_words'] ) );
			$new['site_brand_name']                = sanitize_text_field( $new['site_brand_name'] );
			$new['default_description']            = sanitize_textarea_field( $new['default_description'] );
			$new['default_keywords']               = Super_SEO_Helpers::normalize_keywords( sanitize_textarea_field( $new['default_keywords'] ), 12 );
			$new['pagespeed_mode']                 = $this->sanitize_choice( $new['pagespeed_mode'], array( 'safe', 'balanced', 'aggressive' ), 'balanced' );
			$new['audit_urls']                     = sanitize_textarea_field( $new['audit_urls'] );
			$new['performance_preload_hero_image'] = esc_url_raw( $new['performance_preload_hero_image'] );
			$new['performance_defer_exclusions']   = sanitize_textarea_field( $new['performance_defer_exclusions'] );

			$vision_providers        = array_keys( Super_SEO_AI::vision_providers() );
			$new['vision_provider']  = $this->sanitize_choice( $new['vision_provider'], $vision_providers, 'anthropic' );
			$new['vision_endpoint']  = esc_url_raw( $new['vision_endpoint'] );
			$new['vision_model']     = sanitize_text_field( $new['vision_model'] );
			$new['vision_language']  = sanitize_text_field( $new['vision_language'] );
			$new['vision_max_edge']  = (string) max( 320, min( 2576, (int) $new['vision_max_edge'] ) );
			$new['vision_batch_size'] = (string) max( 1, min( 20, (int) $new['vision_batch_size'] ) );

		$new['ai_api_key']     = $this->resolve_posted_key( 'ai_api_key', 'ai_clear_key', $old );
		$new['vision_api_key'] = $this->resolve_posted_key( 'vision_api_key', 'vision_clear_key', $old );

			$this->plugin->update_settings( $new );
			$this->automation->ensure_article_cron();

		wp_safe_redirect( add_query_arg( 'settings-updated', '1', admin_url( 'admin.php?page=super-seo' ) ) );
		exit;
	}

	/**
	 * Resolves a posted secret against the "clear" checkbox and the stored value.
	 *
	 * @param string $field     POST field name.
	 * @param string $clear_key POST field name of the clear checkbox.
	 * @param array  $old       Previous settings.
	 * @return string
	 */
	private function resolve_posted_key( $field, $clear_key, array $old ) {
		if ( isset( $_POST[ $clear_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in save_settings().
			return '';
		}

		$posted = isset( $_POST[ $field ] ) ? trim( (string) wp_unslash( $_POST[ $field ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' !== $posted ) {
			return sanitize_text_field( $posted );
		}

		return (string) ( $old[ $field ] ?? '' );
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
			<?php $this->meta_input( '_super_seo_keywords', '焦点关键词', $keywords, '用于 AI 和内容方向，5-8 个即可', 'keywords' ); ?>
			<label class="super-seo-check">
				<input type="checkbox" name="_super_seo_noindex" value="1" <?php checked( '1', (string) get_post_meta( $post->ID, '_super_seo_noindex', true ) ); ?>>
				<span>不允许搜索引擎收录本页（noindex）</span>
				<small>勾选后输出 noindex 并从站点地图移除，适合感谢页、内部页、重复内容。</small>
			</label>
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

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$this->save_meta_value( $post_id, '_super_seo_title', 'text' );
		$this->save_meta_value( $post_id, '_super_seo_description', 'textarea' );
		$this->save_meta_value( $post_id, '_super_seo_keywords', 'keywords' );

		if ( isset( $_POST['_super_seo_noindex'] ) ) {
			update_post_meta( $post_id, '_super_seo_noindex', '1' );
		} else {
			delete_post_meta( $post_id, '_super_seo_noindex' );
		}

		delete_transient( 'super_seo_sitemap_entries' );
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
			<label for="super-seo-term-keywords">超级SEO焦点关键词</label>
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
			<th scope="row"><label for="super-seo-term-keywords">超级SEO焦点关键词</label></th>
			<td>
				<input id="super-seo-term-keywords" name="_super_seo_keywords" type="text" value="<?php echo esc_attr( $keywords ); ?>" data-super-seo-field="keywords">
				<p class="description">用于 AI 和内容方向，5-8 个即可。</p>
				<label>
					<input type="checkbox" name="_super_seo_noindex" value="1" <?php checked( '1', (string) get_term_meta( $term->term_id, '_super_seo_noindex', true ) ); ?>>
					不允许搜索引擎收录本分类（noindex，同时移出站点地图）
				</label>
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

		if ( isset( $_POST['_super_seo_noindex'] ) ) {
			update_term_meta( $term_id, '_super_seo_noindex', '1' );
		} else {
			delete_term_meta( $term_id, '_super_seo_noindex' );
		}

		delete_transient( 'super_seo_sitemap_entries' );
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
		} elseif ( 'term' === $type ) {
			if ( ! current_user_can( 'manage_categories' ) && ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( '没有权限编辑该分类。' );
			}
		} else {
			wp_send_json_error( '未知对象类型。' );
		}

		$rate_limit = $this->rate_limit_ai_action( 'generate', 20, MINUTE_IN_SECONDS );

		if ( is_wp_error( $rate_limit ) ) {
			wp_send_json_error( $rate_limit->get_error_message() );
		}

		$result = 'post' === $type ? $this->ai->generate_for_post( $id ) : $this->ai->generate_for_term( $id );

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

		$rate_limit = $this->rate_limit_ai_action( 'test', 5, MINUTE_IN_SECONDS );

		if ( is_wp_error( $rate_limit ) ) {
			wp_send_json_error( $rate_limit->get_error_message() );
		}

		$result = $this->ai->test_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX local audit.
	 *
	 * @return void
	 */
	public function ajax_run_local_audit() {
		$this->check_manage_options_ajax();

		$rate_limit = $this->rate_limit_ai_action( 'audit', 5, MINUTE_IN_SECONDS );

		if ( is_wp_error( $rate_limit ) ) {
			wp_send_json_error( $rate_limit->get_error_message() );
		}

		$result = $this->audit->run();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX product profile generation.
	 *
	 * @return void
	 */
	public function ajax_generate_product_profile() {
		$this->check_manage_options_ajax();

		$rate_limit = $this->rate_limit_ai_action( 'profile', 3, MINUTE_IN_SECONDS );

		if ( is_wp_error( $rate_limit ) ) {
			wp_send_json_error( $rate_limit->get_error_message() );
		}

		$result = $this->automation->generate_product_profile();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX batched meta suggestions.
	 *
	 * @return void
	 */
	public function ajax_generate_meta_batch() {
		$this->check_manage_options_ajax();

		$rate_limit = $this->rate_limit_ai_action( 'meta_batch', 3, MINUTE_IN_SECONDS );

		if ( is_wp_error( $rate_limit ) ) {
			wp_send_json_error( $rate_limit->get_error_message() );
		}

		$result = $this->automation->generate_meta_suggestions();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX apply generated meta suggestions.
	 *
	 * @return void
	 */
	public function ajax_apply_meta_suggestions() {
		$this->check_manage_options_ajax();

		$rate_limit = $this->rate_limit_ai_action( 'apply_meta', 10, MINUTE_IN_SECONDS );

		if ( is_wp_error( $rate_limit ) ) {
			wp_send_json_error( $rate_limit->get_error_message() );
		}

		$result = $this->automation->apply_meta_suggestions();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX article generation.
	 *
	 * @return void
	 */
	public function ajax_generate_article() {
		$this->check_manage_options_ajax();

		$rate_limit = $this->rate_limit_ai_action( 'article', 3, MINUTE_IN_SECONDS );

		if ( is_wp_error( $rate_limit ) ) {
			wp_send_json_error( $rate_limit->get_error_message() );
		}

		$result = $this->automation->generate_article();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: run the scheduled-article job exactly as cron would.
	 *
	 * @return void
	 */
	public function ajax_run_article_cron() {
		$this->check_manage_options_ajax();

		$rate_limit = $this->rate_limit_ai_action( 'article', 3, MINUTE_IN_SECONDS );

		if ( is_wp_error( $rate_limit ) ) {
			wp_send_json_error( $rate_limit->get_error_message() );
		}

		$this->automation->run_article_cron();
		$result = $this->automation->last_article_result();

		if ( empty( $result['success'] ) ) {
			wp_send_json_error( $result['message'] ?? '定时任务执行失败。' );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: roll back the last bulk meta apply.
	 *
	 * @return void
	 */
	public function ajax_undo_meta_suggestions() {
		$this->check_manage_options_ajax();

		$result = $this->automation->undo_meta_suggestions();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: describe a single attachment and write its alt text.
	 *
	 * @return void
	 */
	public function ajax_vision_describe() {
		check_ajax_referer( 'super_seo_admin', 'nonce' );

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

		if ( ! $attachment_id || ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error( '没有权限编辑该媒体文件。' );
		}

		if ( ! $this->plugin->setting( 'vision_enabled', 1 ) ) {
			wp_send_json_error( '图片 ALT 智能识别未启用。' );
		}

		$rate_limit = $this->rate_limit_ai_action( 'vision', 60, MINUTE_IN_SECONDS );

		if ( is_wp_error( $rate_limit ) ) {
			wp_send_json_error( $rate_limit->get_error_message() );
		}

		$overwrite = ! empty( $_POST['overwrite'] );
		$result    = $this->vision->generate_and_apply( $attachment_id, array( 'overwrite' => $overwrite ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: describe the next batch of images missing alt text.
	 *
	 * @return void
	 */
	public function ajax_vision_batch() {
		$this->check_manage_options_ajax();

		if ( ! $this->plugin->setting( 'vision_enabled', 1 ) ) {
			wp_send_json_error( '图片 ALT 智能识别未启用。' );
		}

		$rate_limit = $this->rate_limit_ai_action( 'vision_batch', 40, MINUTE_IN_SECONDS );

		if ( is_wp_error( $rate_limit ) ) {
			wp_send_json_error( $rate_limit->get_error_message() );
		}

		$result = $this->vision->run_batch(
			array(
				'overwrite' => ! empty( $_POST['overwrite'] ),
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: clear the per-image processing record so everything can be re-run.
	 *
	 * @return void
	 */
	public function ajax_vision_reset() {
		$this->check_manage_options_ajax();

		$only_failed = ! empty( $_POST['only_failed'] );
		$cleared     = $this->vision->reset_records( $only_failed );

		if ( ! $only_failed ) {
			$this->vision->reset_usage();
		}

		wp_send_json_success( array( 'cleared' => $cleared ) );
	}

	/**
	 * Adds an "AI 写 alt" button to the attachment edit fields.
	 *
	 * @param array   $fields     Attachment fields.
	 * @param WP_Post $attachment Attachment.
	 * @return array
	 */
	public function attachment_fields( $fields, $attachment ) {
		if ( ! $this->plugin->setting( 'vision_enabled', 1 ) ) {
			return $fields;
		}

		if ( ! $attachment instanceof WP_Post || 0 !== strpos( (string) $attachment->post_mime_type, 'image/' ) ) {
			return $fields;
		}

		if ( ! current_user_can( 'edit_post', $attachment->ID ) ) {
			return $fields;
		}

		$fields['super_seo_vision'] = array(
			'label' => '超级SEO',
			'input' => 'html',
			'html'  => sprintf(
				'<span data-super-seo-box><button type="button" class="button button-secondary super-seo-vision-button" data-attachment-id="%d">AI 写 alt 描述</button> <span class="super-seo-inline-status" data-super-seo-status></span></span>',
				(int) $attachment->ID
			),
			'helps' => '用视觉模型识别图片内容，自动填写替代文本（alt）。生成后请刷新查看。',
		);

		return $fields;
	}

	/**
	 * Provider defaults exposed to the settings JS.
	 *
	 * @return array
	 */
	private function provider_js_defaults() {
		$out = array();

		foreach ( Super_SEO_AI::providers() as $key => $provider ) {
			$out[ $key ] = array(
				'endpoint'     => $provider['endpoint'],
				'model'        => $provider['model'],
				'visionModel'  => '' !== $provider['vision_model'] ? $provider['vision_model'] : $provider['model'],
				'vision'       => (bool) $provider['vision'],
				'note'         => $provider['note'],
			);
		}

		return $out;
	}

	/**
	 * Select options for provider dropdowns.
	 *
	 * @param bool $vision_only Only providers that can read images.
	 * @return array
	 */
	private function provider_options( $vision_only = false ) {
		$providers = $vision_only ? Super_SEO_AI::vision_providers() : Super_SEO_AI::providers();
		$options   = array();

		foreach ( $providers as $key => $provider ) {
			$options[ $key ] = $provider['label'];
		}

		return $options;
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
	 * Applies a small per-user throttle to paid AI calls.
	 *
	 * @param string $action Action key.
	 * @param int    $limit  Max calls.
	 * @param int    $window Window in seconds.
	 * @return true|WP_Error
	 */
	private function rate_limit_ai_action( $action, $limit, $window ) {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return true;
		}

		$key   = 'super_seo_ai_' . sanitize_key( $action ) . '_' . (int) $user_id;
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return new WP_Error( 'super_seo_ai_rate_limited', 'AI 请求过于频繁，请稍后再试。' );
		}

		set_transient( $key, $count + 1, $window );

		return true;
	}

	/**
	 * Checks admin AJAX nonce and capability.
	 *
	 * @return void
	 */
	private function check_manage_options_ajax() {
		check_ajax_referer( 'super_seo_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( '没有权限。' );
		}
	}

	/**
	 * Sanitizes a select value.
	 *
	 * @param string $value   Value.
	 * @param array  $allowed Allowed values.
	 * @param string $default Default.
	 * @return string
	 */
	private function sanitize_choice( $value, array $allowed, $default ) {
		$value = sanitize_key( $value );

		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	/**
	 * Renders cached product profile.
	 *
	 * @param array $profile Profile.
	 * @return void
	 */
	private function render_product_profile( array $profile ) {
		if ( empty( $profile ) ) {
			echo '<p class="super-seo-help">尚未生成产品画像。</p>';
			return;
		}
		?>
		<div class="super-seo-result-box">
			<strong>产品画像</strong>
			<p>核心关键词：<?php echo esc_html( implode( ', ', array_slice( (array) ( $profile['core_keywords'] ?? array() ), 0, 8 ) ) ); ?></p>
			<p>文章方向：<?php echo esc_html( implode( ' / ', array_slice( (array) ( $profile['article_angles'] ?? array() ), 0, 5 ) ) ); ?></p>
			<?php if ( ! empty( $profile['generated_at'] ) ) : ?>
				<small>更新时间：<?php echo esc_html( date_i18n( 'Y-m-d H:i', (int) $profile['generated_at'] ) ); ?></small>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the last scheduled article result.
	 *
	 * @param array $result Result.
	 * @return void
	 */
	private function render_last_article_result( array $result ) {
		if ( empty( $result['time'] ) ) {
			return;
		}
		?>
		<p class="super-seo-help <?php echo empty( $result['success'] ) ? 'is-error' : ''; ?>">
			上次定时文章（<?php echo esc_html( date_i18n( 'Y-m-d H:i', (int) $result['time'] ) ); ?>）：<?php echo esc_html( $result['message'] ?? '' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders cached meta suggestions.
	 *
	 * @param array $suggestions Suggestions.
	 * @return void
	 */
	private function render_meta_suggestions( array $suggestions ) {
		if ( empty( $suggestions ) ) {
			echo '<p class="super-seo-help">尚无批量 Meta 建议。</p>';
			return;
		}
		?>
		<div class="super-seo-result-box">
			<strong>当前 Meta 建议（最多显示 5 条）</strong>
			<?php foreach ( array_slice( $suggestions, 0, 5 ) as $suggestion ) : ?>
				<div class="super-seo-suggestion-row">
					<b><?php echo esc_html( $suggestion['label'] ?? $suggestion['title'] ?? '内容' ); ?></b>
					<?php if ( ! empty( $suggestion['error'] ) ) : ?>
						<p class="is-error"><?php echo esc_html( $suggestion['error'] ); ?></p>
					<?php else : ?>
						<p>标题：<?php echo esc_html( $suggestion['before']['title'] ?? '' ); ?> → <?php echo esc_html( $suggestion['after']['title'] ?? '' ); ?></p>
						<p>描述：<?php echo esc_html( $suggestion['after']['description'] ?? '' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Renders cached local audit report.
	 *
	 * @param array $report Report.
	 * @return void
	 */
	private function render_audit_report( array $report ) {
		if ( empty( $report ) ) {
			echo '<p class="super-seo-help">尚未运行本地 PageSpeed/SEO 检测。</p>';
			return;
		}

		$summary = $report['summary'] ?? array();
		?>
		<div class="super-seo-result-box">
			<strong>本地检测结果</strong>
			<p>平均分：<?php echo esc_html( $summary['average_score'] ?? 0 ); ?> / 100，检测 URL：<?php echo esc_html( $summary['urls'] ?? 0 ); ?> 个</p>
			<?php foreach ( array_slice( (array) ( $report['results'] ?? array() ), 0, 6 ) as $result ) : ?>
				<div class="super-seo-audit-row">
					<b><?php echo esc_html( $result['score'] ?? 0 ); ?>/100</b>
					<a href="<?php echo esc_url( $result['url'] ?? '' ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $result['url'] ?? '' ); ?></a>
					<ul>
						<?php foreach ( array_slice( (array) ( $result['checks'] ?? array() ), 0, 5 ) as $check ) : ?>
							<li><?php echo esc_html( '[' . ( $check['type'] ?? 'needs review' ) . '] ' . ( $check['message'] ?? '' ) ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>
			<?php if ( ! empty( $report['generated_at'] ) ) : ?>
				<small>检测时间：<?php echo esc_html( date_i18n( 'Y-m-d H:i', (int) $report['generated_at'] ) ); ?></small>
			<?php endif; ?>
		</div>
		<?php
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
	private function text_field( $key, $label, $help, array $settings, $role = '' ) {
		?>
		<div class="super-seo-field">
			<label for="super-seo-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<input id="super-seo-<?php echo esc_attr( $key ); ?>" type="text" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $settings[ $key ] ?? '' ); ?>"<?php echo '' !== $role ? ' data-super-seo-field-role="' . esc_attr( $role ) . '"' : ''; ?>>
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
	private function select_field( $key, $label, array $options, array $settings, $role = '' ) {
		?>
		<div class="super-seo-field">
			<label for="super-seo-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<select id="super-seo-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>"<?php echo '' !== $role ? ' data-super-seo-provider="' . esc_attr( $role ) . '"' : ''; ?>>
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
