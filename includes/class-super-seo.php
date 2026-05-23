<?php
/**
 * Main plugin bootstrap.
 *
 * @package SuperSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads all Super SEO modules.
 */
final class Super_SEO {
	/**
	 * Singleton instance.
	 *
	 * @var Super_SEO|null
	 */
	private static $instance = null;

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private $settings = null;

	/**
	 * AI client.
	 *
	 * @var Super_SEO_AI
	 */
	private $ai;

	/**
	 * Returns singleton instance.
	 *
	 * @return Super_SEO
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Plugin activation.
	 *
	 * @return void
	 */
	public static function activate() {
		$existing = get_option( SUPER_SEO_OPTION );

		if ( ! is_array( $existing ) ) {
			add_option( SUPER_SEO_OPTION, self::default_settings(), '', false );
		} else {
			update_option( SUPER_SEO_OPTION, wp_parse_args( $existing, self::default_settings() ), false );
		}

		add_rewrite_rule( '^super-seo-sitemap\.xml/?$', 'index.php?super_seo_sitemap=1', 'top' );
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Default options.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			'enabled'                          => 1,
			'auto_meta_description'            => 1,
			'default_keywords'                 => '',
			'default_description'              => '',
			'site_brand_name'                  => get_bloginfo( 'name' ),
			'ai_provider'                      => 'deepseek',
			'ai_endpoint'                      => 'https://api.deepseek.com/chat/completions',
			'ai_model'                         => 'deepseek-chat',
			'ai_api_key'                       => '',
			'ai_temperature'                   => '0.4',
			'ai_language'                      => 'zh-CN',
			'ai_tone'                          => '专业、自然、适合 Google SEO',
			'sitemap_enabled'                  => 1,
			'robots_enabled'                   => 1,
			'schema_enabled'                   => 1,
			'performance_disable_emojis'       => 1,
			'performance_disable_embeds'       => 1,
			'performance_defer_js'             => 1,
			'performance_defer_exclusions'     => 'jquery,jquery-core,jquery-migrate,admin-bar,wp-polyfill,woocommerce,woocommerce-inline,cart-fragments,wc-cart-fragments',
			'performance_lazy_images'          => 1,
			'performance_fetchpriority'        => 1,
			'performance_preload_hero_image'   => '',
			'performance_auto_preload_image'   => 1,
			'performance_webp_uploads'         => 1,
			'performance_webp_rewrite'         => 1,
			'performance_minify_html'          => 0,
			'performance_accessibility_fixes'  => 1,
			'performance_security_headers'     => 1,
		);
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->ai = new Super_SEO_AI( $this );

		add_action( 'plugins_loaded', array( $this, 'load_modules' ) );
	}

	/**
	 * Loads modules after WordPress is ready.
	 *
	 * @return void
	 */
	public function load_modules() {
		load_plugin_textdomain( 'super-seo', false, dirname( plugin_basename( SUPER_SEO_FILE ) ) . '/languages' );

		new Super_SEO_Meta( $this );
		new Super_SEO_Sitemap( $this );
		new Super_SEO_Performance( $this );

		if ( is_admin() ) {
			new Super_SEO_Admin( $this, $this->ai );
		}
	}

	/**
	 * Returns merged plugin settings.
	 *
	 * @return array
	 */
	public function settings() {
		if ( null === $this->settings ) {
			$this->settings = wp_parse_args( get_option( SUPER_SEO_OPTION, array() ), self::default_settings() );
		}

		return $this->settings;
	}

	/**
	 * Returns a single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function setting( $key, $default = null ) {
		$settings = $this->settings();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Updates settings and refreshes cache.
	 *
	 * @param array $settings Settings.
	 * @return void
	 */
	public function update_settings( array $settings ) {
		$this->settings = wp_parse_args( $settings, self::default_settings() );
		update_option( SUPER_SEO_OPTION, $this->settings, false );
		$this->purge_super_rocket_cache();
	}

	/**
	 * Clears Super Rocket cache when SEO output changes.
	 *
	 * @return void
	 */
	public function purge_super_rocket_cache() {
		if ( ! class_exists( '\SuperRocket\Plugin' ) ) {
			return;
		}

		try {
			$plugin = \SuperRocket\Plugin::instance();

			if ( method_exists( $plugin, 'page_cache' ) ) {
				$page_cache = $plugin->page_cache();

				if ( method_exists( $page_cache, 'purge_all' ) ) {
					$page_cache->purge_all();
				}
			}

			$this->purge_super_rocket_static_cache();
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Clears Super Rocket static HTML files when Apache static cache is enabled.
	 *
	 * @return void
	 */
	private function purge_super_rocket_static_cache() {
		$root = trailingslashit( WP_CONTENT_DIR ) . 'cache/super-rocket-static';

		if ( ! is_dir( $root ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file ) {
			if ( $file->isDir() ) {
				@rmdir( $file->getPathname() );
			} else {
				@unlink( $file->getPathname() );
			}
		}

		@rmdir( $root );
	}

	/**
	 * Returns whether the plugin is enabled.
	 *
	 * @return bool
	 */
	public function enabled() {
		return (bool) $this->setting( 'enabled', 1 );
	}

	/**
	 * Returns the AI client.
	 *
	 * @return Super_SEO_AI
	 */
	public function ai() {
		return $this->ai;
	}
}
