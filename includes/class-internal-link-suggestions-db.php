<?php
/**
 * The index tables: one row per published post, its most telling words,
 * the internal links between posts, and a log of links the plugin added.
 *
 * @package Internal_Link_Suggestions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database access. Every query with outside values goes through prepare().
 */
final class Internal_Link_Suggestions_DB {

	/**
	 * Bump when the table definitions change; dbDelta runs again on the
	 * next load.
	 */
	const VERSION = '1';

	/**
	 * Option holding the installed table version.
	 */
	const VERSION_OPTION = 'internal_link_suggestions_db_version';

	/**
	 * Most words kept per post for finding related posts.
	 */
	const TERMS_PER_POST = 60;

	/**
	 * Table names.
	 *
	 * @param string $name posts, links, terms or log.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;

		return $wpdb->prefix . 'ils_' . $name;
	}

	/**
	 * Create or update the tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$posts   = self::table( 'posts' );
		$links   = self::table( 'links' );
		$terms   = self::table( 'terms' );
		$log     = self::table( 'log' );

		// Words are stored as bytes: an exact match, whatever the collation
		// thinks of "café" and "cafe".
		dbDelta(
			"CREATE TABLE {$posts} (
				post_id bigint(20) unsigned NOT NULL,
				post_type varchar(20) NOT NULL DEFAULT '',
				title text NOT NULL,
				path text NOT NULL,
				path_hash char(32) NOT NULL DEFAULT '',
				keywords text NOT NULL,
				published int(10) unsigned NOT NULL DEFAULT 0,
				words int(10) unsigned NOT NULL DEFAULT 0,
				inbound int(10) unsigned NOT NULL DEFAULT 0,
				outbound int(10) unsigned NOT NULL DEFAULT 0,
				excluded tinyint(1) unsigned NOT NULL DEFAULT 0,
				protected tinyint(1) unsigned NOT NULL DEFAULT 0,
				scanned int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (post_id),
				KEY path_hash (path_hash),
				KEY type_inbound (post_type,inbound)
			) {$collate};
			CREATE TABLE {$links} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source_id bigint(20) unsigned NOT NULL,
				target_id bigint(20) unsigned NOT NULL,
				anchor varchar(255) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY source_id (source_id),
				KEY target_id (target_id)
			) {$collate};
			CREATE TABLE {$terms} (
				term varbinary(255) NOT NULL,
				post_id bigint(20) unsigned NOT NULL,
				weight smallint(5) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (term,post_id),
				KEY post_id (post_id)
			) {$collate};
			CREATE TABLE {$log} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created int(10) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				source_id bigint(20) unsigned NOT NULL,
				target_id bigint(20) unsigned NOT NULL,
				anchor varchar(255) NOT NULL DEFAULT '',
				url text NOT NULL,
				original longtext NOT NULL,
				inserted longtext NOT NULL,
				position int(10) unsigned NOT NULL DEFAULT 0,
				content_hash char(32) NOT NULL DEFAULT '',
				status varchar(10) NOT NULL DEFAULT 'added',
				PRIMARY KEY  (id),
				KEY source_id (source_id),
				KEY created (created)
			) {$collate};"
		);

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Create the tables if they're missing or out of date (after an update,
	 * or on a site where the plugin was never activated, like a new site in
	 * a network).
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( self::VERSION !== get_option( self::VERSION_OPTION ) ) {
			self::install();
		}
	}

	/**
	 * Drop every table (uninstall only).
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;

		foreach ( array( 'posts', 'links', 'terms', 'log' ) as $name ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Fixed table names.
		}

		delete_option( self::VERSION_OPTION );
	}

	/**
	 * Placeholders for a list of values.
	 *
	 * @param array  $values Values.
	 * @param string $type   %d or %s.
	 * @return string
	 */
	private static function in( array $values, $type = '%d' ) {
		return implode( ',', array_fill( 0, count( $values ), $type ) );
	}

	// Posts --------------------------------------------------------------------

