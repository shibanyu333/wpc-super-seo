<?php
/**
 * Front-end SEO metadata output.
 *
 * @package SuperSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outputs title overrides, meta tags, Open Graph and schema.
 */
final class Super_SEO_Meta {
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

		add_action( 'wp_head', array( $this, 'maybe_disable_core_canonical' ), 0 );
		add_filter( 'pre_get_document_title', array( $this, 'filter_document_title' ), 20 );
		add_action( 'wp_head', array( $this, 'output_head' ), 99 );
	}

	/**
	 * Disables WordPress core canonical only while Super SEO is actively outputting one.
	 *
	 * @return void
	 */
	public function maybe_disable_core_canonical() {
		if ( ! $this->plugin->enabled() || is_admin() || is_feed() || is_robots() ) {
			return;
		}

		remove_action( 'wp_head', 'rel_canonical' );
	}

	/**
	 * Overrides the document title when a custom SEO title exists.
	 *
	 * @param string $title Original title.
	 * @return string
	 */
	public function filter_document_title( $title ) {
		if ( ! $this->plugin->enabled() || is_admin() ) {
			return $title;
		}

		$custom = $this->custom_title();

		return '' !== $custom ? $custom : $title;
	}

	/**
	 * Outputs meta tags.
	 *
	 * @return void
	 */
	public function output_head() {
		if ( ! $this->plugin->enabled() || is_admin() || is_feed() || is_robots() ) {
			return;
		}

		$title       = $this->title();
		$description = $this->description();
		$keywords    = $this->keywords();
		$canonical   = $this->canonical();

		echo "\n<!-- Super SEO 智能优化 -->\n";

		$robots = $this->robots_directive();

		if ( '' !== $robots ) {
			printf( "<meta name=\"robots\" content=\"%s\">\n", esc_attr( $robots ) );
		}

		if ( '' !== $description ) {
			printf( "<meta name=\"description\" content=\"%s\">\n", esc_attr( $description ) );
		}

		if ( '' !== $keywords && $this->plugin->setting( 'output_meta_keywords', 0 ) ) {
			printf( "<meta name=\"keywords\" content=\"%s\">\n", esc_attr( $keywords ) );
		}

		if ( '' !== $canonical ) {
			printf( "<link rel=\"canonical\" href=\"%s\">\n", esc_url( $canonical ) );
		}

		printf( "<meta property=\"og:site_name\" content=\"%s\">\n", esc_attr( $this->brand() ) );
		printf( "<meta property=\"og:title\" content=\"%s\">\n", esc_attr( $title ) );

		if ( '' !== $description ) {
			printf( "<meta property=\"og:description\" content=\"%s\">\n", esc_attr( $description ) );
		}

		if ( '' !== $canonical ) {
			printf( "<meta property=\"og:url\" content=\"%s\">\n", esc_url( $canonical ) );
		}

		printf( "<meta property=\"og:type\" content=\"%s\">\n", esc_attr( $this->og_type() ) );
		echo "<meta name=\"twitter:card\" content=\"summary_large_image\">\n";

		$image = $this->image();
		if ( '' !== $image ) {
			printf( "<meta property=\"og:image\" content=\"%s\">\n", esc_url( $image ) );
		}

		if ( $this->plugin->setting( 'schema_enabled', 1 ) ) {
			$this->output_schema( $title, $description, $canonical, $image );
		}

		echo "<!-- /Super SEO 智能优化 -->\n";
	}

	/**
	 * Returns the robots directive for the current request.
	 *
	 * @return string
	 */
	private function robots_directive() {
		if ( self::is_noindex_object() ) {
			return 'noindex,follow';
		}

		if ( ( is_search() || is_404() ) && $this->plugin->setting( 'noindex_search', 1 ) ) {
			return 'noindex,follow';
		}

		return '';
	}

	/**
	 * Whether the queried post or term is marked noindex.
	 *
	 * @return bool
	 */
	public static function is_noindex_object() {
		if ( is_singular() ) {
			return '1' === (string) get_post_meta( get_queried_object_id(), '_super_seo_noindex', true );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			return '1' === (string) get_term_meta( get_queried_object_id(), '_super_seo_noindex', true );
		}

		return false;
	}

	/**
	 * Returns current SEO title.
	 *
	 * @return string
	 */
	private function title() {
		$custom = $this->custom_title();

		if ( '' !== $custom ) {
			return $custom;
		}

		if ( is_singular() ) {
			return Super_SEO_Helpers::limit_text( single_post_title( '', false ) . ' - ' . $this->brand(), 70 );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			return Super_SEO_Helpers::limit_text( single_term_title( '', false ) . ' - ' . $this->brand(), 70 );
		}

		return Super_SEO_Helpers::limit_text( wp_get_document_title(), 70 );
	}

	/**
	 * Returns custom title.
	 *
	 * @return string
	 */
	private function custom_title() {
		if ( is_singular() ) {
			return Super_SEO_Helpers::limit_text( get_post_meta( get_queried_object_id(), '_super_seo_title', true ), 70 );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			return Super_SEO_Helpers::limit_text( get_term_meta( get_queried_object_id(), '_super_seo_title', true ), 70 );
		}

		return '';
	}

	/**
	 * Returns current meta description.
	 *
	 * @return string
	 */
	private function description() {
		$description = '';

		if ( is_singular() ) {
			$post_id     = get_queried_object_id();
			$description = get_post_meta( $post_id, '_super_seo_description', true );

			if ( '' === $description && $this->plugin->setting( 'auto_meta_description', 1 ) ) {
				$post = get_post( $post_id );

				if ( $post ) {
					$description = has_excerpt( $post ) ? get_the_excerpt( $post ) : Super_SEO_Helpers::excerpt_from_content( $post->post_content, 155 );
				}
			}
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term_id     = get_queried_object_id();
			$term        = get_queried_object();
			$description = get_term_meta( $term_id, '_super_seo_description', true );

			if ( '' === $description && $term && $this->plugin->setting( 'auto_meta_description', 1 ) ) {
				$description = term_description( $term_id );

				if ( '' === Super_SEO_Helpers::clean_text( $description ) ) {
					$description = sprintf( '%s：查看 %s 相关产品、文章和解决方案，了解参数、应用场景和询价信息。', $this->brand(), $term->name );
				}
			}
		}

		if ( '' === Super_SEO_Helpers::clean_text( $description ) ) {
			$description = $this->plugin->setting( 'default_description', '' );
		}

		if ( '' === Super_SEO_Helpers::clean_text( $description ) ) {
			$description = get_bloginfo( 'description' );
		}

		return Super_SEO_Helpers::limit_text( $description, 165 );
	}

	/**
	 * Returns keywords.
	 *
	 * @return string
	 */
	private function keywords() {
		$keywords = '';

		if ( is_singular() ) {
			$post_id  = get_queried_object_id();
			$keywords = get_post_meta( $post_id, '_super_seo_keywords', true );

			if ( '' === $keywords && 'post' === get_post_type( $post_id ) ) {
				$keywords = wp_get_post_terms( $post_id, 'post_tag', array( 'fields' => 'names' ) );
				$keywords = is_wp_error( $keywords ) ? '' : $keywords;
			}
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$keywords = get_term_meta( get_queried_object_id(), '_super_seo_keywords', true );
		}

		if ( '' === Super_SEO_Helpers::clean_text( is_array( $keywords ) ? implode( ',', $keywords ) : $keywords ) ) {
			$keywords = $this->plugin->setting( 'default_keywords', '' );
		}

		return Super_SEO_Helpers::normalize_keywords( $keywords, 10 );
	}

	/**
	 * Returns canonical URL.
	 *
	 * @return string
	 */
	private function canonical() {
		if ( is_404() || is_search() ) {
			return '';
		}

		if ( is_singular() ) {
			return get_permalink( get_queried_object_id() );
		}

		if ( is_paged() ) {
			return get_pagenum_link( max( 1, (int) get_query_var( 'paged' ) ) );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$link = get_term_link( get_queried_object() );

			return is_wp_error( $link ) ? '' : $link;
		}

		if ( is_front_page() || is_home() ) {
			return home_url( '/' );
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		return home_url( $request_uri );
	}

	/**
	 * Returns featured image URL when available.
	 *
	 * @return string
	 */
	private function image() {
		if ( is_singular() && has_post_thumbnail( get_queried_object_id() ) ) {
			return (string) get_the_post_thumbnail_url( get_queried_object_id(), 'large' );
		}

		return '';
	}

	/**
	 * Outputs JSON-LD schema.
	 *
	 * @param string $title       Title.
	 * @param string $description Description.
	 * @param string $canonical   Canonical URL.
	 * @param string $image       Image URL.
	 * @return void
	 */
	private function output_schema( $title, $description, $canonical, $image ) {
		$graph = array(
			array(
				'@type'           => 'WebSite',
				'@id'             => home_url( '/#website' ),
				'url'             => home_url( '/' ),
				'name'            => $this->brand(),
				'description'     => get_bloginfo( 'description' ),
				'potentialAction' => array(
					'@type'       => 'SearchAction',
					'target'      => home_url( '/?s={search_term_string}' ),
					'query-input' => 'required name=search_term_string',
				),
			),
		);

		if ( is_singular() && ! is_front_page() ) {
			$type = 'product' === get_post_type() ? 'Product' : 'Article';
			$item = array(
				'@type'            => $type,
				'@id'              => $canonical . '#content',
				'description'      => $description,
				'url'              => $canonical,
				'mainEntityOfPage' => $canonical,
			);

			if ( 'Product' === $type ) {
				$item['name'] = $title;
				$item         = array_merge( $item, $this->product_schema_data( $canonical ) );
			} else {
				$item['headline'] = $title;
			}

			if ( '' !== $image ) {
				$item['image'] = $image;
			}

			if ( 'Article' === $type ) {
				$item['datePublished'] = get_the_date( DATE_W3C );
				$item['dateModified']  = get_the_modified_date( DATE_W3C );
				$item['author']        = array(
					'@type' => 'Person',
					'name'  => $this->author_name(),
				);
			}

			$graph[] = $item;
		} elseif ( is_archive() ) {
			$graph[] = array(
				'@type'       => 'CollectionPage',
				'@id'         => $canonical . '#collection',
				'name'        => $title,
				'description' => $description,
				'url'         => $canonical,
			);
		}

		$schema = array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);

		printf(
			"<script type=\"application/ld+json\">%s</script>\n",
			Super_SEO_Helpers::json_for_html_script( $schema )
		);
	}

	/**
	 * Returns WooCommerce-backed Product schema data when available.
	 *
	 * @param string $canonical Canonical URL.
	 * @return array
	 */
	private function product_schema_data( $canonical ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$product = wc_get_product( get_queried_object_id() );

		if ( ! $product ) {
			return array();
		}

		$data = array();
		$sku  = $product->get_sku();

		if ( '' !== $sku ) {
			$data['sku'] = $sku;
		}

		$offers = array(
			'@type'         => 'Offer',
			'url'           => $canonical,
			'itemCondition' => 'https://schema.org/NewCondition',
			'availability'  => $this->product_availability( $product ),
		);

		$price = $product->get_price();

		if (
			'' !== $price
			&& function_exists( 'get_woocommerce_currency' )
			&& function_exists( 'wc_format_decimal' )
			&& function_exists( 'wc_get_price_decimals' )
		) {
			$offers['price']         = wc_format_decimal( $price, wc_get_price_decimals() );
			$offers['priceCurrency'] = get_woocommerce_currency();
		}

		$data['offers'] = $offers;

		if ( $product->get_average_rating() > 0 && $product->get_review_count() > 0 ) {
			$data['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => (string) $product->get_average_rating(),
				'reviewCount' => (int) $product->get_review_count(),
			);
		}

		return $data;
	}

	/**
	 * Maps WooCommerce stock state to Schema.org availability.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function product_availability( $product ) {
		if ( $product->is_in_stock() ) {
			return 'https://schema.org/InStock';
		}

		if ( $product->backorders_allowed() ) {
			return 'https://schema.org/BackOrder';
		}

		return 'https://schema.org/OutOfStock';
	}

	/**
	 * Brand name.
	 *
	 * @return string
	 */
	private function brand() {
		$brand = $this->plugin->setting( 'site_brand_name', get_bloginfo( 'name' ) );

		return '' !== Super_SEO_Helpers::clean_text( $brand ) ? $brand : get_bloginfo( 'name' );
	}

	/**
	 * Returns a non-empty author name for schema.
	 *
	 * @return string
	 */
	private function author_name() {
		$name = get_the_author_meta( 'display_name', (int) get_post_field( 'post_author', get_queried_object_id() ) );

		return '' !== Super_SEO_Helpers::clean_text( $name ) ? $name : $this->brand();
	}

	/**
	 * Returns Open Graph type for the current page.
	 *
	 * @return string
	 */
	private function og_type() {
		if ( is_singular() && ! is_front_page() ) {
			return 'product' === get_post_type() ? 'product' : 'article';
		}

		return 'website';
	}
}
