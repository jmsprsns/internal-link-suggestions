<?php
/**
 * Adds a suggested link to an older post, and takes it out again.
 *
 * The edit is surgical: the anchor's words are found again in the post's
 * saved HTML (in a paragraph or list item, never inside a heading, another
 * link or a shortcode), and only an opening <a href> and a closing </a> are
 * added around them. Nothing else in the post changes.
 *
 * @package Internal_Link_Suggestions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Server-side link edits.
 */
final class Internal_Link_Suggestions_Linker {

	/**
	 * Plugin instance.
	 *
	 * @var Internal_Link_Suggestions_Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Internal_Link_Suggestions_Plugin $plugin Plugin instance.
	 */
	public function __construct( Internal_Link_Suggestions_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Why the current user can't have a link added to a post from here, or
	 * '' when they can.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function blocked( $post_id ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return __( 'You can\'t edit this post.', 'internal-link-suggestions' );
		}

		$builder = self::builder( $post_id );
		if ( '' !== $builder ) {
			/* translators: %s: page builder name. */
			return sprintf( __( 'Built with %s, so add the link there.', 'internal-link-suggestions' ), $builder );
		}

		$editing = self::locked_by( $post_id );
		if ( '' !== $editing ) {
			/* translators: %s: user's name. */
			return sprintf( __( '%s is editing it right now.', 'internal-link-suggestions' ), $editing );
		}

		return '';
	}

	/**
	 * The page builder a post is built with, if its content is rebuilt from
	 * the builder's own data (an edit to post_content would be overwritten).
	 *
	 * @param int $post_id Post ID.
	 * @return string Builder name, or ''.
	 */
	public static function builder( $post_id ) {
		$builders = array(
			'Elementor'      => 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ),
			'Beaver Builder' => (bool) get_post_meta( $post_id, '_fl_builder_enabled', true ),
			'Bricks'         => 'bricks' === get_post_meta( $post_id, '_bricks_editor_mode', true ),
			'Oxygen'         => (bool) get_post_meta( $post_id, 'ct_builder_shortcodes', true ) || (bool) get_post_meta( $post_id, '_ct_builder_json', true ),
			'Breakdance'     => (bool) get_post_meta( $post_id, '_breakdance_data', true ),
		);

