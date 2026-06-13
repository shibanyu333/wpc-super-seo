<?php
/**
 * Small shared helpers.
 *
 * @package SuperSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper functions used by modules.
 */
final class Super_SEO_Helpers {
	/**
	 * Cleans plain text for meta usage.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function clean_text( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES, get_bloginfo( 'charset' ) );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return trim( (string) $text );
	}

	/**
	 * Limits text length without cutting words too aggressively.
	 *
	 * @param string $text   Text.
	 * @param int    $length Max length.
	 * @return string
	 */
	public static function limit_text( $text, $length ) {
		$text = self::clean_text( $text );

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > $length ) {
			return rtrim( mb_substr( $text, 0, $length - 1, 'UTF-8' ) ) . '…';
		}

		if ( ! function_exists( 'mb_strlen' ) && strlen( $text ) > $length ) {
			return rtrim( substr( $text, 0, $length - 1 ) ) . '...';
		}

		return $text;
	}

	/**
	 * Builds an excerpt from content.
	 *
	 * @param string $content Raw content.
	 * @param int    $length  Length.
	 * @return string
	 */
	public static function excerpt_from_content( $content, $length = 160 ) {
		$content = strip_shortcodes( (string) $content );
		$content = self::clean_text( $content );

		return self::limit_text( $content, $length );
	}

	/**
	 * Normalizes comma separated keywords.
	 *
	 * @param string|array $keywords Keywords.
	 * @param int          $limit    Max keywords.
	 * @return string
	 */
	public static function normalize_keywords( $keywords, $limit = 8 ) {
		if ( is_array( $keywords ) ) {
			$items = $keywords;
		} else {
			$items = preg_split( '/[,，;\n]+/u', (string) $keywords );
		}

		$items = array_filter(
			array_map(
				static function ( $item ) {
					return Super_SEO_Helpers::limit_text( $item, 36 );
				},
				(array) $items
			)
		);

		$items = array_slice( array_unique( $items ), 0, $limit );

		return implode( ', ', $items );
	}

	/**
	 * Escapes a value for XML text nodes.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function esc_xml( $value ) {
		return esc_html( $value );
	}

	/**
	 * Encodes JSON for an inline HTML script context.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function json_for_html_script( $value ) {
		$json = wp_json_encode(
			$value,
			JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);

		return false === $json ? '' : $json;
	}

	/**
	 * Removes duplicate SEO tags from the document head, keeping the last tag.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function dedupe_head_seo_tags( $html ) {
		if ( false === stripos( (string) $html, '<head' ) || false === stripos( (string) $html, '</head>' ) ) {
			return $html;
		}

		return preg_replace_callback(
			'/<head\b[^>]*>.*?<\/head>/is',
			static function ( $matches ) {
				return Super_SEO_Helpers::dedupe_head_markup( $matches[0] );
			},
			$html,
			1
		);
	}

	/**
	 * De-dupes known SEO tags inside a head fragment.
	 *
	 * @param string $head Head markup.
	 * @return string
	 */
	private static function dedupe_head_markup( $head ) {
		if ( ! preg_match_all( '/<(meta|link)\b[^>]*>/i', $head, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $head;
		}

		$groups = array();

		foreach ( $matches[0] as $index => $match ) {
			$tag = $match[0];
			$key = self::head_seo_tag_key( $tag, $matches[1][ $index ][0] );

			if ( '' === $key ) {
				continue;
			}

			$groups[ $key ][] = array(
				'offset' => $match[1],
				'length' => strlen( $tag ),
			);
		}

		$removals = array();

		foreach ( $groups as $items ) {
			if ( count( $items ) < 2 ) {
				continue;
			}

			array_pop( $items );
			$removals = array_merge( $removals, $items );
		}

		usort(
			$removals,
			static function ( $a, $b ) {
				return $b['offset'] - $a['offset'];
			}
		);

		foreach ( $removals as $removal ) {
			$head = substr_replace( $head, '', $removal['offset'], $removal['length'] );
		}

		return $head;
	}

	/**
	 * Returns a stable de-dupe key for head SEO tags.
	 *
	 * @param string $tag  HTML tag.
	 * @param string $type Tag type.
	 * @return string
	 */
	private static function head_seo_tag_key( $tag, $type ) {
		$type = strtolower( $type );

		if ( 'link' === $type ) {
			$rel = strtolower( self::tag_attribute( $tag, 'rel' ) );

			return in_array( 'canonical', preg_split( '/\s+/', $rel ), true ) ? 'link:canonical' : '';
		}

		$name = strtolower( self::tag_attribute( $tag, 'name' ) );

		if ( in_array( $name, array( 'description', 'keywords', 'robots', 'twitter:card', 'twitter:title', 'twitter:description' ), true ) ) {
			return 'meta:name:' . $name;
		}

		$property = strtolower( self::tag_attribute( $tag, 'property' ) );

		if ( in_array( $property, array( 'og:site_name', 'og:title', 'og:description', 'og:url', 'og:type', 'og:image' ), true ) ) {
			return 'meta:property:' . $property;
		}

		return '';
	}

	/**
	 * Reads a single HTML attribute from a tag.
	 *
	 * @param string $tag       HTML tag.
	 * @param string $attribute Attribute name.
	 * @return string
	 */
	private static function tag_attribute( $tag, $attribute ) {
		$attribute = preg_quote( $attribute, '/' );

		if ( preg_match( '/\s' . $attribute . '\s*=\s*(["\'])(.*?)\1/is', $tag, $matches ) ) {
			return html_entity_decode( $matches[2], ENT_QUOTES, function_exists( 'get_bloginfo' ) ? get_bloginfo( 'charset' ) : 'UTF-8' );
		}

		if ( preg_match( '/\s' . $attribute . '\s*=\s*([^\s>]+)/i', $tag, $matches ) ) {
			return trim( html_entity_decode( $matches[1], ENT_QUOTES, function_exists( 'get_bloginfo' ) ? get_bloginfo( 'charset' ) : 'UTF-8' ), "\"'" );
		}

		return '';
	}

	/**
	 * Converts a URL that may be relative into an absolute site URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function absolute_url( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}

		return home_url( '/' . ltrim( $url, '/' ) );
	}

	/**
	 * Returns public post types for SEO boxes and sitemap.
	 *
	 * @return array
	 */
	public static function public_post_types() {
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		unset( $post_types['attachment'] );

		return array_values( $post_types );
	}
}