	/**
	 * Save a post's index row, words and links in one go.
	 *
	 * @param array             $row   Columns for the posts table.
	 * @param array<string,int> $terms Word key => weight.
	 * @param array<int,string> $links Linked post ID => first anchor text.
	 * @return int[] Posts whose inbound count may have changed.
	 */
	public static function save_post( array $row, array $terms, array $links ) {
		global $wpdb;

		$post_id = (int) $row['post_id'];

		$wpdb->replace( self::table( 'posts' ), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$wpdb->delete( self::table( 'terms' ), array( 'post_id' => $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( $terms ) {
			$values = array();
			$sql    = array();
			foreach ( $terms as $term => $weight ) {
				$sql[]    = '(%s,%d,%d)';
				$values[] = (string) $term;
				$values[] = $post_id;
				$values[] = max( 1, min( 65535, (int) $weight ) );
			}

			// IGNORE: two words can still collide once truncated to 255 bytes.
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table( 'terms' ) . ' (term,post_id,weight) VALUES ' . implode( ',', $sql ), $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery
		}

		return self::replace_links( $post_id, $links );
	}

	/**
	 * Replace a post's outbound internal links.
	 *
	 * @param int               $source_id Post the links are in.
	 * @param array<int,string> $links     Linked post ID => anchor text.
	 * @return int[] Posts whose inbound count may have changed.
	 */
	public static function replace_links( $source_id, array $links ) {
		global $wpdb;

		$table = self::table( 'links' );
		$old   = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT target_id FROM {$table} WHERE source_id = %d", $source_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		$wpdb->delete( $table, array( 'source_id' => $source_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( $links ) {
			$values = array();
			$sql    = array();
			foreach ( $links as $target => $anchor ) {
				$sql[]    = '(%d,%d,%s)';
				$values[] = (int) $source_id;
				$values[] = (int) $target;
				$values[] = Internal_Link_Suggestions_Text::cut( (string) $anchor, 255 );
			}
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (source_id,target_id,anchor) VALUES " . implode( ',', $sql ), $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery
		}

		return array_values( array_unique( array_merge( $old, array_map( 'intval', array_keys( $links ) ) ) ) );
	}

	/**
	 * Take a post out of the index (unpublished, trashed, deleted, or its
	 * type is no longer included). Links pointing at it stay, so they count
	 * again if it comes back.
	 *
	 * @param int $post_id Post ID.
	 * @return int[] Posts whose inbound count may have changed.
	 */
	public static function remove_post( $post_id ) {
		global $wpdb;

		$changed = self::replace_links( $post_id, array() );

		$wpdb->delete( self::table( 'posts' ), array( 'post_id' => $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::table( 'terms' ), array( 'post_id' => $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $changed;
	}

	/**
	 * Recount inbound links (distinct linking posts that are in the index)
	 * for some posts.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return void
	 */
	public static function recount( array $post_ids ) {
		global $wpdb;

		$post_ids = array_values( array_filter( array_map( 'intval', $post_ids ) ) );

		if ( ! $post_ids ) {
			return;
		}

		$posts = self::table( 'posts' );
		$links = self::table( 'links' );

		foreach ( array_chunk( $post_ids, 200 ) as $ids ) {
			$in = self::in( $ids );

			// The grouped derived table is always materialized, which is what
			// lets MySQL read the posts table while updating it.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$posts} p LEFT JOIN (
						SELECT l.target_id, COUNT(DISTINCT l.source_id) AS c FROM {$links} l
						INNER JOIN {$posts} s ON s.post_id = l.source_id
						WHERE l.target_id IN ({$in}) AND l.source_id <> l.target_id
						GROUP BY l.target_id
					) x ON x.target_id = p.post_id
					SET p.inbound = COALESCE(x.c, 0)
					WHERE p.post_id IN ({$in})",
					array_merge( $ids, $ids )
				)
			);
			// phpcs:enable
		}
	}

	/**
	 * Recount every post's inbound links and clear links from posts that
	 * are no longer indexed. Runs at the end of a full scan.
	 *
	 * @param int $started When the scan started: rows not touched since were
	 *                     for posts that are gone.
	 * @return void
	 */
	public static function finish_scan( $started ) {
		global $wpdb;

		$posts = self::table( 'posts' );
		$links = self::table( 'links' );
		$terms = self::table( 'terms' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$stale = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$posts} WHERE scanned < %d", $started ) );

		foreach ( $stale as $post_id ) {
			self::remove_post( (int) $post_id );
		}

		$wpdb->query( "DELETE l FROM {$links} l LEFT JOIN {$posts} p ON p.post_id = l.source_id WHERE p.post_id IS NULL" );
		$wpdb->query( "DELETE t FROM {$terms} t LEFT JOIN {$posts} p ON p.post_id = t.post_id WHERE p.post_id IS NULL" );

		$wpdb->query(
			"UPDATE {$posts} p LEFT JOIN (
				SELECT l.target_id, COUNT(DISTINCT l.source_id) AS c FROM {$links} l
				WHERE l.source_id <> l.target_id GROUP BY l.target_id
			) x ON x.target_id = p.post_id SET p.inbound = COALESCE(x.c, 0)"
		);
		// phpcs:enable
	}

	/**
	 * Index rows by ID.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array<int,array> Rows keyed by post ID.
	 */
	public static function get_posts( array $post_ids ) {
		global $wpdb;

		$post_ids = array_values( array_filter( array_map( 'intval', $post_ids ) ) );

		if ( ! $post_ids ) {
			return array();
		}

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'posts' ) . ' WHERE post_id IN (' . self::in( $post_ids ) . ')', $post_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$out  = array();

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['post_id'] ] = $row;
		}

		return $out;
	}

	/**
	 * One index row.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function get_post( $post_id ) {
		$rows = self::get_posts( array( $post_id ) );

		return isset( $rows[ (int) $post_id ] ) ? $rows[ (int) $post_id ] : null;
	}

	/**
	 * The indexed post at a permalink path.
	 *
	 * @param string $path Normalized path (see Indexer::normalize_path()).
	 * @return int 0 if none.
	 */
	public static function find_by_path( $path ) {
		global $wpdb;

		if ( '' === $path ) {
			return 0;
		}

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT post_id FROM ' . self::table( 'posts' ) . ' WHERE path_hash = %s ORDER BY post_id LIMIT 1', md5( $path ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Set or clear a post's "not a link target" flag in the index.
	 *
	 * @param int  $post_id  Post ID.
	 * @param bool $excluded Flag.
	 * @return void
	 */
	public static function set_excluded( $post_id, $excluded ) {
		global $wpdb;

		$wpdb->update( self::table( 'posts' ), array( 'excluded' => $excluded ? 1 : 0 ), array( 'post_id' => (int) $post_id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	// Words --------------------------------------------------------------------

	/**
	 * Number of indexed posts.
	 *
	 * @return int
	 */
	public static function count_posts() {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table( 'posts' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * How many indexed posts each word appears in (among their top words).
	 *
	 * @param string[] $terms Word keys.
	 * @return array<string,int>
	 */
	public static function document_frequency( array $terms ) {
		global $wpdb;

		$terms = array_values( array_unique( array_map( 'strval', $terms ) ) );
		$out   = array();

		foreach ( array_chunk( $terms, 500 ) as $chunk ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT term, COUNT(*) AS df FROM ' . self::table( 'terms' ) . ' WHERE term IN (' . self::in( $chunk, '%s' ) . ') GROUP BY term', $chunk ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

			foreach ( (array) $rows as $row ) {
				$out[ (string) $row['term'] ] = (int) $row['df'];
			}
		}

		return $out;
	}

	/**
	 * Posts that share the most telling words with a set of weighted words.
	 *
	 * @param array<string,float> $weights Word key => weight (already
	 *                                     multiplied by how rare it is).
	 * @param int[]               $exclude Post IDs to leave out.
	 * @param string[]            $types   Post types to include.
	 * @param int                 $limit   Most posts.
	 * @param bool                $targets Only posts that can be linked to
	 *                                     (not flagged "not a link target").
	 *                                     Password-protected posts are always
	 *                                     left out.
	 * @return array<int,float> Post ID => score, best first.
	 */
	public static function related( array $weights, array $exclude, array $types, $limit, $targets = true ) {
		global $wpdb;

		$weights = array_filter(
			$weights,
			function ( $weight ) {
				return $weight > 0;
			}
		);

		if ( ! $weights || ! $types ) {
			return array();
		}

		arsort( $weights );
		$weights = array_slice( $weights, 0, 300, true );

		$case   = array();
		$values = array();
		foreach ( $weights as $term => $weight ) {
			$case[]   = 'WHEN %s THEN %f';
			$values[] = (string) $term;
			$values[] = round( (float) $weight, 4 );
		}

		$terms   = array_map( 'strval', array_keys( $weights ) );
		$exclude = array_values( array_map( 'intval', $exclude ) );
		$exclude = $exclude ? $exclude : array( 0 );

		$sql = 'SELECT t.post_id, SUM(LEAST(t.weight, 12) * (CASE t.term ' . implode( ' ', $case ) . ' ELSE 0 END)) AS score
			FROM ' . self::table( 'terms' ) . ' t
			INNER JOIN ' . self::table( 'posts' ) . ' p ON p.post_id = t.post_id
			WHERE t.term IN (' . self::in( $terms, '%s' ) . ')
			AND p.post_type IN (' . self::in( $types, '%s' ) . ')
			AND p.post_id NOT IN (' . self::in( $exclude ) . ')
			AND p.protected = 0'
			. ( $targets ? ' AND p.excluded = 0' : '' ) . '
			GROUP BY t.post_id ORDER BY score DESC LIMIT %d';

		$args = array_merge( $values, $terms, array_values( $types ), $exclude, array( max( 1, (int) $limit ) ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$out  = array();

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['post_id'] ] = (float) $row['score'];
		}

		return $out;
	}

	/**
	 * Published posts whose content contains any of some phrases.
	 *
	 * @param string[] $phrases Plain phrases.
	 * @param int[]    $exclude Post IDs to leave out.
	 * @param string[] $types   Post types.
	 * @param int      $limit   Most posts.
	 * @return int[]
	 */
	public static function containing( array $phrases, array $exclude, array $types, $limit ) {
		global $wpdb;

		$phrases = array_values( array_filter( array_map( 'strval', $phrases ) ) );

		if ( ! $phrases || ! $types ) {
			return array();
		}

		$likes = array();
		$args  = array();
		foreach ( $phrases as $phrase ) {
			$likes[] = 'p.post_content LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $phrase ) . '%';
		}

		$exclude = array_values( array_map( 'intval', $exclude ) );
		$exclude = $exclude ? $exclude : array( 0 );

		$sql = "SELECT p.ID FROM {$wpdb->posts} p
			INNER JOIN " . self::table( 'posts' ) . " i ON i.post_id = p.ID
			WHERE p.post_status = 'publish' AND p.post_password = ''
			AND p.post_type IN (" . self::in( $types, '%s' ) . ')
			AND p.ID NOT IN (' . self::in( $exclude ) . ')
			AND (' . implode( ' OR ', $likes ) . ')
			ORDER BY p.post_date DESC LIMIT %d';

		$args = array_merge( array_values( $types ), $exclude, $args, array( max( 1, (int) $limit ) ) );

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	// Links --------------------------------------------------------------------

	/**
	 * Posts that link to a post.
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	public static function sources_of( $post_id ) {
		global $wpdb;

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT source_id FROM ' . self::table( 'links' ) . ' WHERE target_id = %d', $post_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Links into or out of a post, with the other post's title, for the
	 * report's expanded rows.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $direction "in" or "out".
	 * @param int    $limit     Most links.
	 * @return array<int,array{post_id:int,anchor:string,title:string,post_type:string}>
	 */
	public static function link_list( $post_id, $direction, $limit = 100 ) {
		global $wpdb;

		$links = self::table( 'links' );
		$posts = self::table( 'posts' );

		if ( 'in' === $direction ) {
			$sql = "SELECT l.source_id AS post_id, l.anchor, p.title, p.post_type FROM {$links} l INNER JOIN {$posts} p ON p.post_id = l.source_id WHERE l.target_id = %d AND l.source_id <> %d ORDER BY p.title LIMIT %d";
		} else {
			$sql = "SELECT l.target_id AS post_id, l.anchor, p.title, p.post_type FROM {$links} l INNER JOIN {$posts} p ON p.post_id = l.target_id WHERE l.source_id = %d AND l.target_id <> %d ORDER BY p.title LIMIT %d";
		}

		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $post_id, $post_id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Totals for the report's summary.
	 *
	 * @param int[] $always_linked Posts never counted as orphaned (front page...).
	 * @return array{posts:int,links:int,orphans:int,no_outbound:int,excluded:int}
	 */
	public static function totals( array $always_linked ) {
		global $wpdb;

		$posts  = self::table( 'posts' );
		$links  = self::table( 'links' );
		$skip   = $always_linked ? array_values( array_map( 'intval', $always_linked ) ) : array( 0 );
		$in_sql = self::in( $skip );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS posts,
					SUM(CASE WHEN inbound = 0 AND excluded = 0 AND post_id NOT IN ({$in_sql}) THEN 1 ELSE 0 END) AS orphans,
					SUM(CASE WHEN outbound = 0 THEN 1 ELSE 0 END) AS no_outbound,
					SUM(CASE WHEN excluded = 1 THEN 1 ELSE 0 END) AS excluded
				FROM {$posts}",
				$skip
			),
			ARRAY_A
		);

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$links} l INNER JOIN {$posts} s ON s.post_id = l.source_id INNER JOIN {$posts} t ON t.post_id = l.target_id WHERE l.source_id <> l.target_id" );
		// phpcs:enable

		return array(
			'posts'       => (int) $row['posts'],
			'links'       => $count,
			'orphans'     => (int) $row['orphans'],
			'no_outbound' => (int) $row['no_outbound'],
			'excluded'    => (int) $row['excluded'],
		);
	}

	/**
	 * Rows for the report table.
	 *
	 * @param array $args view (all, orphans, no_outbound, excluded), type,
	 *                    search, orderby, order, per_page, page, always_linked.
	 * @return array{rows:array,total:int}
	 */
	public static function report( array $args ) {
		global $wpdb;

		$where  = array( '1=1' );
		$values = array();

		switch ( $args['view'] ) {
			case 'orphans':
				$where[] = 'inbound = 0 AND excluded = 0';
				if ( ! empty( $args['always_linked'] ) ) {
					$where[] = 'post_id NOT IN (' . self::in( $args['always_linked'] ) . ')';
					$values  = array_merge( $values, array_map( 'intval', $args['always_linked'] ) );
				}
				break;
			case 'no_outbound':
				$where[] = 'outbound = 0';
				break;
			case 'excluded':
				$where[] = 'excluded = 1';
				break;
		}

		if ( ! empty( $args['type'] ) ) {
			$where[]  = 'post_type = %s';
			$values[] = (string) $args['type'];
		}

		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'title LIKE %s';
			$values[] = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		}

		$columns = array(
			'title'     => 'title',
			'published' => 'published',
			'words'     => 'words',
			'inbound'   => 'inbound',
			'outbound'  => 'outbound',
		);
		$orderby = isset( $columns[ $args['orderby'] ] ) ? $columns[ $args['orderby'] ] : 'published';
		$order   = 'asc' === $args['order'] ? 'ASC' : 'DESC';
		$sql     = ' FROM ' . self::table( 'posts' ) . ' WHERE ' . implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$total = (int) ( $values ? $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*)' . $sql, $values ) ) : $wpdb->get_var( 'SELECT COUNT(*)' . $sql ) );

		$values[] = max( 1, (int) $args['per_page'] );
		$values[] = max( 0, ( (int) $args['page'] - 1 ) * (int) $args['per_page'] );

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT *' . $sql . " ORDER BY {$orderby} {$order}, post_id DESC LIMIT %d OFFSET %d", $values ), ARRAY_A );
		// phpcs:enable

		return array(
			'rows'  => (array) $rows,
			'total' => $total,
		);
	}

	// Log ----------------------------------------------------------------------

	/**
	 * Record a link the plugin added to a post.
	 *
	 * @param array $entry Columns.
	 * @return int Log ID.
	 */
	public static function add_log( array $entry ) {
		global $wpdb;

		$wpdb->insert( self::table( 'log' ), $entry ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return (int) $wpdb->insert_id;
	}

	/**
	 * One log entry.
	 *
	 * @param int $id Log ID.
	 * @return array|null
	 */
	public static function get_log( $id ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'log' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		return $row ? $row : null;
	}

	/**
	 * Mark a log entry undone.
	 *
	 * @param int $id Log ID.
	 * @return void
	 */
	public static function mark_undone( $id ) {
		global $wpdb;

		$wpdb->update( self::table( 'log' ), array( 'status' => 'undone' ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * A page of log entries, newest first.
	 *
	 * @param int $per_page Rows per page.
	 * @param int $page     Page number.
	 * @return array{rows:array,total:int}
	 */
	public static function log_page( $per_page, $page ) {
		global $wpdb;

		$table = self::table( 'log' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, created, user_id, source_id, target_id, anchor, url, status FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, max( 0, ( $page - 1 ) * $per_page ) ), ARRAY_A );
		// phpcs:enable

		return array(
			'rows'  => (array) $rows,
			'total' => $total,
		);
	}
}
