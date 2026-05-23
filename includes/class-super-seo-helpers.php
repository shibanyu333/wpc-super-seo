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
