<?php
/**
 * AI-assisted product intelligence, meta optimization and article automation.
 *
 * @package SuperSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates safe AI automation flows.
 */
final class Super_SEO_Automation {
	/**
	 * Product profile option.
	 */
	const PROFILE_OPTION = 'super_seo_product_profile';

	/**
	 * Meta suggestion transient.
	 */
	const META_SUGGESTIONS_TRANSIENT = 'super_seo_meta_suggestions';

	/**
	 * Last scheduled article result option.
	 */
	const LAST_ARTICLE_RESULT_OPTION = 'super_seo_last_article_result';

	/**
	 * Objects touched by the most recent bulk apply, used for rollback.
	 */
	const LAST_APPLY_OPTION = 'super_seo_last_meta_apply';

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

		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
		add_action( 'init', array( $this, 'ensure_article_cron' ) );
		add_action( 'super_seo_run_article_cron', array( $this, 'run_article_cron' ) );
	}

	/**
	 * Registers lightweight article schedules.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public function cron_schedules( $schedules ) {
		$schedules['super_seo_daily'] = array(
			'interval' => DAY_IN_SECONDS,
			'display'  => 'Super SEO Daily',
		);

		$schedules['super_seo_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => 'Super SEO Weekly',
		);

		return $schedules;
	}

	/**
	 * Ensures scheduled article generation follows settings.
	 *
	 * @return void
	 */
	public function ensure_article_cron() {
		$frequency = $this->plugin->setting( 'ai_article_frequency', 'manual' );
		$timestamp = wp_next_scheduled( 'super_seo_run_article_cron' );

		if ( ! in_array( $frequency, array( 'daily', 'weekly' ), true ) ) {
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'super_seo_run_article_cron' );
			}
			return;
		}

		$schedule = 'daily' === $frequency ? 'super_seo_daily' : 'super_seo_weekly';

		if ( $timestamp && wp_get_schedule( 'super_seo_run_article_cron' ) !== $schedule ) {
			wp_unschedule_event( $timestamp, 'super_seo_run_article_cron' );
			$timestamp = false;
		}

		if ( ! $timestamp ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $schedule, 'super_seo_run_article_cron' );
		}
	}

	/**
	 * Returns the stored product profile.
	 *
	 * @return array
	 */
	public function product_profile() {
		$profile = get_option( self::PROFILE_OPTION, array() );

		return is_array( $profile ) ? $profile : array();
	}

	/**
	 * Builds a WooCommerce-first product context.
	 *
	 * @return array
	 */
	public function collect_product_context() {
		$context = array(
			'products'   => array(),
			'categories' => array(),
			'attributes' => array(),
			'site'       => array(
				'name'        => get_bloginfo( 'name' ),
				'description' => get_bloginfo( 'description' ),
				'url'         => home_url( '/' ),
			),
		);

		$post_type = post_type_exists( 'product' ) ? 'product' : 'post';
		$ids       = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		foreach ( $ids as $post_id ) {
			$item = array(
				'id'          => (int) $post_id,
				'title'       => Super_SEO_Helpers::limit_text( get_the_title( $post_id ), 120 ),
				'description' => Super_SEO_Helpers::excerpt_from_content( get_post_field( 'post_content', $post_id ), 500 ),
				'url'         => get_permalink( $post_id ),
				'seo_title'   => get_post_meta( $post_id, '_super_seo_title', true ),
				'seo_desc'    => get_post_meta( $post_id, '_super_seo_description', true ),
				'keywords'    => get_post_meta( $post_id, '_super_seo_keywords', true ),
			);

			if ( function_exists( 'wc_get_product' ) && 'product' === get_post_type( $post_id ) ) {
				$product = wc_get_product( $post_id );

				if ( $product ) {
					$item['sku']        = $product->get_sku();
					$item['price_type'] = '' !== $product->get_price() ? 'visible' : 'not listed';
					$item['stock']      = $product->is_in_stock() ? 'in stock' : 'out of stock';
					$item['attributes'] = $this->product_attributes( $product );
				}
			}

			$context['products'][] = $item;
		}

		if ( taxonomy_exists( 'product_cat' ) || taxonomy_exists( 'category' ) ) {
			$taxonomy = taxonomy_exists( 'product_cat' ) ? 'product_cat' : 'category';
			$terms    = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
					'number'     => 20,
				)
			);

			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$link = get_term_link( $term );

					if ( is_wp_error( $link ) ) {
						continue;
					}

					$context['categories'][] = array(
						'id'          => (int) $term->term_id,
						'name'        => Super_SEO_Helpers::limit_text( $term->name, 100 ),
						'description' => Super_SEO_Helpers::excerpt_from_content( $term->description, 300 ),
						'url'         => $link,
					);
				}
			}
		}

		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( wc_get_attribute_taxonomies() as $attribute ) {
				$context['attributes'][] = sanitize_text_field( $attribute->attribute_label ?: $attribute->attribute_name );
			}
		}

		return $context;
	}

	/**
	 * Generates and stores a product profile through AI.
	 *
	 * @return array|WP_Error
	 */
	public function generate_product_profile() {
		$context = $this->collect_product_context();
		$result  = $this->ai->generate_structured(
			array(
				'type'        => 'product_profile',
				'title'       => 'Site product profile',
				'content'     => wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'url'         => home_url( '/' ),
				'schema_hint' => array(
					'product_types'      => array(),
					'selling_points'     => array(),
					'use_cases'          => array(),
					'customer_intent'    => array(),
					'core_keywords'      => array(),
					'long_tail_keywords' => array(),
					'article_angles'     => array(),
					'forbidden_claims'   => array(),
					'unsupported_claims' => array(),
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( isset( $result['product_profile'] ) && is_array( $result['product_profile'] ) ) {
			$result = $result['product_profile'];
		}

		$profile = self::sanitize_profile( $result );
		$profile['generated_at'] = time();

		update_option( self::PROFILE_OPTION, $profile, false );

		return $profile;
	}

	/**
	 * Generates batched meta suggestions.
	 *
	 * @param array $args Args.
	 * @return array|WP_Error
	 */
	public function generate_meta_suggestions( array $args = array() ) {
		$defaults = array(
			'limit'     => 10,
		);
		$args     = wp_parse_args( $args, $defaults );
		$limit    = max( 1, min( 20, (int) $args['limit'] ) );
		$profile  = $this->product_profile();
		$targets  = $this->meta_targets( $limit );

		$suggestions = array();

		foreach ( $targets as $target ) {
			if ( 'term' === $target['object_type'] ) {
				$result = $this->ai->generate_for_term(
					$target['object_id'],
					array(
						'product_profile' => $profile,
						'task'            => 'Return product-relevant SEO meta only. Do not change category facts.',
					)
				);
			} else {
				$result = $this->ai->generate_for_post(
					$target['object_id'],
					array(
						'product_profile' => $profile,
						'task'            => 'Return product-relevant SEO meta only. Do not change product or page facts.',
					)
				);
			}

			if ( is_wp_error( $result ) ) {
				$suggestions[] = array(
					'object_type' => $target['object_type'],
					'object_id'   => (int) $target['object_id'],
					'title'       => $target['label'],
					'error'       => $result->get_error_message(),
				);
				continue;
			}

			$suggestions[] = array(
				'object_type' => $target['object_type'],
				'object_id'   => (int) $target['object_id'],
				'label'       => $target['label'],
				'before'      => 'term' === $target['object_type'] ? self::term_meta_snapshot( $target['object_id'] ) : self::post_meta_snapshot( $target['object_id'] ),
				'after'       => self::sanitize_meta_result( $result ),
			);
		}

		set_transient( self::META_SUGGESTIONS_TRANSIENT, $suggestions, HOUR_IN_SECONDS );

		return $suggestions;
	}

	/**
	 * Applies generated meta suggestions.
	 *
	 * @param array $items Items.
	 * @return array|WP_Error
	 */
	public function apply_meta_suggestions( array $items = array() ) {
		$suggestions = empty( $items ) ? get_transient( self::META_SUGGESTIONS_TRANSIENT ) : $items;

		if ( ! is_array( $suggestions ) || empty( $suggestions ) ) {
			return new WP_Error( 'super_seo_no_meta_suggestions', '没有可应用的 SEO 建议。' );
		}

		$applied = array();
		$journal = array();

		foreach ( $suggestions as $suggestion ) {
			if ( ! empty( $suggestion['error'] ) ) {
				continue;
			}

			$object_type = $suggestion['object_type'] ?? '';
			$object_id   = absint( $suggestion['object_id'] ?? 0 );
			$after       = isset( $suggestion['after'] ) && is_array( $suggestion['after'] ) ? $suggestion['after'] : array();

			if ( ! $object_id ) {
				continue;
			}

			if ( 'term' === $object_type ) {
				$term = get_term( $object_id );

				if ( ! $term || is_wp_error( $term ) ) {
					continue;
				}

				update_term_meta( $object_id, '_super_seo_previous_meta', self::term_meta_snapshot( $object_id ) );
				update_term_meta( $object_id, '_super_seo_title', $after['title'] ?? '' );
				update_term_meta( $object_id, '_super_seo_description', $after['description'] ?? '' );
				update_term_meta( $object_id, '_super_seo_keywords', $after['keywords'] ?? '' );
			} else {
				if ( ! get_post( $object_id ) ) {
					continue;
				}

				update_post_meta( $object_id, '_super_seo_previous_meta', self::post_meta_snapshot( $object_id ) );
				update_post_meta( $object_id, '_super_seo_title', $after['title'] ?? '' );
				update_post_meta( $object_id, '_super_seo_description', $after['description'] ?? '' );
				update_post_meta( $object_id, '_super_seo_keywords', $after['keywords'] ?? '' );
			}

			$applied[] = $object_id;
			$journal[] = array(
				'object_type' => 'term' === $object_type ? 'term' : 'post',
				'object_id'   => $object_id,
			);
		}

		if ( ! empty( $applied ) ) {
			update_option(
				self::LAST_APPLY_OPTION,
				array(
					'time'  => time(),
					'items' => $journal,
				),
				false
			);

			$this->plugin->purge_super_rocket_cache();
		}

		return array(
			'applied' => $applied,
			'count'   => count( $applied ),
		);
	}

	/**
	 * Restores the SEO meta snapshot captured before the last bulk apply.
	 *
	 * @return array|WP_Error
	 */
	public function undo_meta_suggestions() {
		$record = get_option( self::LAST_APPLY_OPTION, array() );

		if ( ! is_array( $record ) || empty( $record['items'] ) ) {
			return new WP_Error( 'super_seo_nothing_to_undo', '没有可撤销的批量应用记录。' );
		}

		$restored = 0;

		foreach ( (array) $record['items'] as $item ) {
			$object_id = absint( $item['object_id'] ?? 0 );
			$is_term   = 'term' === ( $item['object_type'] ?? '' );

			if ( ! $object_id ) {
				continue;
			}

			$snapshot = $is_term
				? get_term_meta( $object_id, '_super_seo_previous_meta', true )
				: get_post_meta( $object_id, '_super_seo_previous_meta', true );

			if ( ! is_array( $snapshot ) ) {
				continue;
			}

			foreach ( array( 'title', 'description', 'keywords' ) as $field ) {
				$key   = '_super_seo_' . $field;
				$value = (string) ( $snapshot[ $field ] ?? '' );

				if ( '' === $value ) {
					if ( $is_term ) {
						delete_term_meta( $object_id, $key );
					} else {
						delete_post_meta( $object_id, $key );
					}
					continue;
				}

				if ( $is_term ) {
					update_term_meta( $object_id, $key, $value );
				} else {
					update_post_meta( $object_id, $key, $value );
				}
			}

			if ( $is_term ) {
				delete_term_meta( $object_id, '_super_seo_previous_meta' );
			} else {
				delete_post_meta( $object_id, '_super_seo_previous_meta' );
			}

			$restored++;
		}

		delete_option( self::LAST_APPLY_OPTION );
		$this->plugin->purge_super_rocket_cache();

		if ( ! $restored ) {
			return new WP_Error( 'super_seo_undo_empty', '没有找到可恢复的旧值快照。' );
		}

		return array(
			'restored' => $restored,
		);
	}

	/**
	 * Returns the last bulk apply record.
	 *
	 * @return array
	 */
	public function last_apply_record() {
		$record = get_option( self::LAST_APPLY_OPTION, array() );

		return is_array( $record ) ? $record : array();
	}

	/**
	 * Generates an article and creates a post according to settings.
	 *
	 * @param array $overrides Overrides.
	 * @return array|WP_Error
	 */
	public function generate_article( array $overrides = array() ) {
		$settings = $this->plugin->settings();
		$profile  = $this->product_profile();
		$strategy = sanitize_key( $overrides['strategy'] ?? $settings['ai_article_strategy'] );
		$mode     = sanitize_key( $overrides['publish_mode'] ?? $settings['ai_publish_mode'] );
		$min      = max( 80, (int) ( $overrides['min_words'] ?? $settings['ai_article_min_words'] ) );

		if ( empty( $profile ) ) {
			return new WP_Error( 'super_seo_missing_product_profile', '请先生成产品画像，再生成 AI 文章。' );
		}

		if ( 'publish' === $mode && empty( $settings['ai_direct_publish_enabled'] ) ) {
			return new WP_Error( 'super_seo_direct_publish_disabled', '直接发布未启用，请改为草稿或定时。' );
		}

		$result = $this->ai->generate_structured(
			array(
				'type'            => 'article',
				'title'           => 'Generate a product-relevant SEO article',
				'content'         => wp_json_encode( $profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'url'             => home_url( '/' ),
				'article_strategy' => $strategy,
				'schema_hint'     => array(
					'title'       => '',
					'description' => '',
					'keywords'    => array(),
					'content'     => '',
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( isset( $result['article'] ) && is_array( $result['article'] ) ) {
			$result = $result['article'];
		}

		$article = self::sanitize_article_payload( $result );
		$gates   = self::validate_article_payload( $article, $profile, $min, $strategy );

		if ( ! $gates['passed'] ) {
			return new WP_Error( 'super_seo_article_gate_failed', '文章质量门禁未通过：' . $gates['errors'][0]['message'] );
		}

		$duplicates = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'title'          => $article['title'],
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( ! empty( $duplicates ) ) {
			return new WP_Error( 'super_seo_article_duplicate_title', '已存在同名文章，已阻止重复发布。' );
		}

		$postarr = array(
			'post_title'   => $article['title'],
			'post_content' => wp_kses_post( $article['content'] ),
			'post_excerpt' => $article['description'],
			'post_status'  => $this->post_status_for_mode( $mode ),
			'post_type'    => 'post',
		);

		if ( 'future' === $postarr['post_status'] ) {
			$postarr['post_date'] = wp_date( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		}

		$category = absint( $settings['ai_article_category'] ?? 0 );

		if ( $category && ! term_exists( $category, 'category' ) ) {
			return new WP_Error( 'super_seo_article_bad_category', '文章分类 ID 无效，已阻止发布。' );
		}

		if ( $category ) {
			$postarr['post_category'] = array( $category );
		}

		$post_id = wp_insert_post( $postarr, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_super_seo_title', Super_SEO_Helpers::limit_text( $article['title'], 70 ) );
		update_post_meta( $post_id, '_super_seo_description', Super_SEO_Helpers::limit_text( $article['description'], 180 ) );
		update_post_meta( $post_id, '_super_seo_keywords', Super_SEO_Helpers::normalize_keywords( $article['keywords'], 10 ) );
		update_post_meta( $post_id, '_super_seo_ai_article_gates', $gates );

		return array(
			'post_id' => (int) $post_id,
			'status'  => $postarr['post_status'],
			'gates'   => $gates,
		);
	}

	/**
	 * Runs one scheduled article generation batch.
	 *
	 * @return void
	 */
	public function run_article_cron() {
		if ( ! $this->plugin->enabled() ) {
			return;
		}

		$result = $this->generate_article();

		update_option(
			self::LAST_ARTICLE_RESULT_OPTION,
			array(
				'time'    => time(),
				'success' => ! is_wp_error( $result ),
				'message' => is_wp_error( $result ) ? $result->get_error_message() : sprintf( '已生成文章 #%d（%s）。', $result['post_id'], $result['status'] ),
			),
			false
		);
	}

	/**
	 * Returns the last scheduled article result.
	 *
	 * @return array
	 */
	public function last_article_result() {
		$result = get_option( self::LAST_ARTICLE_RESULT_OPTION, array() );

		return is_array( $result ) ? $result : array();
	}

	/**
	 * Validates an article payload.
	 *
	 * @param array  $article  Article.
	 * @param array  $profile  Product profile.
	 * @param int    $min_words Minimum words.
	 * @param string $strategy Strategy.
	 * @return array
	 */
	public static function validate_article_payload( array $article, array $profile, $min_words = 300, $strategy = 'strict' ) {
		$errors   = array();
		$title    = Super_SEO_Helpers::clean_text( $article['title'] ?? '' );
		$content  = Super_SEO_Helpers::clean_text( $article['content'] ?? '' );
		$desc     = Super_SEO_Helpers::clean_text( $article['description'] ?? '' );
		$haystack = strtolower( $title . ' ' . $desc . ' ' . $content );

		if ( self::text_length( $title ) < 20 || self::text_length( $title ) > 90 ) {
			$errors[] = self::gate_error( 'bad_title', '文章标题长度不合适。' );
		}

		if ( self::word_count( $content ) < max( 1, (int) $min_words ) ) {
			$errors[] = self::gate_error( 'too_short', '文章内容低于最低字数要求。' );
		}

		if ( self::text_length( $desc ) < 50 ) {
			$errors[] = self::gate_error( 'missing_description', '文章缺少可用 SEO 描述。' );
		}

		$keywords = self::profile_terms( $profile );
		$matches  = 0;

		foreach ( $keywords as $keyword ) {
			if ( '' !== $keyword && false !== strpos( $haystack, strtolower( $keyword ) ) ) {
				$matches++;
			}
		}

		$required_matches = 'strict' === $strategy ? 1 : 0;

		if ( $matches < $required_matches ) {
			$errors[] = self::gate_error( 'not_product_relevant', '文章与产品主题相关性不足。' );
		}

		foreach ( array_merge( (array) ( $profile['forbidden_claims'] ?? array() ), (array) ( $profile['unsupported_claims'] ?? array() ) ) as $claim ) {
			$claim = Super_SEO_Helpers::clean_text( $claim );

			if ( '' !== $claim && false !== strpos( $haystack, strtolower( $claim ) ) ) {
				$errors[] = self::gate_error( 'forbidden_claim', '文章包含禁止或未经支持的产品承诺：' . $claim );
				break;
			}
		}

		return array(
			'passed' => empty( $errors ),
			'errors' => $errors,
		);
	}

	/**
	 * Sanitizes an AI product profile.
	 *
	 * @param array $profile Profile.
	 * @return array
	 */
	public static function sanitize_profile( array $profile ) {
		$keys  = array( 'product_types', 'selling_points', 'use_cases', 'customer_intent', 'core_keywords', 'long_tail_keywords', 'article_angles', 'forbidden_claims', 'unsupported_claims' );
		$clean = array();

		foreach ( $keys as $key ) {
			$clean[ $key ] = self::clean_string_list( $profile[ $key ] ?? array(), 12, 120 );
		}

		return $clean;
	}

	/**
	 * Sanitizes a generated article.
	 *
	 * @param array $article Article.
	 * @return array
	 */
	public static function sanitize_article_payload( array $article ) {
		return array(
			'title'       => Super_SEO_Helpers::limit_text( sanitize_text_field( $article['title'] ?? '' ), 90 ),
			'description' => Super_SEO_Helpers::limit_text( sanitize_textarea_field( $article['description'] ?? '' ), 180 ),
			'keywords'    => self::clean_string_list( $article['keywords'] ?? array(), 10, 60 ),
			'content'     => wp_kses_post( $article['content'] ?? '' ),
		);
	}

	/**
	 * Sanitizes meta generation output.
	 *
	 * @param array $result Result.
	 * @return array
	 */
	public static function sanitize_meta_result( array $result ) {
		return array(
			'title'       => Super_SEO_Helpers::limit_text( sanitize_text_field( $result['title'] ?? '' ), 80 ),
			'description' => Super_SEO_Helpers::limit_text( sanitize_textarea_field( $result['description'] ?? '' ), 180 ),
			'keywords'    => Super_SEO_Helpers::normalize_keywords( sanitize_text_field( $result['keywords'] ?? '' ), 10 ),
		);
	}

	/**
	 * Returns a post meta rollback snapshot.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function post_meta_snapshot( $post_id ) {
		return array(
			'title'       => get_post_meta( $post_id, '_super_seo_title', true ),
			'description' => get_post_meta( $post_id, '_super_seo_description', true ),
			'keywords'    => get_post_meta( $post_id, '_super_seo_keywords', true ),
			'captured_at' => time(),
		);
	}

	/**
	 * Returns a term meta rollback snapshot.
	 *
	 * @param int $term_id Term ID.
	 * @return array
	 */
	public static function term_meta_snapshot( $term_id ) {
		return array(
			'title'       => get_term_meta( $term_id, '_super_seo_title', true ),
			'description' => get_term_meta( $term_id, '_super_seo_description', true ),
			'keywords'    => get_term_meta( $term_id, '_super_seo_keywords', true ),
			'captured_at' => time(),
		);
	}

	/**
	 * Returns a balanced set of post and term targets.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	private function meta_targets( $limit ) {
		$targets    = array();
		$post_types = array_values( array_unique( array_filter( array( post_type_exists( 'product' ) ? 'product' : '', 'page', 'post' ) ) ) );

		foreach ( $post_types as $post_type ) {
			if ( count( $targets ) >= $limit ) {
				break;
			}

			$ids = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'publish',
					'posts_per_page' => max( 1, min( 4, $limit - count( $targets ) ) ),
					'fields'         => 'ids',
					'orderby'        => 'modified',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				)
			);

			foreach ( $ids as $post_id ) {
				$targets[] = array(
					'object_type' => 'post',
					'object_id'   => (int) $post_id,
					'label'       => get_the_title( $post_id ),
				);
			}
		}

		foreach ( array( 'product_cat', 'category' ) as $taxonomy ) {
			if ( count( $targets ) >= $limit || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
					'number'     => max( 1, min( 4, $limit - count( $targets ) ) ),
				)
			);

			if ( is_wp_error( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$targets[] = array(
					'object_type' => 'term',
					'object_id'   => (int) $term->term_id,
					'label'       => $term->name,
				);
			}
		}

		return array_slice( $targets, 0, $limit );
	}

	/**
	 * Returns WooCommerce product attributes.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	private function product_attributes( $product ) {
		$attributes = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( method_exists( $attribute, 'get_name' ) ) {
				$attributes[] = sanitize_text_field( wc_attribute_label( $attribute->get_name() ) );
			}
		}

		return array_slice( array_unique( array_filter( $attributes ) ), 0, 12 );
	}

	/**
	 * Maps publish mode to WP post status.
	 *
	 * @param string $mode Mode.
	 * @return string
	 */
	private function post_status_for_mode( $mode ) {
		if ( 'publish' === $mode ) {
			return 'publish';
		}

		if ( 'future' === $mode || 'scheduled' === $mode ) {
			return 'future';
		}

		return 'draft';
	}

	/**
	 * Returns product relevance terms.
	 *
	 * @param array $profile Profile.
	 * @return array
	 */
	private static function profile_terms( array $profile ) {
		return array_merge(
			(array) ( $profile['core_keywords'] ?? array() ),
			(array) ( $profile['product_types'] ?? array() ),
			(array) ( $profile['use_cases'] ?? array() )
		);
	}

	/**
	 * Cleans a list of strings.
	 *
	 * @param mixed $items  Items.
	 * @param int   $limit  Limit.
	 * @param int   $length Length.
	 * @return array
	 */
	private static function clean_string_list( $items, $limit, $length ) {
		if ( ! is_array( $items ) ) {
			$items = preg_split( '/[,，;\n]+/u', (string) $items );
		}

		$clean = array();

		foreach ( $items as $item ) {
			$item = Super_SEO_Helpers::limit_text( sanitize_text_field( $item ), $length );

			if ( '' !== $item ) {
				$clean[] = $item;
			}
		}

		return array_slice( array_values( array_unique( $clean ) ), 0, $limit );
	}

	/**
	 * Builds a stable gate error.
	 *
	 * @param string $id      ID.
	 * @param string $message Message.
	 * @return array
	 */
	private static function gate_error( $id, $message ) {
		return array(
			'id'      => $id,
			'message' => $message,
		);
	}

	/**
	 * Word-ish count that works for Latin and CJK content.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function word_count( $text ) {
		$text = Super_SEO_Helpers::clean_text( $text );

		if ( preg_match_all( '/[\p{Han}]|[A-Za-z0-9]+/u', $text, $matches ) ) {
			return count( $matches[0] );
		}

		return 0;
	}

	/**
	 * Text length.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function text_length( $text ) {
		$text = Super_SEO_Helpers::clean_text( $text );

		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}
}
