<?php
/**
 * Keeps the index current: one row per published post with its words,
 * keywords and internal links. Posts are indexed when they're saved, and a
 * background scan (WP-Cron, plus the report page while it's open) covers
 * everything else in small batches.
 *
 * @package Internal_Link_Suggestions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Indexer and link resolver.
 */
final class Internal_Link_Suggestions_Indexer {

	/**
	 * Option holding the full scan's progress.
	 */
	const SCAN_OPTION = 'internal_link_suggestions_scan';

	/**
	 * WP-Cron hook for the next scan batch.
	 */
	const SCAN_HOOK = 'internal_link_suggestions_scan';

	/**
	 * Option used as a lock, so two batches never run at once.
	 */
	const LOCK_OPTION = 'internal_link_suggestions_scan_lock';

	/**
	 * Seconds a lock is honoured before it's treated as abandoned.
	 */
	const LOCK_TTL = 90;

	/**
	 * Posts fetched per query during a scan.
	 */
	const BATCH = 25;

	/**
	 * The plugin's own keywords meta.
	 */
	const KEYWORDS_META = '_ils_keywords';

	/**
	 * The "not a link target" flag.
	 */
	const EXCLUDE_META = '_ils_exclude';

	/**
	 * Focus keyword fields from SEO plugins, read as extra keywords.
	 * Meta key => format (single, comma, lines or yoast JSON).
	 */
	const SEO_META = array(
		'_yoast_wpseo_focuskw'         => 'single',
		'_yoast_wpseo_focuskeywords'   => 'yoast',
		'rank_math_focus_keyword'      => 'comma',
		'_seopress_analysis_target_kw' => 'comma',
	);

	/**
	 * Plugin instance.
	 *
	 * @var Internal_Link_Suggestions_Plugin
	 */
	private $plugin;

	/**
	 * Posts to reindex at the end of this request (meta changed).
	 *
	 * @var array<int,bool>
	 */
	private $pending = array();

	/**
	 * Resolved URLs for this request.
	 *
	 * @var array<string,int>
	 */
	private $resolved = array();

	/**
	 * Constructor.
	 *
	 * @param Internal_Link_Suggestions_Plugin $plugin Plugin instance.
	 */
	public function __construct( Internal_Link_Suggestions_Plugin $plugin ) {
		$this->plugin = $plugin;

		// After the post, its terms and (in the block editor) its meta are saved.
		add_action( 'wp_after_insert_post', array( $this, 'on_save' ), 20, 2 );
		add_action( 'trashed_post', array( $this, 'on_gone' ) );
		add_action( 'deleted_post', array( $this, 'on_gone' ) );

		add_action( 'added_post_meta', array( $this, 'on_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'on_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'on_meta' ), 10, 3 );

		add_action( self::SCAN_HOOK, array( $this, 'cron_batch' ) );
	}

	// Saving ---------------------------------------------------------------------

	/**
	 * Index a post after it's saved.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public function on_save( $post_id, $post ) {
		if ( ! $post instanceof WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		$this->index_post( $post );
		unset( $this->pending[ (int) $post_id ] );
	}

	/**
	 * Drop a post that was trashed or deleted.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_gone( $post_id ) {
		if ( Internal_Link_Suggestions_DB::get_post( $post_id ) ) {
			Internal_Link_Suggestions_DB::recount( Internal_Link_Suggestions_DB::remove_post( (int) $post_id ) );
		}
	}

	/**
	 * Reindex a post once this request ends when its keywords or exclude
	 * flag change (SEO plugins often save their keyword after the post).
	 *
	 * @param int|int[] $meta_id   Meta ID(s).
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key.
	 * @return void
	 */
	public function on_meta( $meta_id, $object_id, $meta_key ) {
		if ( self::KEYWORDS_META !== $meta_key && self::EXCLUDE_META !== $meta_key && ! isset( self::SEO_META[ $meta_key ] ) ) {
			return;
		}

		if ( ! $this->pending ) {
			add_action( 'shutdown', array( $this, 'flush_pending' ) );
		}

		$this->pending[ (int) $object_id ] = true;
	}

