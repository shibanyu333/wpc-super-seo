<?php
/**
 * AI image understanding: alt text, title and caption for media library images.
 *
 * @package SuperSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates and stores accessible, SEO-friendly image descriptions.
 */
final class Super_SEO_Vision {
	/**
	 * Meta key holding the last AI result for an attachment.
	 */
	const RESULT_META = '_super_seo_vision';

	/**
	 * Cron hook used for non-blocking generation after upload.
	 */
	const AUTO_HOOK = 'super_seo_vision_generate';

	/**
	 * Running token usage for this feature.
	 */
	const USAGE_OPTION = 'super_seo_vision_usage';

	/**
	 * Mime types the vision APIs accept.
	 *
	 * @var array
	 */
	private static $supported_mimes = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

	/**
	 * Plugin instance.
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

		add_filter( 'wp_generate_attachment_metadata', array( $this, 'maybe_queue_on_upload' ), 30, 2 );
		add_action( self::AUTO_HOOK, array( $this, 'generate_and_apply' ) );
	}

	/**
	 * Queues a background description job for freshly uploaded images.
	 *
	 * Runs on cron rather than inline so the upload response is never blocked
	 * by a model round trip.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array
	 */
	public function maybe_queue_on_upload( $metadata, $attachment_id ) {
		if ( ! $this->plugin->enabled() || ! $this->plugin->setting( 'vision_auto_on_upload', 0 ) ) {
			return $metadata;
		}

		if ( ! $this->is_supported_image( $attachment_id ) ) {
			return $metadata;
		}

		if ( ! wp_next_scheduled( self::AUTO_HOOK, array( (int) $attachment_id ) ) ) {
			wp_schedule_single_event( time() + 30, self::AUTO_HOOK, array( (int) $attachment_id ) );
		}

		return $metadata;
	}

	/**
	 * Generates a description and writes it to the attachment.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $args          Overrides (overwrite).
	 * @return array|WP_Error
	 */
	public function generate_and_apply( $attachment_id, array $args = array() ) {
		$result = $this->describe_attachment( $attachment_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->apply_to_attachment( $attachment_id, $result, $args );
	}

	/**
	 * Asks the vision model to describe one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|WP_Error
	 */
	public function describe_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $this->is_supported_image( $attachment_id ) ) {
			return new WP_Error( 'super_seo_vision_unsupported_file', '该文件不是插件支持的图片格式（JPG/PNG/GIF/WebP）。' );
		}

		$image = $this->image_payload( $attachment_id );

		if ( is_wp_error( $image ) ) {
			return $image;
		}

