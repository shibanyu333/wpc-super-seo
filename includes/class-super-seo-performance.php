<?php
/**
 * PageSpeed-focused front-end optimizations.
 *
 * @package SuperSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds conservative performance and Lighthouse fixes.
 */
final class Super_SEO_Performance {
	/**
	 * Plugin instance.
	 *
	 * @var Super_SEO
	 */
	private $plugin;

	/**
	 * Whether a high-priority image has already been selected.
	 *
	 * @var bool
	 */
	private $priority_image_assigned = false;

	/**
	 * Constructor.
	 *
	 * @param Super_SEO $plugin Plugin.
	 */
	public function __construct( Super_SEO $plugin ) {
		$this->plugin = $plugin;

		add_action( 'init', array( $this, 'disable_unneeded_assets' ) );
		add_filter( 'script_loader_tag', array( $this, 'defer_script' ), 20, 3 );
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'image_attributes' ), 20, 3 );
		add_filter( 'image_editor_output_format', array( $this, 'image_editor_output_format' ) );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'generate_webp_versions' ), 20, 2 );
		add_filter( 'wp_get_attachment_image_src', array( $this, 'maybe_replace_attachment_src' ), 20, 4 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'maybe_replace_srcset' ), 20, 5 );
		add_action( 'delete_attachment', array( $this, 'delete_webp_versions' ) );
		add_action( 'send_headers', array( $this, 'security_headers' ) );
		add_action( 'send_headers', array( $this, 'webp_vary_header' ) );
		add_action( 'template_redirect', array( $this, 'start_output_buffer' ), 0 );
		add_action( 'wp_head', array( $this, 'accessibility_css' ), 90 );
		add_action( 'wp_footer', array( $this, 'accessibility_js' ), 90 );
	}

	/**
	 * Removes assets that often add small blocking costs.
	 *
	 * @return void
	 */
	public function disable_unneeded_assets() {
		if ( ! $this->plugin->enabled() || is_admin() ) {
			return;
		}

		if ( $this->plugin->setting( 'performance_disable_emojis', 1 ) ) {
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
			remove_action( 'admin_print_styles', 'print_emoji_styles' );
			remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
			remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
			remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		}

		if ( $this->plugin->setting( 'performance_disable_embeds', 1 ) ) {
			wp_deregister_script( 'wp-embed' );
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
			remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		}

		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );
	}

	/**
	 * Adds defer to safe scripts.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Script handle.
	 * @param string $src    Script src.
	 * @return string
	 */
	public function defer_script( $tag, $handle, $src ) {
		if ( ! $this->plugin->enabled() || is_admin() || ! $this->mode_allows( 'defer_js' ) || ! $this->plugin->setting( 'performance_defer_js', 1 ) || empty( $src ) ) {
			return $tag;
		}

		if ( $this->super_rocket_handles( array( 'defer_js' ) ) ) {
			return $tag;
		}

		if ( false !== strpos( $tag, ' defer' ) || false !== strpos( $tag, ' async' ) || false !== strpos( $tag, 'type="module"' ) ) {
			return $tag;
		}

		// Inline "after" fragments run immediately and would break if the file they depend on is deferred.
		if ( wp_scripts()->get_data( $handle, 'after' ) ) {
			return $tag;
		}

		foreach ( $this->defer_exclusions() as $excluded ) {
			if ( '' !== $excluded && false !== strpos( $handle, $excluded ) ) {
				return $tag;
			}
		}

		return preg_replace( '/<script(?=[^>]*\bsrc=)/i', '<script defer', $tag, 1 );
	}

	/**
	 * Improves image attributes.
	 *
	 * @param array        $attr       Attributes.
	 * @param WP_Post     $attachment Attachment.
	 * @param string|array $size       Image size.
	 * @return array
	 */
	public function image_attributes( $attr, $attachment, $size ) {
		if ( ! $this->plugin->enabled() || is_admin() ) {
			return $attr;
		}

		if ( $this->plugin->setting( 'performance_lazy_images', 1 ) ) {
			$attr['decoding'] = $attr['decoding'] ?? 'async';

			if ( ! $this->will_preload_image() && ! $this->priority_image_assigned && $this->plugin->setting( 'performance_fetchpriority', 1 ) && ! $this->is_low_priority_image_attr( $attr ) ) {
				$attr['fetchpriority'] = 'high';
				$attr['loading']       = 'eager';
				$this->priority_image_assigned = true;
			} else {
				$attr['loading'] = $attr['loading'] ?? 'lazy';
			}
		}

		if ( empty( $attr['alt'] ) && $attachment instanceof WP_Post ) {
			$alt = get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true );

			if ( '' === $alt ) {
				$alt = $attachment->post_title;
			}

			$attr['alt'] = Super_SEO_Helpers::limit_text( $alt, 120 );
		}

		return $attr;
	}

	/**
	 * Makes future generated image sizes WebP.
	 *
	 * @param array $formats Formats.
	 * @return array
	 */
	public function image_editor_output_format( $formats ) {
		if ( ! $this->plugin->enabled() || ! $this->mode_allows( 'webp' ) || ! $this->plugin->setting( 'performance_webp_uploads', 1 ) ) {
			return $formats;
		}

		if ( $this->super_rocket_handles( array( 'image_webp_conversion' ) ) ) {
			return $formats;
		}

		$formats['image/jpeg'] = 'image/webp';
		$formats['image/png']  = 'image/webp';

		return $formats;
	}

	/**
	 * Creates WebP copies for the original and generated sizes.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array
	 */
	public function generate_webp_versions( $metadata, $attachment_id ) {
		if ( ! $this->plugin->enabled() || ! $this->mode_allows( 'webp' ) || ! $this->plugin->setting( 'performance_webp_uploads', 1 ) || ! is_array( $metadata ) ) {
			return $metadata;
		}

		if ( $this->super_rocket_handles( array( 'image_webp_conversion' ) ) ) {
			return $metadata;
		}

		$full = get_attached_file( $attachment_id );

		if ( ! $full || ! file_exists( $full ) ) {
			return $metadata;
		}

		$map = array();
		$webp = $this->convert_to_webp( $full );

		if ( $webp ) {
			$map['full'] = $this->file_to_url( $webp );
		}

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$base_dir = trailingslashit( dirname( $full ) );

			foreach ( $metadata['sizes'] as $size_name => $size_data ) {
				if ( empty( $size_data['file'] ) ) {
					continue;
				}

				$size_webp = $this->convert_to_webp( $base_dir . $size_data['file'] );

				if ( $size_webp ) {
					$map[ $size_name ] = $this->file_to_url( $size_webp );
				}
			}
		}

		if ( ! empty( $map ) ) {
			update_post_meta( $attachment_id, '_super_seo_webp_map', $map );
		}

		return $metadata;
	}

	/**
	 * Replaces attachment image URL with WebP when available.
	 *
	 * @param array|false  $image         Image data.
	 * @param int          $attachment_id Attachment ID.
	 * @param string|array $size          Size.
	 * @param bool         $icon          Icon flag.
	 * @return array|false
	 */
	public function maybe_replace_attachment_src( $image, $attachment_id, $size, $icon ) {
		if ( ! $image || $icon || ! $this->webp_rewrite_active() ) {
			return $image;
		}

		$map = get_post_meta( $attachment_id, '_super_seo_webp_map', true );

		if ( ! is_array( $map ) || empty( $map ) ) {
			return $image;
		}

		$key = is_string( $size ) ? $size : 'full';

		if ( empty( $map[ $key ] ) ) {
			$key = 'full';
		}

		if ( ! empty( $map[ $key ] ) ) {
			$image[0] = esc_url_raw( $map[ $key ] );
		}

		return $image;
	}

	/**
	 * Replaces srcset candidate URLs with WebP when available.
	 *
	 * @param array  $sources       Srcset sources keyed by width.
	 * @param array  $size_array    Requested width and height.
	 * @param string $image_src     Image src.
	 * @param array  $image_meta    Attachment metadata.
	 * @param int    $attachment_id Attachment ID.
	 * @return array
	 */
	public function maybe_replace_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		if ( ! is_array( $sources ) || ! $this->webp_rewrite_active() ) {
			return $sources;
		}

		$map = get_post_meta( $attachment_id, '_super_seo_webp_map', true );

		if ( ! is_array( $map ) || empty( $map ) ) {
			return $sources;
		}

		foreach ( $sources as $width => $source ) {
			if ( empty( $source['url'] ) ) {
				continue;
			}

			$candidate = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $source['url'] );

			if ( $candidate !== $source['url'] && in_array( $candidate, $map, true ) ) {
				$sources[ $width ]['url'] = $candidate;
			}
		}

		return $sources;
	}

	/**
	 * Deletes WebP copies when the attachment is removed.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function delete_webp_versions( $attachment_id ) {
		$map = get_post_meta( $attachment_id, '_super_seo_webp_map', true );

		if ( ! is_array( $map ) || empty( $map ) ) {
			return;
		}

		$uploads = wp_get_upload_dir();

		if ( empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return;
		}

		foreach ( $map as $url ) {
			if ( ! is_string( $url ) || 0 !== strpos( $url, $uploads['baseurl'] ) ) {
				continue;
			}

			$path = str_replace( $uploads['baseurl'], $uploads['basedir'], $url );

			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Adds small security headers.
	 *
	 * @return void
	 */
	public function security_headers() {
		if ( ! $this->plugin->enabled() || ! $this->plugin->setting( 'performance_security_headers', 1 ) || headers_sent() || is_admin() ) {
			return;
		}

		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'X-Frame-Options: SAMEORIGIN' );
	}

	/**
	 * Starts output buffering when HTML needs final-pass optimization.
	 *
	 * @return void
	 */
	public function start_output_buffer() {
		if ( ! $this->plugin->enabled() || is_admin() || is_feed() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( ! $this->needs_html_buffer() ) {
			return;
		}

		ob_start( array( $this, 'process_html' ) );
	}

	/**
	 * Processes final HTML.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public function process_html( $html ) {
		if ( false === stripos( $html, '<html' ) || false === stripos( $html, '</head>' ) ) {
			return $html;
		}

		$html = Super_SEO_Helpers::dedupe_head_seo_tags( $html );
		$html = $this->inject_preload_image( $html );

		if ( $this->mode_allows( 'minify' ) && $this->plugin->setting( 'performance_minify_html', 0 ) && ! $this->super_rocket_handles( array( 'html_minify' ) ) ) {
			$html = $this->minify_html( $html );
		}

		return $html;
	}

	/**
	 * Outputs CSS for report-specific accessibility nits.
	 *
	 * @return void
	 */
	public function accessibility_css() {
		if ( ! $this->plugin->enabled() || ! $this->mode_allows( 'accessibility' ) || ! $this->plugin->setting( 'performance_accessibility_fixes', 1 ) || is_admin() ) {
			return;
		}
		?>
<style id="super-seo-accessibility-fixes">
.mi-section-sub,.mi-inquiry-contact span,[data-mi-footer-note]{color:#52606a!important}
.gc-factory-band .mi-section-label{color:#9be7b0!important}
.ms-product-spec__label,.product_meta,.product_meta span{color:#5d554b!important}
.product_meta a{color:#73571d!important}
#ms-footer .ms-footer__bottom,#ms-footer .ms-footer__bottom span,#ms-footer .ms-footer__bottom a{color:#a7a7a7!important}
.mi-whatsapp-float__button:focus-visible,a:focus-visible,button:focus-visible{outline:3px solid #1d7f46!important;outline-offset:3px!important}
</style>
		<?php
	}

	/**
	 * Outputs JS for empty icon buttons flagged by Lighthouse.
	 *
	 * @return void
	 */
	public function accessibility_js() {
		if ( ! $this->plugin->enabled() || ! $this->mode_allows( 'accessibility' ) || ! $this->plugin->setting( 'performance_accessibility_fixes', 1 ) || is_admin() ) {
			return;
		}
		?>
<script id="super-seo-accessibility-js">
(function(){var buttons=document.querySelectorAll('button:not([aria-label])');for(var i=0;i<buttons.length;i++){var b=buttons[i];if(!b.textContent.trim()){if(b.matches('.mi-whatsapp-float__button,[data-mi-whatsapp-toggle]')){b.setAttribute('aria-label','Open WhatsApp contact panel');}else{b.setAttribute('aria-label','Open control');}}}var logos=document.querySelectorAll('.ms-logo[aria-label]');for(var j=0;j<logos.length;j++){var text=logos[j].textContent.replace(/\s+/g,' ').trim();if(text){logos[j].setAttribute('aria-label',text);}}})();
</script>
		<?php
	}

	/**
	 * Injects a preload link for manual or detected hero images.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function inject_preload_image( $html ) {
		$url        = '';
		$manual_url = Super_SEO_Helpers::absolute_url( $this->plugin->setting( 'performance_preload_hero_image', '' ) );

		if ( '' !== $manual_url && $this->mode_allows( 'manual_preload' ) ) {
			$url = $manual_url;
		}

		if ( '' === $url && $this->mode_allows( 'auto_preload' ) && $this->plugin->setting( 'performance_auto_preload_image', 1 ) ) {
			$url = $this->discover_first_image_url( $html );
		}

		if ( '' === $url || $this->has_image_preload( $html, $url ) ) {
			return $html;
		}

		$html = $this->promote_preloaded_image( $html, $url );
		$link = sprintf( "<link rel=\"preload\" as=\"image\" href=\"%s\" fetchpriority=\"high\">\n", esc_url( $url ) );

		return preg_replace( '/<\/head>/i', $link . '</head>', $html, 1 );
	}

	/**
	 * Finds first likely content image or CSS background image.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function discover_first_image_url( $html ) {
		$image_tags = array();

		if ( preg_match_all( '/<img\b[^>]*>/i', $html, $matches ) ) {
			$image_tags = $matches[0];

			foreach ( $image_tags as $tag ) {
				if ( ! preg_match( '/\b(?:wp-post-image|woocommerce-product-gallery|product-gallery|product-main|ms-pg-img|hero|featured)\b/i', $tag ) || $this->is_low_priority_image_tag( $tag ) ) {
					continue;
				}

				$url = $this->image_tag_src( $tag );

				if ( '' !== $url ) {
					return $url;
				}
			}
		}

		if ( preg_match( '/url\((?:\'|")?([^\'")]+?\.(?:jpe?g|png|webp|avif))(?:\'|")?\)/i', $html, $matches ) ) {
			$url = $this->normalize_image_url( $matches[1] );

			if ( '' !== $url ) {
				return $url;
			}
		}

		if ( ! empty( $image_tags ) ) {
			foreach ( $image_tags as $tag ) {
				if ( $this->is_low_priority_image_tag( $tag ) ) {
					continue;
				}

				$url = $this->image_tag_src( $tag );

				if ( '' !== $url ) {
					return $url;
				}
			}
		}

		return '';
	}

	/**
	 * Adds high-priority attributes to the same image we preload.
	 *
	 * @param string $html HTML.
	 * @param string $url  Image URL.
	 * @return string
	 */
	private function promote_preloaded_image( $html, $url ) {
		$updated = false;

		return preg_replace_callback(
			'/<img\b[^>]*>/i',
			function ( $matches ) use ( $url, &$updated ) {
				$tag = $matches[0];

				if ( $updated || $this->image_tag_src( $tag ) !== $url ) {
					return $tag;
				}

				$updated = true;
				$tag = preg_replace( '/\s(?:fetchpriority|loading|decoding)=["\'][^"\']*["\']/i', '', $tag );

				return preg_replace( '/\s*\/?>$/', ' fetchpriority="high" loading="eager" decoding="async">', $tag, 1 );
			},
			$html
		);
	}

	/**
	 * Checks whether the exact image is already preloaded.
	 *
	 * @param string $html HTML.
	 * @param string $url  Image URL.
	 * @return bool
	 */
	private function has_image_preload( $html, $url ) {
		if ( ! preg_match_all( '/<link\b[^>]*\brel=["\']preload["\'][^>]*>/i', $html, $matches ) ) {
			return false;
		}

		foreach ( $matches[0] as $tag ) {
			if ( false !== strpos( $tag, 'as="image"' ) && false !== strpos( $tag, 'href="' . esc_url( $url ) . '"' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the normalized src from an image tag.
	 *
	 * @param string $tag Image tag.
	 * @return string
	 */
	private function image_tag_src( $tag ) {
		if ( ! preg_match( '/\bsrc=["\']([^"\']+?\.(?:jpe?g|png|webp|avif))(?:\?[^"\']*)?["\']/i', $tag, $matches ) ) {
			return '';
		}

		return $this->normalize_image_url( $matches[1] );
	}

	/**
	 * Normalizes an image URL and rejects non-LCP candidates.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private function normalize_image_url( $url ) {
		$url = html_entity_decode( trim( $url ), ENT_QUOTES, get_bloginfo( 'charset' ) );

		if ( '' === $url || false !== strpos( $url, 'data:' ) || false !== strpos( $url, '.svg' ) ) {
			return '';
		}

		return Super_SEO_Helpers::absolute_url( $url );
	}

	/**
	 * Skips logos, icons, related thumbnails and page chrome.
	 *
	 * @param string $tag Image tag.
	 * @return bool
	 */
	private function is_low_priority_image_tag( $tag ) {
		return (bool) preg_match( '/logo|avatar|icon|sprite|placeholder|tracking|pixel|spinner|related|sidebar|thumbnail|thumb|payment|social/i', $tag );
	}

	/**
	 * Skips attachment images that should not be treated as LCP.
	 *
	 * @param array $attr Image attributes.
	 * @return bool
	 */
	private function is_low_priority_image_attr( array $attr ) {
		$haystack = implode( ' ', array_map( 'strval', $attr ) );

		return (bool) preg_match( '/logo|avatar|icon|sprite|placeholder|tracking|pixel|spinner|related|sidebar|thumbnail|thumb|payment|social/i', $haystack );
	}

	/**
	 * Lightweight HTML minification with script/style placeholders.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function minify_html( $html ) {
		$placeholders = array();
		$html = preg_replace_callback(
			'/<(script|style|pre|textarea)\b[^>]*>.*?<\/\1>/is',
			static function ( $matches ) use ( &$placeholders ) {
				$key                  = '___SUPER_SEO_KEEP_' . count( $placeholders ) . '___';
				$placeholders[ $key ] = $matches[0];

				return $key;
			},
			$html
		);

		$html = preg_replace( '/<!--(?!\[if).*?-->/s', '', $html );
		$html = preg_replace( '/>\s+</', '><', $html );
		$html = preg_replace( '/\s{2,}/', ' ', $html );

		foreach ( $placeholders as $key => $value ) {
			$html = str_replace( $key, $value, $html );
		}

		return trim( $html );
	}

	/**
	 * Converts an image file to WebP.
	 *
	 * @param string $file File path.
	 * @return string
	 */
	private function convert_to_webp( $file ) {
		if ( ! file_exists( $file ) || ! preg_match( '/\.(jpe?g|png)$/i', $file ) ) {
			return '';
		}

		$destination = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $file );

		if ( file_exists( $destination ) ) {
			return $destination;
		}

		$editor = wp_get_image_editor( $file );

		if ( is_wp_error( $editor ) ) {
			return '';
		}

		if ( method_exists( $editor, 'set_quality' ) ) {
			$editor->set_quality( 82 );
		}

		$saved = $editor->save( $destination, 'image/webp' );

		return is_wp_error( $saved ) ? '' : $destination;
	}

	/**
	 * Converts a local upload file path to URL.
	 *
	 * @param string $file File.
	 * @return string
	 */
	private function file_to_url( $file ) {
		$uploads = wp_get_upload_dir();

		if ( empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return '';
		}

		return str_replace( $uploads['basedir'], $uploads['baseurl'], $file );
	}

	/**
	 * Checks whether the browser accepts WebP.
	 *
	 * @return bool
	 */
	private function browser_accepts_webp() {
		return isset( $_SERVER['HTTP_ACCEPT'] ) && false !== strpos( strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) ), 'image/webp' );
	}

	/**
	 * Returns whether WebP URL rewriting applies to the current request.
	 *
	 * @return bool
	 */
	private function webp_rewrite_active() {
		if ( ! $this->plugin->enabled() || ! $this->mode_allows( 'webp' ) || ! $this->plugin->setting( 'performance_webp_rewrite', 1 ) || ! $this->browser_accepts_webp() ) {
			return false;
		}

		return ! $this->super_rocket_handles( array( 'image_webp_rewrite' ) );
	}

	/**
	 * Keeps full-page caches from reusing WebP HTML for non-WebP clients.
	 *
	 * Sent whenever the rewrite feature is on — both WebP and non-WebP
	 * variants must carry Vary: Accept for shared caches to key correctly.
	 *
	 * @return void
	 */
	public function webp_vary_header() {
		if ( headers_sent() || is_admin() || ! $this->plugin->enabled() || ! $this->mode_allows( 'webp' ) || ! $this->plugin->setting( 'performance_webp_rewrite', 1 ) ) {
			return;
		}

		if ( $this->super_rocket_handles( array( 'image_webp_rewrite' ) ) ) {
			return;
		}

		header( 'Vary: Accept', false );
	}

	/**
	 * Returns whether Super Rocket is active and already owns a performance feature.
	 *
	 * @param array $keys Super Rocket setting keys.
	 * @return bool
	 */
	private function super_rocket_handles( array $keys ) {
		if ( ! defined( 'SUPER_ROCKET_OPTION' ) && ! defined( 'SUPER_ROCKET_VERSION' ) ) {
			return false;
		}

		$settings = defined( 'SUPER_ROCKET_OPTION' ) ? get_option( SUPER_ROCKET_OPTION, array() ) : array();

		if ( ! is_array( $settings ) ) {
			return false;
		}

		foreach ( $keys as $key ) {
			if ( ! empty( $settings[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns whether Super SEO still needs a final HTML pass.
	 *
	 * @return bool
	 */
	private function needs_html_buffer() {
		$needs_dedupe = true;
		$needs_minify  = $this->mode_allows( 'minify' ) && $this->plugin->setting( 'performance_minify_html', 0 ) && ! $this->super_rocket_handles( array( 'html_minify' ) );
		$needs_preload = $this->will_preload_image();

		return $needs_dedupe || $needs_minify || $needs_preload;
	}

	/**
	 * Returns whether final HTML processing will choose the LCP image.
	 *
	 * @return bool
	 */
	private function will_preload_image() {
		if ( 'safe' === $this->plugin->setting( 'pagespeed_mode', 'balanced' ) ) {
			return false;
		}

		return ( $this->mode_allows( 'auto_preload' ) && $this->plugin->setting( 'performance_auto_preload_image', 1 ) ) || '' !== $this->plugin->setting( 'performance_preload_hero_image', '' );
	}

	/**
	 * Returns whether the selected PageSpeed mode allows a feature.
	 *
	 * @param string $feature Feature.
	 * @return bool
	 */
	private function mode_allows( $feature ) {
		$mode = $this->plugin->setting( 'pagespeed_mode', 'balanced' );

		if ( 'safe' === $mode ) {
			return in_array( $feature, array( 'image_attrs' ), true );
		}

		if ( 'aggressive' === $mode ) {
			return true;
		}

		return in_array( $feature, array( 'defer_js', 'webp', 'manual_preload' ), true );
	}

	/**
	 * Returns defer exclusions.
	 *
	 * @return array
	 */
	private function defer_exclusions() {
		$raw = (string) $this->plugin->setting( 'performance_defer_exclusions', '' );

		return array_filter( array_map( 'trim', preg_split( '/[,，\n]+/u', $raw ) ) );
	}
}