	/**
	 * Reindex posts whose meta changed during this request.
	 *
	 * @return void
	 */
	public function flush_pending() {
		$ids           = array_keys( $this->pending );
		$this->pending = array();

		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );
			if ( $post && ( Internal_Link_Suggestions_DB::get_post( $post_id ) || $this->indexable( $post ) ) ) {
				$this->index_post( $post );
			}
		}
	}

	/**
	 * Whether a post belongs in the index: published, of an included type.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public function indexable( WP_Post $post ) {
		$indexable = 'publish' === $post->post_status && in_array( $post->post_type, $this->plugin->post_types(), true );

		/**
		 * Filters whether a post is indexed: suggested as a link, searched
		 * for links to other posts, and listed in the reports.
		 *
		 * @param bool    $indexable Whether it's indexed.
		 * @param WP_Post $post      Post.
		 */
		return (bool) apply_filters( 'internal_link_suggestions_index_post', $indexable, $post );
	}

	/**
	 * Add, update or remove a post's index row, words and links.
	 *
	 * @param int|WP_Post $post Post.
	 * @return void
	 */
	public function index_post( $post ) {
		$post = get_post( $post );

		if ( ! $post ) {
			return;
		}

		if ( ! $this->indexable( $post ) ) {
			if ( Internal_Link_Suggestions_DB::get_post( $post->ID ) ) {
				Internal_Link_Suggestions_DB::recount( Internal_Link_Suggestions_DB::remove_post( $post->ID ) );
			}
			return;
		}

		$content  = Internal_Link_Suggestions_Text::cut( (string) $post->post_content, Internal_Link_Suggestions_Text::MAX_BYTES );
		$title    = Internal_Link_Suggestions_Text::plain( $post->post_title );
		$keywords = self::keywords( $post->ID );
		$terms    = array();
		$words    = 0;

		foreach ( Internal_Link_Suggestions_Text::chunks( $content ) as $chunk ) {
			foreach ( Internal_Link_Suggestions_Text::words( $chunk['text'] ) as $word ) {
				++$words;
				$key = Internal_Link_Suggestions_Text::key( $word[0] );
				if ( ! Internal_Link_Suggestions_Text::is_stop( $key ) ) {
					$key           = Internal_Link_Suggestions_Text::cut( $key, 240 );
					$terms[ $key ] = isset( $terms[ $key ] ) ? $terms[ $key ] + 1 : 1;
				}
			}
		}

		// Common words in a long post shouldn't drown out its title.
		foreach ( $terms as $key => $count ) {
			$terms[ $key ] = min( $count, 20 );
		}

		foreach ( array_unique( Internal_Link_Suggestions_Text::phrase_keys( $title ) ) as $key ) {
			$terms[ $key ] = ( isset( $terms[ $key ] ) ? $terms[ $key ] : 0 ) + 6;
		}

		foreach ( $keywords as $keyword ) {
			foreach ( array_unique( Internal_Link_Suggestions_Text::phrase_keys( $keyword ) ) as $key ) {
				$terms[ $key ] = ( isset( $terms[ $key ] ) ? $terms[ $key ] : 0 ) + 4;
			}
		}

		arsort( $terms );
		$terms = array_slice( $terms, 0, Internal_Link_Suggestions_DB::TERMS_PER_POST, true );

		$links = $this->extract_links( $content, $post->ID );
		$path  = self::permalink_path( $post );

		$changed = Internal_Link_Suggestions_DB::save_post(
			array(
				'post_id'   => $post->ID,
				'post_type' => $post->post_type,
				'title'     => Internal_Link_Suggestions_Text::cut( '' === $title ? __( '(no title)', 'internal-link-suggestions' ) : $title, 1000 ),
				'path'      => $path,
				'path_hash' => '' === $path ? '' : md5( $path ),
				'keywords'  => implode( "\n", $keywords ),
				'published' => (int) get_post_time( 'U', true, $post ),
				'words'     => $words,
				'inbound'   => 0,
				'outbound'  => count( $links ),
				'excluded'  => self::is_excluded( $post->ID ) ? 1 : 0,
				'protected' => '' !== $post->post_password ? 1 : 0,
				'scanned'   => time(),
			),
			$terms,
			$links
		);

		$changed[] = $post->ID;
		Internal_Link_Suggestions_DB::recount( $changed );
	}

	/**
	 * Whether a post is flagged "not a link target".
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_excluded( $post_id ) {
		return (bool) get_post_meta( $post_id, self::EXCLUDE_META, true );
	}

	/**
	 * A post's own link keywords (set in its editor).
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	public static function own_keywords( $post_id ) {
		return self::split_keywords( (string) get_post_meta( $post_id, self::KEYWORDS_META, true ) );
	}

	/**
	 * A post's focus keywords from SEO plugins, by plugin.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	public static function seo_keywords( $post_id ) {
		$out = array();

		foreach ( self::SEO_META as $key => $format ) {
			$value = get_post_meta( $post_id, $key, true );

			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				continue;
			}

			if ( 'yoast' === $format ) {
				$list = json_decode( $value, true );
				$list = is_array( $list ) ? array_column( array_filter( $list, 'is_array' ), 'keyword' ) : array();
			} elseif ( 'comma' === $format ) {
				$list = explode( ',', $value );
			} else {
				$list = array( $value );
			}

			$out = array_merge( $out, array_map( 'strval', $list ) );
		}

		// All in One SEO keeps its keyphrases in its own table.
		if ( function_exists( 'aioseo' ) ) {
			global $wpdb;

			$table = $wpdb->prefix . 'aioseo_posts';
			$json  = $wpdb->get_var( $wpdb->prepare( 'SELECT keyphrases FROM ' . esc_sql( $table ) . ' WHERE post_id = %d LIMIT 1', $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQueryCaching -- Read-only lookup in AIOSEO's table.
			$data  = is_string( $json ) ? json_decode( $json, true ) : null;

			if ( is_array( $data ) ) {
				if ( ! empty( $data['focus']['keyphrase'] ) ) {
					$out[] = (string) $data['focus']['keyphrase'];
				}
				if ( ! empty( $data['additional'] ) && is_array( $data['additional'] ) ) {
					foreach ( $data['additional'] as $extra ) {
						if ( is_array( $extra ) && ! empty( $extra['keyphrase'] ) ) {
							$out[] = (string) $extra['keyphrase'];
						}
					}
				}
			}
		}

		return self::clean_keywords( $out );
	}

	/**
	 * Every keyword a post can be linked with: its own, then its SEO
	 * plugin's focus keywords.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	public static function keywords( $post_id ) {
		return self::clean_keywords( array_merge( self::own_keywords( $post_id ), self::seo_keywords( $post_id ) ) );
	}

	/**
	 * Split a keywords field: one per line or comma separated.
	 *
	 * @param string $value Field value.
	 * @return string[]
	 */
	public static function split_keywords( $value ) {
		return self::clean_keywords( preg_split( '/[\r\n,]+/', (string) $value ) );
	}

	/**
	 * Trim, de-duplicate (ignoring case) and cap a keyword list.
	 *
	 * @param array $list Keywords.
	 * @return string[]
	 */
	public static function clean_keywords( $list ) {
		$out = array();

		foreach ( (array) $list as $keyword ) {
			$keyword = Internal_Link_Suggestions_Text::cut( Internal_Link_Suggestions_Text::plain( is_scalar( $keyword ) ? (string) $keyword : '' ), 100 );
			$lower   = function_exists( 'mb_strtolower' ) ? mb_strtolower( $keyword, 'UTF-8' ) : strtolower( $keyword );

			if ( '' !== $keyword && ! isset( $out[ $lower ] ) ) {
				$out[ $lower ] = $keyword;
			}
		}

		return array_slice( array_values( $out ), 0, 10 );
	}

	// Links ------------------------------------------------------------------------

	/**
	 * The internal links in some HTML, by the post they point to.
	 *
	 * @param string $html      HTML.
	 * @param int    $source_id Post the HTML belongs to (links to itself are skipped).
	 * @return array<int,string> Linked post ID => first anchor text.
	 */
	public function extract_links( $html, $source_id = 0 ) {
		$links  = array();
		$open   = null;
		$anchor = '';

		$close = function () use ( &$open, &$anchor, &$links ) {
			if ( $open && ! isset( $links[ $open ] ) ) {
				$links[ $open ] = Internal_Link_Suggestions_Text::collapse( $anchor );
			}
			$open = null;
		};

		Internal_Link_Suggestions_Text::walk(
			$html,
			function ( $raw, $start, $linkable, $chunk, $markup ) use ( &$open, &$anchor ) {
				if ( null !== $open && ! $markup ) {
					$anchor .= Internal_Link_Suggestions_Text::decode( $raw );
				}
			},
			function ( $raw, $tag ) use ( &$open, &$anchor, $close, $source_id ) {
				if ( 'a' !== $tag['name'] ) {
					return;
				}

				$close();

				if ( ! $tag['close'] ) {
					$href   = Internal_Link_Suggestions_Text::attr( $raw, 'href' );
					$target = null === $href ? 0 : $this->resolve( $href );
					$open   = $target && $target !== (int) $source_id ? $target : 0;
					$anchor = '';
				}
			}
		);

		$close();

		return $links;
	}

	/**
	 * The included, published post a link points to.
	 *
	 * Reads the href the way browsers do (tabs and newlines dropped,
	 * backslashes as slashes), accepts absolute, protocol-relative and
	 * relative links to this site, ?p= and ?page_id= links, and old slugs
	 * WordPress still redirects.
	 *
	 * @param string $href Raw href attribute value.
	 * @return int Post ID, or 0.
	 */
	public function resolve( $href ) {
		$href = html_entity_decode( (string) $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$href = trim( str_replace( array( "\t", "\n", "\r" ), '', $href ) );
		$href = str_replace( '\\', '/', $href );

		if ( '' === $href || '#' === $href[0] || preg_match( '/^(?:mailto|tel|sms|javascript|data|ftp|file):/i', $href ) ) {
			return 0;
		}

		if ( isset( $this->resolved[ $href ] ) ) {
			return $this->resolved[ $href ];
		}

		$home = wp_parse_url( home_url( '/' ) );
		$base = ( isset( $home['scheme'] ) ? $home['scheme'] : 'https' ) . '://' . ( isset( $home['host'] ) ? $home['host'] : '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		$url  = $href;

		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( isset( $home['scheme'] ) ? $home['scheme'] : 'https' ) . ':' . $url;
		} elseif ( '/' === $url[0] ) {
			$url = $base . $url;
		} elseif ( '?' === $url[0] ) {
			$url = home_url( '/' ) . $url;
		} elseif ( ! preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $url ) ) {
			$url = trailingslashit( home_url() ) . $url;
		}

		$this->resolved[ $href ] = $this->resolve_url( $url );

		return $this->resolved[ $href ];
	}

	/**
	 * Resolve an absolute URL (see resolve()).
	 *
	 * @param string $url URL.
	 * @return int Post ID, or 0.
	 */
	private function resolve_url( $url ) {
		$parts = wp_parse_url( $url );

		if ( ! $parts || empty( $parts['host'] ) || ! in_array( strtolower( isset( $parts['scheme'] ) ? $parts['scheme'] : '' ), array( 'http', 'https' ), true ) ) {
			return 0;
		}

		if ( ! in_array( self::bare_host( $parts['host'] ), self::own_hosts(), true ) ) {
			return 0;
		}

		$post_id = 0;

		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
			foreach ( array( 'p', 'page_id', 'post' ) as $key ) {
				if ( ! empty( $query[ $key ] ) && is_scalar( $query[ $key ] ) && ctype_digit( (string) $query[ $key ] ) ) {
					$post_id = (int) $query[ $key ];
					break;
				}
			}
		}

		$path = self::normalize_path( isset( $parts['path'] ) ? $parts['path'] : '/' );

		if ( ! $post_id ) {
			$post_id = Internal_Link_Suggestions_DB::find_by_path( $path );
		}

		if ( ! $post_id ) {
			$post_id = url_to_postid( $url );
		}

		if ( ! $post_id ) {
			$post_id = $this->old_slug( $path );
		}

		if ( ! $post_id ) {
			return 0;
		}

		$type = get_post_type( $post_id );

		return $type && in_array( $type, $this->plugin->post_types(), true ) ? (int) $post_id : 0;
	}

	/**
	 * A post that used to have this URL's last path segment as its slug.
	 *
	 * @param string $path Normalized path.
	 * @return int
	 */
	private function old_slug( $path ) {
		global $wpdb;

		$slug = sanitize_title( (string) substr( $path, strrpos( $path, '/' ) + 1 ) );

		if ( '' === $slug ) {
			return 0;
		}

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_old_slug' AND meta_value = %s ORDER BY meta_id DESC LIMIT 1", $slug ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * This site's host names, without "www.".
	 *
	 * @return string[]
	 */
	private static function own_hosts() {
		static $hosts = null;

		if ( null === $hosts ) {
			$hosts = array();
			foreach ( array( home_url(), site_url() ) as $url ) {
				$host = wp_parse_url( $url, PHP_URL_HOST );
				if ( $host ) {
					$hosts[] = self::bare_host( $host );
				}
			}
			$hosts = array_values( array_unique( $hosts ) );
		}

		return $hosts;
	}

	/**
	 * A host lowercased, without "www." or a trailing dot.
	 *
	 * @param string $host Host.
	 * @return string
	 */
	private static function bare_host( $host ) {
		return (string) preg_replace( '/^www\./', '', rtrim( strtolower( (string) $host ), '.' ) );
	}

	/**
	 * A URL path as it's compared: decoded, lowercase, single slashes, no
	 * trailing slash ("/" for the home page).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function normalize_path( $path ) {
		$path = rawurldecode( (string) $path );
		$path = function_exists( 'mb_strtolower' ) ? mb_strtolower( $path, 'UTF-8' ) : strtolower( $path );
		$path = (string) preg_replace( '#/+#', '/', $path );

		return '/' . trim( $path, '/' );
	}

	/**
	 * A post's permalink path, or '' when its permalink isn't a pretty one
	 * (plain ?p= links are matched by ID instead).
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function permalink_path( WP_Post $post ) {
		$parts = wp_parse_url( (string) get_permalink( $post ) );

		if ( ! $parts || ! empty( $parts['query'] ) ) {
			return '';
		}

		return self::normalize_path( isset( $parts['path'] ) ? $parts['path'] : '/' );
	}

	// Full scan --------------------------------------------------------------------

	/**
	 * The scan's progress.
	 *
	 * @return array{status:string,last_id:int,done:int,total:int,started:int,finished:int}
	 */
	public static function get_state() {
		$state = get_option( self::SCAN_OPTION );

		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'status'   => 'none',
				'last_id'  => 0,
				'done'     => 0,
				'total'    => 0,
				'started'  => 0,
				'finished' => 0,
			)
		);
	}

	/**
	 * Start (or restart) a full scan in the background.
	 *
	 * @return void
	 */
	public static function start_scan() {
		update_option(
			self::SCAN_OPTION,
			array(
				'status'   => 'running',
				'last_id'  => 0,
				'done'     => 0,
				'total'    => self::count_indexable(),
				'started'  => time(),
				'finished' => 0,
			),
			false
		);

		delete_option( self::LOCK_OPTION );
		wp_clear_scheduled_hook( self::SCAN_HOOK );
		wp_schedule_single_event( time(), self::SCAN_HOOK );
	}

	/**
	 * Published posts of the included types.
	 *
	 * @return int
	 */
	private static function count_indexable() {
		global $wpdb;

		$types = internal_link_suggestions_plugin()->post_types();

		if ( ! $types ) {
			return 0;
		}

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')', $types ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * WP-Cron: run a batch, and queue the next one while there's more.
	 *
	 * @return void
	 */
	public function cron_batch() {
		$this->run_batch( 20 );
	}

	/**
	 * Index the next posts of a running scan for up to $budget seconds.
	 *
	 * @param int $budget Seconds.
	 * @return array Scan state afterwards.
	 */
	public function run_batch( $budget ) {
		global $wpdb;

		$state = self::get_state();

		if ( 'running' !== $state['status'] || ! self::lock() ) {
			return $state;
		}

		$types    = $this->plugin->post_types();
		$deadline = microtime( true ) + max( 1, (int) $budget );
		$done     = false;

		while ( microtime( true ) < $deadline ) {
			$ids = $types ? $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_status = 'publish' AND post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') ORDER BY ID ASC LIMIT %d', array_merge( array( (int) $state['last_id'] ), $types, array( self::BATCH ) ) ) ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery

			if ( ! $ids ) {
				$done = true;
				break;
			}

			foreach ( $ids as $post_id ) {
				$this->index_post( (int) $post_id );
				$state['last_id'] = (int) $post_id;
				$state['done']    = min( (int) $state['total'], (int) $state['done'] + 1 );

				if ( microtime( true ) >= $deadline ) {
					break;
				}
			}

			// Long scans: let the object cache go between batches.
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		}

		if ( $done ) {
			Internal_Link_Suggestions_DB::finish_scan( (int) $state['started'] );
			$state['status']   = 'done';
			$state['done']     = (int) $state['total'];
			$state['finished'] = time();
		}

		update_option( self::SCAN_OPTION, $state, false );
		delete_option( self::LOCK_OPTION );

		if ( ! $done && ! wp_next_scheduled( self::SCAN_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::SCAN_HOOK );
		}

		return $state;
	}

	/**
	 * Take the scan lock.
	 *
	 * @return bool False when another batch holds it.
	 */
	private static function lock() {
		$now = time();

		if ( add_option( self::LOCK_OPTION, $now + self::LOCK_TTL, '', 'no' ) ) {
			return true;
		}

		if ( (int) get_option( self::LOCK_OPTION ) < $now ) {
			update_option( self::LOCK_OPTION, $now + self::LOCK_TTL, false );
			return true;
		}

		return false;
	}
}
