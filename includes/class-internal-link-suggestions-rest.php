<?php
/**
 * REST routes used by the editor panels and the report page.
 *
 * Every route needs a logged-in user with the right capability for the post
 * involved (the REST cookie nonce proves the request came from wp-admin).
 * Suggestions only ever show published, public posts.
 *
 * @package Internal_Link_Suggestions
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST controller.
 */
final class Internal_Link_Suggestions_REST {

	/**
	 * Route namespace.
	 */
	const NS = 'internal-link-suggestions/v1';

	/**
	 * Most bytes of editor content accepted (it's analysed, never stored).
	 */
	const MAX_CONTENT = 2000000;

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

		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register() {
		$post_id = array(
			'type'     => 'integer',
			'required' => true,
			'minimum'  => 1,
		);
		$text    = array(
			'type'    => 'string',
			'default' => '',
		);

		register_rest_route(
			self::NS,
			'/outbound',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'outbound' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array(
					'post_id'  => $post_id,
					'title'    => $text,
					'content'  => $text,
					'keywords' => $text,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/inbound',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'inbound' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array(
					'post_id'  => $post_id,
					'title'    => array( 'type' => 'string' ),
					'content'  => array( 'type' => 'string' ),
					'keywords' => array( 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/link',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'link' ),
				'permission_callback' => array( $this, 'can_edit_source' ),
				'args'                => array(
					'source_id' => $post_id,
					'target_id' => $post_id,
					'anchor'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'context'   => $text,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/undo',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'undo' ),
				'permission_callback' => array( $this, 'can_undo' ),
				'args'                => array(
					'log' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/links',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'links' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array(
					'post_id' => $post_id,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/post-settings',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_settings' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array(
					'post_id'  => $post_id,
					'keywords' => array( 'type' => 'string' ),
					'excluded' => array( 'type' => 'boolean' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/scan',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'scan' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'restart' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	// Permissions ------------------------------------------------------------------

	/**
	 * The user may edit the post in post_id, and it's of an included type.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_edit_post( WP_REST_Request $request ) {
		$post = get_post( (int) $request['post_id'] );

		return $post && current_user_can( 'edit_post', $post->ID ) && in_array( $post->post_type, $this->plugin->post_types(), true );
	}

	/**
	 * The user may edit the post a link would be added to.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_edit_source( WP_REST_Request $request ) {
		return current_user_can( 'edit_post', (int) $request['source_id'] );
	}

	/**
	 * The user may edit the post a logged link was added to.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_undo( WP_REST_Request $request ) {
		$log = Internal_Link_Suggestions_DB::get_log( (int) $request['log'] );

		return $log && current_user_can( 'edit_post', (int) $log['source_id'] );
	}

	/**
	 * The user may run the scan.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( Internal_Link_Suggestions_Admin::CAPABILITY );
	}

	// Callbacks --------------------------------------------------------------------

	/**
	 * Posts to link to from the draft in the editor.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function outbound( WP_REST_Request $request ) {
		$post_id  = (int) $request['post_id'];
		$keywords = Internal_Link_Suggestions_Indexer::clean_keywords(
			array_merge(
				Internal_Link_Suggestions_Indexer::split_keywords( self::text( $request['keywords'], 2000 ) ),
				Internal_Link_Suggestions_Indexer::seo_keywords( $post_id )
			)
		);

		$result = $this->plugin->matcher()->outbound(
			$post_id,
			self::text( $request['title'], 1000 ),
			self::text( $request['content'], self::MAX_CONTENT ),
			$keywords
		);

		$result['index'] = $this->index_status();

		return rest_ensure_response( $result );
	}

	/**
	 * Older posts that should link to the post in the editor (or a post in
	 * the report).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function inbound( WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		$post    = get_post( $post_id );

		$result = $this->plugin->matcher()->inbound(
			$post_id,
			null === $request['title'] ? null : self::text( $request['title'], 1000 ),
			null === $request['keywords'] ? null : Internal_Link_Suggestions_Indexer::split_keywords( self::text( $request['keywords'], 2000 ) ),
			null === $request['content'] ? null : self::text( $request['content'], self::MAX_CONTENT )
		);

		$linkable = 'publish' === $post->post_status && '' === $post->post_password && ! Internal_Link_Suggestions_Indexer::is_excluded( $post_id );

		$result['linkable'] = $linkable;
		$result['index']    = $this->index_status();

		if ( ! $linkable ) {
			foreach ( $result['items'] as $i => $item ) {
				$result['items'][ $i ]['canAdd'] = false;
			}
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Add a link to an older post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function link( WP_REST_Request $request ) {
		$log = $this->plugin->linker()->add(
			(int) $request['source_id'],
			(int) $request['target_id'],
			self::text( $request['anchor'], 500 ),
			self::text( $request['context'], 1000 )
		);

		if ( is_wp_error( $log ) ) {
			return $log;
		}

		return rest_ensure_response(
			array(
				'log'     => $log,
				'message' => __( 'Link added.', 'internal-link-suggestions' ),
			)
		);
	}

	/**
	 * Undo a link the plugin added.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function undo( WP_REST_Request $request ) {
		$message = $this->plugin->linker()->undo( (int) $request['log'] );

		if ( is_wp_error( $message ) ) {
			return $message;
		}

		return rest_ensure_response( array( 'message' => $message ) );
	}

	/**
	 * The posts linking to and from a post, for the report.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function links( WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		$out     = array();

		foreach ( array( 'in', 'out' ) as $direction ) {
			$out[ $direction ] = array();

			foreach ( Internal_Link_Suggestions_DB::link_list( $post_id, $direction ) as $row ) {
				$other              = (int) $row['post_id'];
				$edit               = current_user_can( 'edit_post', $other ) ? get_edit_post_link( $other, 'raw' ) : '';
				$out[ $direction ][] = array(
					'id'     => $other,
					'title'  => (string) $row['title'],
					'anchor' => (string) $row['anchor'],
					'url'    => (string) get_permalink( $other ),
					'edit'   => $edit ? (string) $edit : '',
				);
			}
		}

		return rest_ensure_response( $out );
	}

	/**
	 * Save a post's link keywords and "not a link target" flag.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function post_settings( WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];

		if ( null !== $request['keywords'] ) {
			$keywords = Internal_Link_Suggestions_Indexer::split_keywords( self::text( $request['keywords'], 2000 ) );

			if ( $keywords ) {
				update_post_meta( $post_id, Internal_Link_Suggestions_Indexer::KEYWORDS_META, wp_slash( implode( "\n", $keywords ) ) );
			} else {
				delete_post_meta( $post_id, Internal_Link_Suggestions_Indexer::KEYWORDS_META );
			}
		}

		if ( null !== $request['excluded'] ) {
			if ( $request['excluded'] ) {
				update_post_meta( $post_id, Internal_Link_Suggestions_Indexer::EXCLUDE_META, 1 );
			} else {
				delete_post_meta( $post_id, Internal_Link_Suggestions_Indexer::EXCLUDE_META );
			}

			Internal_Link_Suggestions_DB::set_excluded( $post_id, (bool) $request['excluded'] );
		}

		return rest_ensure_response(
			array(
				'keywords' => Internal_Link_Suggestions_Indexer::own_keywords( $post_id ),
				'seo'      => Internal_Link_Suggestions_Indexer::seo_keywords( $post_id ),
				'excluded' => Internal_Link_Suggestions_Indexer::is_excluded( $post_id ),
			)
		);
	}

	/**
	 * Run a scan batch while the report page is open (WP-Cron may be off or
	 * slow on quiet sites), or start a fresh scan.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function scan( WP_REST_Request $request ) {
		if ( $request['restart'] ) {
			Internal_Link_Suggestions_Indexer::start_scan();
		}

		$state = $this->plugin->indexer()->run_batch( 8 );

		return rest_ensure_response(
			array(
				'status' => $state['status'],
				'done'   => (int) $state['done'],
				'total'  => (int) $state['total'],
			)
		);
	}

	// Helpers ------------------------------------------------------------------------

	/**
	 * Whether the index is ready, for the editor's messages.
	 *
	 * @return array{posts:int,scanning:bool}
	 */
	private function index_status() {
		$state = Internal_Link_Suggestions_Indexer::get_state();

		return array(
			'posts'    => Internal_Link_Suggestions_DB::count_posts(),
			'scanning' => 'running' === $state['status'],
		);
	}

	/**
	 * A request string, valid UTF-8 and at most $bytes long.
	 *
	 * @param mixed $value Value.
	 * @param int   $bytes Most bytes.
	 * @return string
	 */
	private static function text( $value, $bytes ) {
		$value = is_scalar( $value ) ? (string) $value : '';

		return Internal_Link_Suggestions_Text::cut( wp_check_invalid_utf8( $value, true ), $bytes );
	}
}
