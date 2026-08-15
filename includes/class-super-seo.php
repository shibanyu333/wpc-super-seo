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
	 * Option holding API keys, stored separately and never autoloaded.
	 */
	const CREDENTIALS_OPTION = 'super_seo_credentials';

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private $settings = null;

	/**
	 * Cached credentials.
	 *
	 * @var array|null
	 */
	private $credentials = null;

	/**
	 * Vision service.
	 *
	 * @var Super_SEO_Vision|null
	 */
	private $vision = null;

	/**
	 * AI client.
	 *
	 * @var Super_SEO_AI
	 */
	private $ai;

	/**
	 * Local audit service.
	 *
	 * @var Super_SEO_Audit|null
	 */
	private $audit = null;

	/**
	 * AI automation service.
	 *
	 * @var Super_SEO_Automation|null
	 */
	private $automation = null;

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
			add_option( SUPER_SEO_OPTION, self::default_settings(), '', true );
		} else {
			update_option( SUPER_SEO_OPTION, wp_parse_args( $existing, self::default_settings() ), true );
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
		wp_unschedule_hook( 'super_seo_run_article_cron' );
		wp_unschedule_hook( Super_SEO_Vision::AUTO_HOOK );
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
			'output_meta_keywords'             => 0,
			'default_keywords'                 => '',
			'default_description'              => '',
			'site_brand_name'                  => get_bloginfo( 'name' ),
			'pagespeed_mode'                   => 'balanced',
			'audit_urls'                       => '',
			'ai_provider'                      => 'deepseek',
			'ai_endpoint'                      => 'https://api.deepseek.com/chat/completions',
			'ai_model'                         => 'deepseek-chat',
			'ai_api_key'                       => '',
			'vision_enabled'                   => 1,
			'vision_provider'                  => 'anthropic',
			'vision_endpoint'                  => 'https://api.anthropic.com/v1/messages',
			'vision_model'                     => 'claude-opus-5',
			'vision_api_key'                   => '',
			'vision_language'                  => '',
			'vision_auto_on_upload'            => 0,
			'vision_overwrite_existing'        => 0,
			'vision_write_title'               => 1,
			'vision_write_caption'             => 0,
			'vision_max_edge'                  => 1024,
			'vision_batch_size'                => 5,
			'ai_temperature'                   => '0.4',
			'ai_language'                      => 'zh-CN',
			'ai_tone'                          => '专业、自然、适合 Google SEO',
			'ai_article_strategy'              => 'strict',
			'ai_publish_mode'                  => 'draft',
			'ai_article_frequency'             => 'manual',
			'ai_article_category'              => 0,
			'ai_article_min_words'             => 500,
			'ai_direct_publish_enabled'        => 0,
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
			'performance_auto_preload_image'   => 0,
			'performance_webp_uploads'         => 1,
			'performance_webp_rewrite'         => 1,
			'performance_minify_html'          => 0,
			'performance_accessibility_fixes'  => 0,
			'performance_security_headers'     => 1,
			'performance_dedupe_head'          => 1,
			'noindex_search'                   => 1,
		);
	}

	/**
	 * Setting keys that hold secrets and live in their own option.
	 *
	 * @return array
	 */
	public static function credential_keys() {
		return array( 'ai_api_key', 'vision_api_key' );
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

		$this->audit      = new Super_SEO_Audit( $this );
		$this->automation = new Super_SEO_Automation( $this, $this->ai );
		$this->vision     = new Super_SEO_Vision( $this, $this->ai );

		new Super_SEO_Meta( $this );
		new Super_SEO_Sitemap( $this );
		new Super_SEO_Performance( $this );

		if ( is_admin() ) {
			new Super_SEO_Admin( $this, $this->ai, $this->audit, $this->automation, $this->vision );
		}
	}

	/**
	 * Returns merged plugin settings.
	 *
	 * @return array
	 */
	public function settings() {
		if ( null === $this->settings ) {
			$stored = get_option( SUPER_SEO_OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();
			$stored = $this->migrate_credentials( $stored );

			$merged = wp_parse_args( $stored, self::default_settings() );

			foreach ( self::credential_keys() as $key ) {
				$merged[ $key ] = $this->credential( $key );
			}

			$this->settings = $merged;
		}

		return $this->settings;
	}

	/**
	 * Returns stored credentials.
	 *
	 * @return array
	 */
	private function credentials() {
		if ( null === $this->credentials ) {
			$stored            = get_option( self::CREDENTIALS_OPTION, array() );
			$this->credentials = is_array( $stored ) ? $stored : array();
		}

		return $this->credentials;
	}

	/**
	 * Returns one credential value.
	 *
	 * @param string $key Credential key.
	 * @return string
	 */
	public function credential( $key ) {
		$credentials = $this->credentials();

		return isset( $credentials[ $key ] ) ? (string) $credentials[ $key ] : '';
	}

	/**
	 * Moves legacy plaintext keys out of the autoloaded settings option.
	 *
	 * @param array $stored Stored settings.
	 * @return array
	 */
	private function migrate_credentials( array $stored ) {
		$moved = false;

		foreach ( self::credential_keys() as $key ) {
			if ( ! array_key_exists( $key, $stored ) ) {
				continue;
			}

			$value = trim( (string) $stored[ $key ] );

			if ( '' !== $value && '' === $this->credential( $key ) ) {
				$this->credentials          = $this->credentials();
				$this->credentials[ $key ]  = $value;
				$moved                      = true;
			}

			unset( $stored[ $key ] );
			$moved = true;
		}

		if ( $moved ) {
			update_option( self::CREDENTIALS_OPTION, $this->credentials(), false );
			update_option( SUPER_SEO_OPTION, $stored, true );
		}

		return $stored;
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
		$merged      = wp_parse_args( $settings, self::default_settings() );
		$credentials = $this->credentials();
		$public      = $merged;

		foreach ( self::credential_keys() as $key ) {
			$credentials[ $key ] = (string) $merged[ $key ];
			unset( $public[ $key ] );
		}

		$this->credentials = $credentials;
		$this->settings    = $merged;

		// Secrets are stored separately and never autoloaded on front-end requests.
		update_option( self::CREDENTIALS_OPTION, $credentials, false );
		update_option( SUPER_SEO_OPTION, $public, true );
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

	/**
	 * Returns the local audit service.
	 *
	 * @return Super_SEO_Audit|null
	 */
	public function audit() {
		return $this->audit;
	}

	/**
	 * Returns the automation service.
	 *
	 * @return Super_SEO_Automation|null
	 */
	public function automation() {
		return $this->automation;
	}

	/**
	 * Returns the image description service.
	 *
	 * @return Super_SEO_Vision|null
	 */
	public function vision() {
		return $this->vision;
	}
}