		return $this->ai->describe_image( $image, $this->attachment_context( $attachment_id ) );
	}

	/**
	 * Writes a generated description onto the attachment.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $result        Sanitized vision result.
	 * @param array $args          Overrides.
	 * @return array
	 */
	public function apply_to_attachment( $attachment_id, array $result, array $args = array() ) {
		$attachment_id = absint( $attachment_id );
		$overwrite     = isset( $args['overwrite'] )
			? (bool) $args['overwrite']
			: (bool) $this->plugin->setting( 'vision_overwrite_existing', 0 );

		$existing_alt = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$written      = array();

		if ( '' !== $result['alt'] && ( $overwrite || '' === trim( $existing_alt ) ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $result['alt'] );
			$written[] = 'alt';
		}

		$post_update = array();
		$attachment  = get_post( $attachment_id );

		if ( $attachment ) {
			if (
				$this->plugin->setting( 'vision_write_title', 1 )
				&& '' !== $result['title']
				&& ( $overwrite || $this->is_placeholder_title( $attachment->post_title, $attachment_id ) )
			) {
				$post_update['post_title'] = $result['title'];
				$written[]                 = 'title';
			}

			if (
				$this->plugin->setting( 'vision_write_caption', 0 )
				&& '' !== $result['caption']
				&& ( $overwrite || '' === trim( (string) $attachment->post_excerpt ) )
			) {
				$post_update['post_excerpt'] = $result['caption'];
				$written[]                   = 'caption';
			}
		}

		if ( ! empty( $post_update ) ) {
			$post_update['ID'] = $attachment_id;
			wp_update_post( $post_update );
		}

		$result['written']      = $written;
		$result['generated_at'] = time();

		update_post_meta( $attachment_id, self::RESULT_META, $result );

		return $result;
	}

	/**
	 * Runs one batch over images that still need alt text.
	 *
	 * @param array $args Batch args (limit, overwrite).
	 * @return array|WP_Error
	 */
	public function run_batch( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'limit'     => (int) $this->plugin->setting( 'vision_batch_size', 5 ),
				'overwrite' => (bool) $this->plugin->setting( 'vision_overwrite_existing', 0 ),
			)
		);

		$overwrite = (bool) $args['overwrite'];
		$limit     = max( 1, min( 20, (int) $args['limit'] ) );
		$query     = $this->pending_query( $overwrite, $limit );
		$ids       = $query->posts;

		if ( empty( $ids ) ) {
			return $this->batch_result( array(), 0, 0, 0 );
		}

		/**
		 * Filters the wall-clock budget for one batch, in seconds.
		 *
		 * A vision call takes seconds, not milliseconds, so a fixed image count
		 * can outrun PHP's max_execution_time on stock hosting. The batch stops
		 * early instead and the browser picks the rest up on the next request.
		 *
		 * @param float $seconds Budget.
		 */
		$budget   = (float) apply_filters( 'super_seo_vision_batch_seconds', 20.0 );
		$deadline = microtime( true ) + $budget;

		$items     = array();
		$succeeded = 0;
		$failed    = 0;
		$processed = 0;
		$paused    = '';

		foreach ( $ids as $attachment_id ) {
			if ( $processed > 0 && microtime( true ) >= $deadline ) {
				break;
			}

			$started = microtime( true );
			$result  = $this->generate_and_apply( $attachment_id, array( 'overwrite' => $overwrite ) );
			$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );
			$processed++;

			if ( is_wp_error( $result ) ) {
				// A rate limit or outage is temporary. Recording it would exclude
				// the image from every future batch, so stop the run instead and
				// let the operator resume once the provider recovers.
				if ( Super_SEO_AI::is_retryable_error( $result ) ) {
					$processed--;
					$paused  = $result->get_error_message();
					$items[] = array(
						'id'      => (int) $attachment_id,
						'title'   => get_the_title( $attachment_id ),
						'error'   => $paused,
						'retry'   => true,
						'elapsed' => $elapsed,
					);
					break;
				}

				$failed++;
				update_post_meta(
					$attachment_id,
					self::RESULT_META,
					array(
						'error'        => $result->get_error_message(),
						'generated_at' => time(),
					)
				);

				$items[] = array(
					'id'      => (int) $attachment_id,
					'title'   => get_the_title( $attachment_id ),
					'error'   => $result->get_error_message(),
					'elapsed' => $elapsed,
				);
				continue;
			}

			$succeeded++;
			$this->record_usage( $result['usage'] ?? array() );

			$items[] = array(
				'id'      => (int) $attachment_id,
				'title'   => get_the_title( $attachment_id ),
				'alt'     => $result['alt'],
				'elapsed' => $elapsed,
			);
		}

		return $this->batch_result( $items, $processed, $succeeded, $failed, $paused, $overwrite );
	}

	/**
	 * Shapes a batch response.
	 *
	 * @param array  $items     Per-image results.
	 * @param int    $processed Images attempted.
	 * @param int    $succeeded Successes.
	 * @param int    $failed    Permanent failures.
	 * @param string $paused    Retryable error that stopped the run, if any.
	 * @param bool   $overwrite Overwrite mode.
	 * @return array
	 */
	private function batch_result( array $items, $processed, $succeeded, $failed, $paused = '', $overwrite = false ) {
		return array(
			'processed' => (int) $processed,
			'succeeded' => (int) $succeeded,
			'failed'    => (int) $failed,
			'remaining' => $this->pending_count( $overwrite ),
			'paused'    => $paused,
			'items'     => $items,
			'usage'     => $this->usage_totals(),
		);
	}

	/**
	 * Returns attachment IDs still missing alt text.
	 *
	 * @param int  $limit     Max IDs.
	 * @param bool $overwrite Include images that already have alt text.
	 * @return array
	 */
	public function pending_attachment_ids( $limit = 5, $overwrite = false ) {
		return $this->pending_query( $overwrite, max( 1, (int) $limit ) )->posts;
	}

	/**
	 * Counts images still missing alt text.
	 *
	 * Uses found_posts rather than counting a capped result set, so the number
	 * stays truthful on libraries with thousands of images.
	 *
	 * @param bool $overwrite Include images that already have alt text.
	 * @return int
	 */
	public function pending_count( $overwrite = false ) {
		return (int) $this->pending_query( $overwrite, 1 )->found_posts;
	}

	/**
	 * Runs the pending-images query.
	 *
	 * @param bool $overwrite Include images that already have alt text.
	 * @param int  $limit     Page size.
	 * @return WP_Query
	 */
	private function pending_query( $overwrite, $limit ) {
		$args = $this->pending_query_args( $overwrite );

		$args['posts_per_page'] = (int) $limit;
		$args['fields']         = 'ids';
		$args['no_found_rows']  = false;

		return new WP_Query( $args );
	}

	/**
	 * Media library statistics for the admin screen.
	 *
	 * @return array
	 */
	public function stats() {
		$total = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => self::$supported_mimes,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			)
		);

		$failed = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => self::$supported_mimes,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::RESULT_META,
						'value'   => '"error"',
						'compare' => 'LIKE',
					),
				),
			)
		);

		$missing = $this->pending_count( false );

		return array(
			'total'   => (int) $total->found_posts,
			'missing' => $missing,
			'failed'  => (int) $failed->found_posts,
			'done'    => max( 0, (int) $total->found_posts - $missing ),
			'usage'   => $this->usage_totals(),
		);
	}

	/**
	 * Adds one call's token usage to the running total.
	 *
	 * @param array $usage {input, output}.
	 * @return void
	 */
	private function record_usage( $usage ) {
		if ( empty( $usage['input'] ) && empty( $usage['output'] ) ) {
			return;
		}

		$totals = $this->usage_totals();

		$totals['input']  += (int) ( $usage['input'] ?? 0 );
		$totals['output'] += (int) ( $usage['output'] ?? 0 );
		$totals['images']++;
		$totals['updated'] = time();

		update_option( self::USAGE_OPTION, $totals, false );
	}

	/**
	 * Running token totals for this feature.
	 *
	 * @return array
	 */
	public function usage_totals() {
		$stored = get_option( self::USAGE_OPTION, array() );

		return wp_parse_args(
			is_array( $stored ) ? $stored : array(),
			array(
				'input'   => 0,
				'output'  => 0,
				'images'  => 0,
				'updated' => 0,
			)
		);
	}

	/**
	 * Resets the token counter.
	 *
	 * @return void
	 */
	public function reset_usage() {
		delete_option( self::USAGE_OPTION );
	}

	/**
	 * Clears the per-image processing record so the whole library can be re-run.
	 *
	 * Each image is attempted at most once per record, which is what keeps a
	 * permanently failing file from stalling the batch loop forever. Resetting
	 * is therefore an explicit action rather than something "overwrite" implies.
	 *
	 * @return int Number of cleared records.
	 */
	public function reset_records( $only_failed = false ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => self::RESULT_META,
					'compare' => 'EXISTS',
				),
			),
		);

		if ( $only_failed ) {
			// Serialized records carry an "error" key only when the run failed,
			// so retrying just the failures never re-bills successful images.
			$args['meta_query'][0] = array(
				'key'     => self::RESULT_META,
				'value'   => '"error"',
				'compare' => 'LIKE',
			);
		}

		$ids = get_posts( $args );

		foreach ( $ids as $attachment_id ) {
			delete_post_meta( $attachment_id, self::RESULT_META );
		}

		return count( $ids );
	}

	/**
	 * Shared query for images needing description.
	 *
	 * @param bool $overwrite Include images that already have alt text.
	 * @return array
	 */
	private function pending_query_args( $overwrite ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => self::$supported_mimes,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'meta_query'     => array(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		);

		if ( ! $overwrite ) {
			$args['meta_query'][] = array(
				'relation' => 'OR',
				array(
					'key'     => '_wp_attachment_image_alt',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_wp_attachment_image_alt',
					'value'   => '',
					'compare' => '=',
				),
			);
		}

		// Never retry an image that already failed — it would block the queue.
		$args['meta_query'][] = array(
			'key'     => self::RESULT_META,
			'compare' => 'NOT EXISTS',
		);

		if ( count( $args['meta_query'] ) > 1 ) {
			$args['meta_query']['relation'] = 'AND';
		}

		return $args;
	}

	/**
	 * Builds page context so the model can write on-topic alt text.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	private function attachment_context( $attachment_id ) {
		$attachment = get_post( $attachment_id );
		$context    = array(
			'site_name'    => get_bloginfo( 'name' ),
			'site_tagline' => get_bloginfo( 'description' ),
			'file_name'    => $attachment ? basename( (string) get_attached_file( $attachment_id ) ) : '',
		);

		$parent_id = $attachment ? (int) $attachment->post_parent : 0;

		if ( $parent_id && get_post( $parent_id ) ) {
			$context['used_on'] = array(
				'title'     => get_the_title( $parent_id ),
				'post_type' => get_post_type( $parent_id ),
				'excerpt'   => Super_SEO_Helpers::excerpt_from_content( get_post_field( 'post_content', $parent_id ), 300 ),
			);
		}

		$automation = $this->plugin->automation();

		if ( $automation ) {
			$profile = $automation->product_profile();

			if ( ! empty( $profile['core_keywords'] ) ) {
				$context['site_keywords'] = array_slice( (array) $profile['core_keywords'], 0, 8 );
			}

			if ( ! empty( $profile['product_types'] ) ) {
				$context['product_types'] = array_slice( (array) $profile['product_types'], 0, 8 );
			}
		}

		return $context;
	}

	/**
	 * Reads an attachment, downscales it and returns base64 data.
	 *
	 * Downscaling keeps image-token cost predictable: the models accept up to
	 * 2576px on the long edge, but alt text does not need that fidelity.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|WP_Error
	 */
	private function image_payload( $attachment_id ) {
		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'super_seo_vision_missing_file', '找不到图片文件，可能已被删除或托管在外部存储。' );
		}

		$max_edge = (int) $this->plugin->setting( 'vision_max_edge', 1024 );
		$max_edge = max( 320, min( 2576, $max_edge ) );
		$editor   = wp_get_image_editor( $file );

		if ( ! is_wp_error( $editor ) ) {
			$editor->resize( $max_edge, $max_edge, false );

			if ( method_exists( $editor, 'set_quality' ) ) {
				$editor->set_quality( 82 );
			}

			$temp = wp_tempnam( 'super-seo-vision.jpg' );

			if ( $temp ) {
				$saved = $editor->save( $temp, 'image/jpeg' );

				if ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) && file_exists( $saved['path'] ) ) {
					$data = file_get_contents( $saved['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

					wp_delete_file( $saved['path'] );

					if ( $saved['path'] !== $temp && file_exists( $temp ) ) {
						wp_delete_file( $temp );
					}

					if ( false !== $data && '' !== $data ) {
						return array(
							'mime' => 'image/jpeg',
							'data' => base64_encode( $data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
						);
					}
				} elseif ( file_exists( $temp ) ) {
					wp_delete_file( $temp );
				}
			}
		}

		// Fallback: send the original when the image editor is unavailable.
		$size = (int) @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( $size > 3 * MB_IN_BYTES ) {
			return new WP_Error( 'super_seo_vision_too_large', '图片过大且服务器无法压缩，请安装 GD/Imagick 或手动压缩后重试。' );
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		$data = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $data || '' === $data ) {
			return new WP_Error( 'super_seo_vision_unreadable', '图片无法读取，请检查文件权限。' );
		}

		return array(
			'mime' => in_array( $mime, self::$supported_mimes, true ) ? $mime : 'image/jpeg',
			'data' => base64_encode( $data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		);
	}

	/**
	 * Whether an attachment is an image the API can read.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function is_supported_image( $attachment_id ) {
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return false;
		}

		return in_array( (string) get_post_mime_type( $attachment_id ), self::$supported_mimes, true );
	}

	/**
	 * Detects WordPress' auto-generated "IMG_1234" style titles.
	 *
	 * @param string $title         Current title.
	 * @param int    $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function is_placeholder_title( $title, $attachment_id = 0 ) {
		$title = trim( (string) $title );

		if ( '' === $title ) {
			return true;
		}

		if ( preg_match( '/^(img|image|dsc|dscn|pxl|photo|screenshot|微信图片|未命名)[\s_\-]*\d*$/iu', $title ) ) {
			return true;
		}

		// A title that is still just the sanitized file name carries no meaning.
		if ( $attachment_id && function_exists( 'get_attached_file' ) ) {
			$file = (string) get_attached_file( $attachment_id );

			if ( '' !== $file ) {
				$base = pathinfo( $file, PATHINFO_FILENAME );

				if ( '' !== $base && strtolower( str_replace( array( '-', '_' ), ' ', $base ) ) === strtolower( str_replace( array( '-', '_' ), ' ', $title ) ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
