<?php
/**
 * XML sitemap and robots.txt support.
 *
 * @package SuperSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates a lightweight XML sitemap and valid robots.txt.
 */
final class Super_SEO_Sitemap {
	/**
	 * Plugin instance.
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

		add_action( 'init', array( $this, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_filter( 'wp_sitemaps_enabled', array( $this, 'maybe_disable_core_sitemap' ) );
		add_filter( 'redirect_canonical', array( $this, 'disable_sitemap_canonical_redirect' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'maybe_output_sitemap' ) );
		add_filter( 'robots_txt', array( $this, 'filter_robots_txt' ), 20, 2 );
		add_action( 'save_post', array( $this, 'clear_cache' ) );
		add_action( 'deleted_post', array( $this, 'clear_cache' ) );
		add_action( 'created_term', array( $this, 'clear_cache' ) );
		add_action( 'edited_term', array( $this, 'clear_cache' ) );
		add_action( 'delete_term', array( $this, 'clear_cache' ) );
	}

	/**
	 * Adds sitemap rewrite rule.
	 *
	 * @return void
	 */
	public function add_rewrite_rule() {
		add_rewrite_rule( '^super-seo-sitemap\.xml/?$', 'index.php?super_seo_sitemap=1', 'top' );
	}

	/**
	 * Adds query var.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function add_query_var( $vars ) {
		$vars[] = 'super_seo_sitemap';

		return $vars;
	}

	/**
	 * Disables the core wp-sitemap.xml while the Super SEO sitemap is active.
	 *
	 * @param bool $enabled Whether core sitemaps are enabled.
	 * @return bool
	 */
	public function maybe_disable_core_sitemap( $enabled ) {
		if ( $this->plugin->enabled() && $this->plugin->setting( 'sitemap_enabled', 1 ) ) {
			return false;
		}

		return $enabled;
	}

	/**
	 * Outputs sitemap when requested.
	 *
	 * @return void
	 */
	public function maybe_output_sitemap() {
		if ( ! get_query_var( 'super_seo_sitemap' ) ) {
			return;
		}

		if ( ! $this->plugin->enabled() || ! $this->plugin->setting( 'sitemap_enabled', 1 ) ) {
			status_header( 404 );
			exit;
		}

		header( 'Content-Type: application/xml; charset=' . get_bloginfo( 'charset' ) );
		echo '<?xml version="1.0" encoding="' . esc_attr( get_bloginfo( 'charset' ) ) . "\"?>\n";
		echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

		foreach ( $this->entries() as $entry ) {
			echo "\t<url>\n";
			echo "\t\t<loc>" . Super_SEO_Helpers::esc_xml( $entry['loc'] ) . "</loc>\n";

			if ( ! empty( $entry['lastmod'] ) ) {
				echo "\t\t<lastmod>" . Super_SEO_Helpers::esc_xml( $entry['lastmod'] ) . "</lastmod>\n";
			}

			echo "\t\t<changefreq>" . Super_SEO_Helpers::esc_xml( $entry['changefreq'] ) . "</changefreq>\n";
			echo "\t\t<priority>" . Super_SEO_Helpers::esc_xml( $entry['priority'] ) . "</priority>\n";
			echo "\t</url>\n";
		}

		echo "</urlset>\n";
		exit;
	}

	/**
	 * Keeps the XML sitemap from being redirected to a trailing slash URL.
	 *
	 * @param string|false $redirect_url  Redirect URL.
	 * @param string       $requested_url Requested URL.
	 * @return string|false
	 */
	public function disable_sitemap_canonical_redirect( $redirect_url, $requested_url ) {
		$path = (string) wp_parse_url( $requested_url, PHP_URL_PATH );

		if ( preg_match( '#/super-seo-sitemap\.xml/?$#', $path ) ) {
			return false;
		}

		return $redirect_url;
	}

	/**
	 * Preserves robots.txt output and appends the Super SEO sitemap when needed.
	 *
	 * @param string $output Existing output.
	 * @param bool   $public Whether site is public.
	 * @return string
	 */
	public function filter_robots_txt( $output, $public ) {
		if ( ! $this->plugin->enabled() || ! $this->plugin->setting( 'robots_enabled', 1 ) ) {
			return $output;
		}

		if ( ! $public ) {
			return "User-agent: *\nDisallow: /\n";
		}

		$output = trim( (string) $output );

		if ( '' === $output ) {
			$output = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php";
		}

		if ( $this->plugin->setting( 'sitemap_enabled', 1 ) ) {
			$sitemap = home_url( '/super-seo-sitemap.xml' );

			if ( ! preg_match( '#^Sitemap:\s*' . preg_quote( $sitemap, '#' ) . '\s*$#mi', $output ) ) {
				$output .= "\nSitemap: " . $sitemap;
			}
		}

		return $output . "\n";
	}

	/**
	 * Clears cached sitemap entries.
	 *
	 * @return void
	 */
	public function clear_cache() {
		delete_transient( 'super_seo_sitemap_entries' );
	}

	/**
	 * Builds sitemap entries.
	 *
	 * @return array
	 */
	private function entries() {
		$cached = get_transient( 'super_seo_sitemap_entries' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$post_entries = array();

		$query = new WP_Query(
			array(
				'post_type'              => Super_SEO_Helpers::public_post_types(),
				'post_status'            => 'publish',
				'posts_per_page'         => max( 1, (int) apply_filters( 'super_seo_sitemap_post_limit', 1500 ) ),
				'fields'                 => 'ids',
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// Pages marked noindex must not be advertised to crawlers.
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'     => '_super_seo_noindex',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_super_seo_noindex',
						'value'   => '1',
						'compare' => '!=',
					),
				),
			)
		);

		foreach ( $query->posts as $post_id ) {
			$post_entries[] = array(
				'loc'        => get_permalink( $post_id ),
				'lastmod'    => get_post_modified_time( 'c', true, $post_id ),
				'changefreq' => 'weekly',
				'priority'   => '0.8',
			);
		}

		$entries = array(
			array(
				'loc'        => home_url( '/' ),
				'lastmod'    => $this->site_lastmod(),
				'changefreq' => 'daily',
				'priority'   => '1.0',
			),
		);

		$entries = array_merge( $entries, $post_entries );

		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		$terms      = get_terms(
			array(
				'taxonomy'   => $taxonomies,
				'hide_empty' => true,
				'number'     => max( 1, (int) apply_filters( 'super_seo_sitemap_term_limit', 1000 ) ),
			)
		);

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( '1' === (string) get_term_meta( $term->term_id, '_super_seo_noindex', true ) ) {
					continue;
				}

				$link = get_term_link( $term );

				if ( is_wp_error( $link ) ) {
					continue;
				}

				$entries[] = array(
					'loc'        => $link,
					'lastmod'    => '',
					'changefreq' => 'weekly',
					'priority'   => '0.7',
				);
			}
		}

		set_transient( 'super_seo_sitemap_entries', $entries, HOUR_IN_SECONDS );

		return $entries;
	}

	/**
	 * Returns the latest published content modification time.
	 *
	 * @return string
	 */
	private function site_lastmod() {
		$lastmod = get_lastpostmodified( 'GMT' );

		if ( ! $lastmod ) {
			return '';
		}

		return mysql2date( 'c', $lastmod, false );
	}
}