		foreach ( $builders as $name => $used ) {
			if ( $used ) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * Who else has a post open in the editor right now (WordPress's own
	 * post lock), or ''.
	 *
	 * @param int $post_id Post ID.
	 * @return string Display name.
	 */
	public static function locked_by( $post_id ) {
		$lock = (string) get_post_meta( $post_id, '_edit_lock', true );

		if ( '' === $lock ) {
			return '';
		}

		$parts   = explode( ':', $lock );
		$time    = (int) $parts[0];
		$user_id = isset( $parts[1] ) ? (int) $parts[1] : (int) get_post_meta( $post_id, '_edit_last', true );

		/** This filter is documented in wp-admin/includes/ajax-actions.php */
		$window = (int) apply_filters( 'wp_check_post_lock_window', 150 );

		if ( ! $user_id || get_current_user_id() === $user_id || $time <= time() - $window ) {
			return '';
		}

		$user = get_userdata( $user_id );

		return $user ? (string) $user->display_name : __( 'Someone', 'internal-link-suggestions' );
	}

	/**
	 * Add a link to $target_id around the anchor's words in $source_id.
	 *
	 * @param int    $source_id Post to edit.
	 * @param int    $target_id Post to link to.
	 * @param string $anchor    Words to link, as suggested.
	 * @param string $context   The sentence they were in, to pick the right
	 *                          place when the words appear more than once.
	 * @return int|WP_Error Log ID.
	 */
	public function add( $source_id, $target_id, $anchor, $context ) {
		$source = get_post( $source_id );
		$target = get_post( $target_id );
		$types  = $this->plugin->post_types();

		if ( ! $source || ! $target || $source->ID === $target->ID ) {
			return new WP_Error( 'ils_missing', __( 'That post doesn\'t exist anymore.', 'internal-link-suggestions' ), array( 'status' => 404 ) );
		}

		if ( 'publish' !== $target->post_status || '' !== $target->post_password || ! in_array( $target->post_type, $types, true ) || Internal_Link_Suggestions_Indexer::is_excluded( $target->ID ) ) {
			return new WP_Error( 'ils_target', __( 'Links can only be added to published posts once they have a public address.', 'internal-link-suggestions' ), array( 'status' => 400 ) );
		}

		if ( 'publish' !== $source->post_status || ! in_array( $source->post_type, $types, true ) ) {
			return new WP_Error( 'ils_source', __( 'Links are only added to published posts.', 'internal-link-suggestions' ), array( 'status' => 400 ) );
		}

		$blocked = self::blocked( $source->ID );
		if ( '' !== $blocked ) {
			return new WP_Error( 'ils_blocked', $blocked, array( 'status' => 403 ) );
		}

		$content = (string) $source->post_content;
		$links   = $this->plugin->indexer()->extract_links( $content, $source->ID );

		if ( isset( $links[ $target->ID ] ) ) {
			return new WP_Error( 'ils_exists', __( 'That post already links here.', 'internal-link-suggestions' ), array( 'status' => 409 ) );
		}

		$place = self::locate( $content, $anchor, $context );
		if ( is_wp_error( $place ) ) {
			return $place;
		}

		$original = substr( $content, $place[0], $place[1] - $place[0] );
		$inserted = '<a href="' . esc_url( get_permalink( $target ) ) . '">' . $original . '</a>';
		$updated  = substr( $content, 0, $place[0] ) . $inserted . substr( $content, $place[1] );

		if ( ! $this->save( $source, $updated ) ) {
			return new WP_Error( 'ils_save', __( 'The post couldn\'t be saved. Nothing was changed.', 'internal-link-suggestions' ), array( 'status' => 500 ) );
		}

		$saved    = (string) get_post_field( 'post_content', $source->ID, 'raw' );
		$position = strpos( $saved, $inserted );

		if ( false === $position ) {
			return new WP_Error( 'ils_filtered', __( 'The post was saved, but another plugin removed the link as it was saved.', 'internal-link-suggestions' ), array( 'status' => 500 ) );
		}

		return Internal_Link_Suggestions_DB::add_log(
			array(
				'created'      => time(),
				'user_id'      => get_current_user_id(),
				'source_id'    => $source->ID,
				'target_id'    => $target->ID,
				'anchor'       => Internal_Link_Suggestions_Text::cut( Internal_Link_Suggestions_Text::plain( $original ), 255 ),
				'url'          => (string) get_permalink( $target ),
				'original'     => $original,
				'inserted'     => $inserted,
				'position'     => $position,
				'content_hash' => md5( $saved ),
				'status'       => 'added',
			)
		);
	}

	/**
	 * Take a link the plugin added back out, leaving its words.
	 *
	 * Exact when the post hasn't changed since; otherwise only when the
	 * added link appears exactly once in it.
	 *
	 * @param int $log_id Log ID.
	 * @return string|WP_Error Message.
	 */
	public function undo( $log_id ) {
		$log = Internal_Link_Suggestions_DB::get_log( $log_id );

		if ( ! $log || 'added' !== $log['status'] ) {
			return new WP_Error( 'ils_log', __( 'That link was already undone.', 'internal-link-suggestions' ), array( 'status' => 409 ) );
		}

		$source = get_post( (int) $log['source_id'] );

		if ( ! $source ) {
			Internal_Link_Suggestions_DB::mark_undone( (int) $log['id'] );
			return __( 'The post was deleted, so there was nothing to undo.', 'internal-link-suggestions' );
		}

		$blocked = self::blocked( $source->ID );
		if ( '' !== $blocked ) {
			return new WP_Error( 'ils_blocked', $blocked, array( 'status' => 403 ) );
		}

		$content  = (string) $source->post_content;
		$inserted = (string) $log['inserted'];
		$position = (int) $log['position'];

		if ( md5( $content ) === $log['content_hash'] && substr( $content, $position, strlen( $inserted ) ) === $inserted ) {
			$at = $position;
		} else {
			$count = '' === $inserted ? 0 : substr_count( $content, $inserted );

			if ( 0 === $count ) {
				Internal_Link_Suggestions_DB::mark_undone( (int) $log['id'] );
				return __( 'The link had already been removed from the post.', 'internal-link-suggestions' );
			}

			if ( $count > 1 ) {
				return new WP_Error( 'ils_ambiguous', __( 'The post has changed and now has that same link more than once. Remove the one you want in the editor.', 'internal-link-suggestions' ), array( 'status' => 409 ) );
			}

			$at = strpos( $content, $inserted );
		}

		$updated = substr( $content, 0, $at ) . $log['original'] . substr( $content, $at + strlen( $inserted ) );

		if ( ! $this->save( $source, $updated ) ) {
			return new WP_Error( 'ils_save', __( 'The post couldn\'t be saved. Nothing was changed.', 'internal-link-suggestions' ), array( 'status' => 500 ) );
		}

		Internal_Link_Suggestions_DB::mark_undone( (int) $log['id'] );

		return __( 'Link removed. The words are still there.', 'internal-link-suggestions' );
	}

	/**
	 * Where to put a link: the anchor's words in a linkable piece of the
	 * post's raw HTML, preferring the sentence it was suggested in.
	 *
	 * @param string $content Post content.
	 * @param string $anchor  Anchor text.
	 * @param string $context Its sentence.
	 * @return int[]|WP_Error Raw byte offsets array( start, end ).
	 */
	public static function locate( $content, $anchor, $context ) {
		$pattern = Internal_Link_Suggestions_Text::anchor_pattern( Internal_Link_Suggestions_Text::collapse( $anchor ) );

		if ( null === $pattern ) {
			return new WP_Error( 'ils_anchor', __( 'No words to link.', 'internal-link-suggestions' ), array( 'status' => 400 ) );
		}

		$chunks    = Internal_Link_Suggestions_Text::chunks( $content );
		$want      = Internal_Link_Suggestions_Text::collapse( $context );
		$first     = null;
		$preferred = null;

		$ok = Internal_Link_Suggestions_Text::walk(
			$content,
			function ( $raw, $start, $linkable, $chunk ) use ( $pattern, $chunks, $want, &$first, &$preferred ) {
				if ( ! $linkable || null !== $preferred ) {
					return;
				}

				list( $decoded, $map ) = Internal_Link_Suggestions_Text::decode_map( $raw );

				if ( ! preg_match( $pattern, $decoded, $m, PREG_OFFSET_CAPTURE ) ) {
					return;
				}

				$place = array( $start + $map[ $m[0][1] ], $start + $map[ $m[0][1] + strlen( $m[0][0] ) ] );

				if ( '' !== $want && isset( $chunks[ $chunk ] ) && false !== strpos( Internal_Link_Suggestions_Text::collapse( $chunks[ $chunk ]['text'] ), $want ) ) {
					$preferred = $place;
				} elseif ( null === $first ) {
					$first = $place;
				}
			}
		);

		$place = $preferred ? $preferred : $first;

		if ( ! $ok || ! $place || $place[1] <= $place[0] ) {
			return new WP_Error( 'ils_not_found', __( 'Those words aren\'t in a paragraph of the post anymore. It may have changed since the suggestion was made.', 'internal-link-suggestions' ), array( 'status' => 409 ) );
		}

		return $place;
	}

	/**
	 * Save new content for a post.
	 *
	 * - kses still runs, but this post's content is put back afterwards
	 *   exactly as built here: the only new markup is the plugin's own
	 *   <a href> with an escaped permalink, and running the whole post through
	 *   kses as a user without unfiltered_html would strip embeds and other
	 *   markup an administrator was allowed to add. Nothing else saved in the
	 *   same request skips kses.
	 * - the "last modified" date stays the same: a new link isn't new content.
	 * - a post with no revisions gets one of its old content first, so the
	 *   change can always be rolled back from the Revisions screen too.
	 *
	 * @param WP_Post $post    Post as it is now.
	 * @param string  $content New content.
	 * @return bool
	 */
	private function save( WP_Post $post, $content ) {
		if ( function_exists( 'wp_revisions_enabled' ) && wp_revisions_enabled( $post ) && function_exists( '_wp_put_post_revision' ) ) {
			$revisions = wp_get_post_revisions(
				$post->ID,
				array(
					'fields'         => 'ids',
					'posts_per_page' => 1,
				)
			);
			if ( ! $revisions ) {
				_wp_put_post_revision( $post );
			}
		}

		// wp_insert_post_data runs after kses and still sees slashed data.
		$restore = function ( $data, $postarr ) use ( $post, $content ) {
			if ( isset( $postarr['ID'] ) && (int) $postarr['ID'] === (int) $post->ID ) {
				$data['post_content']      = wp_slash( $content );
				$data['post_modified']     = $post->post_modified;
				$data['post_modified_gmt'] = $post->post_modified_gmt;
			}
			return $data;
		};

		add_filter( 'wp_insert_post_data', $restore, 99, 2 );

		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post->ID,
					'post_content' => $content,
				)
			),
			true
		);

		remove_filter( 'wp_insert_post_data', $restore, 99 );

		clean_post_cache( $post->ID );

		return $result && ! is_wp_error( $result );
	}
}
