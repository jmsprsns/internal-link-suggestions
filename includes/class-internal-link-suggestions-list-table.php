<?php
/**
 * The report tables: orphaned content, and every post's link counts.
 *
 * @package Internal_Link_Suggestions
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Report list table.
 */
final class Internal_Link_Suggestions_List_Table extends WP_List_Table {

	/**
	 * Screen option for rows per page.
	 */
	const PER_PAGE_OPTION = 'internal_link_suggestions_per_page';

	/**
	 * Views on the Link Report tab.
	 */
	const VIEWS = array( 'all', 'orphans', 'no_outbound', 'excluded' );

	/**
	 * "orphans" (the Orphaned Content tab) or "report" (the Link Report tab).
	 *
	 * @var string
	 */
	private $mode;

	/**
	 * Filters: view, type, search.
	 *
	 * @var array
	 */
	private $filters;

	/**
	 * Report totals, for the view counts.
	 *
	 * @var array
	 */
	private $totals;

	/**
	 * Constructor.
	 *
	 * @param string $mode    orphans or report.
	 * @param array  $filters view, type, search.
	 * @param array  $totals  See Internal_Link_Suggestions_DB::totals().
	 */
	public function __construct( $mode, array $filters, array $totals ) {
		$this->mode    = 'orphans' === $mode ? 'orphans' : 'report';
		$this->filters = $filters;
		$this->totals  = $totals;

		parent::__construct(
			array(
				'singular' => 'ils-post',
				'plural'   => 'ils-posts',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns. Keys are prefixed so core admin CSS for .column-posts and the
	 * like never applies.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		if ( 'orphans' === $this->mode ) {
			return array(
				'title'         => __( 'Orphaned post', 'internal-link-suggestions' ),
				'ils_published' => __( 'Published', 'internal-link-suggestions' ),
				'ils_words'     => __( 'Words', 'internal-link-suggestions' ),
				'ils_outbound'  => __( 'Links out', 'internal-link-suggestions' ),
				'ils_find'      => '<span class="screen-reader-text">' . esc_html__( 'Find links', 'internal-link-suggestions' ) . '</span>',
			);
		}

		return array(
			'title'         => __( 'Post', 'internal-link-suggestions' ),
			'ils_published' => __( 'Published', 'internal-link-suggestions' ),
			'ils_words'     => __( 'Words', 'internal-link-suggestions' ),
			'ils_inbound'   => __( 'Links in', 'internal-link-suggestions' ),
			'ils_outbound'  => __( 'Links out', 'internal-link-suggestions' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'title'         => array( 'title', false ),
			'ils_published' => array( 'published', true ),
			'ils_words'     => array( 'words', true ),
			'ils_inbound'   => array( 'inbound', false ),
			'ils_outbound'  => array( 'outbound', false ),
		);
	}

	/**
	 * The column holding row actions.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'title';
	}

	/**
	 * Load the rows.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( self::PER_PAGE_OPTION, 20 );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Sorting only.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : ( 'orphans' === $this->mode ? 'published' : 'inbound' );
		$order   = isset( $_GET['order'] ) && 'asc' === $_GET['order'] ? 'asc' : 'desc';
		// phpcs:enable

		$result = Internal_Link_Suggestions_DB::report(
			array(
				'view'          => 'orphans' === $this->mode ? 'orphans' : $this->filters['view'],
				'type'          => $this->filters['type'],
				'search'        => $this->filters['search'],
				'orderby'       => $orderby,
				'order'         => $order,
				'per_page'      => $per_page,
				'page'          => $this->get_pagenum(),
				'always_linked' => Internal_Link_Suggestions_Plugin::always_linked(),
			)
		);

		$this->items = $result['rows'];

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'title' );
	}

	/**
	 * Views: all, orphaned, no links out, not link targets.
	 *
	 * @return array
	 */
	protected function get_views() {
		if ( 'report' !== $this->mode ) {
			return array();
		}

		$labels = array(
			/* translators: %s: count. */
			'all'         => _n_noop( 'All <span class="count">(%s)</span>', 'All <span class="count">(%s)</span>', 'internal-link-suggestions' ),
			/* translators: %s: count. */
			'orphans'     => _n_noop( 'Orphaned <span class="count">(%s)</span>', 'Orphaned <span class="count">(%s)</span>', 'internal-link-suggestions' ),
			/* translators: %s: count. */
			'no_outbound' => _n_noop( 'No links out <span class="count">(%s)</span>', 'No links out <span class="count">(%s)</span>', 'internal-link-suggestions' ),
			/* translators: %s: count. */
			'excluded'    => _n_noop( 'Not link targets <span class="count">(%s)</span>', 'Not link targets <span class="count">(%s)</span>', 'internal-link-suggestions' ),
		);
		$counts = array(
			'all'         => $this->totals['posts'],
			'orphans'     => $this->totals['orphans'],
			'no_outbound' => $this->totals['no_outbound'],
			'excluded'    => $this->totals['excluded'],
		);
		$views  = array();

		foreach ( $labels as $view => $label ) {
			if ( 'excluded' === $view && ! $counts[ $view ] && 'excluded' !== $this->filters['view'] ) {
				continue;
			}

			$url = Internal_Link_Suggestions_Admin::tab_url(
				'report',
				array_filter(
					array(
						'view'     => 'all' === $view ? '' : $view,
						'ils_type' => $this->filters['type'],
					)
				)
			);

			$views[ $view ] = sprintf(
				'<a href="%1$s"%2$s>%3$s</a>',
				esc_url( $url ),
				$view === $this->filters['view'] ? ' class="current" aria-current="page"' : '',
				sprintf( translate_nooped_plural( $label, $counts[ $view ], 'internal-link-suggestions' ), esc_html( number_format_i18n( $counts[ $view ] ) ) )
			);
		}

		return $views;
	}

	/**
	 * The post type filter.
	 *
	 * @param string $which top or bottom.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$types = internal_link_suggestions_plugin()->post_types();

		if ( count( $types ) < 2 ) {
			return;
		}
		?>
		<div class="alignleft actions">
			<label for="ils-type" class="screen-reader-text"><?php esc_html_e( 'Filter by type', 'internal-link-suggestions' ); ?></label>
			<select name="ils_type" id="ils-type">
				<option value=""><?php esc_html_e( 'All types', 'internal-link-suggestions' ); ?></option>
				<?php foreach ( $types as $type ) : ?>
					<?php $object = get_post_type_object( $type ); ?>
					<option value="<?php echo esc_attr( $type ); ?>"<?php selected( $this->filters['type'], $type ); ?>><?php echo esc_html( $object ? $object->labels->name : $type ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'internal-link-suggestions' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * Message when there are no rows.
	 *
	 * @return void
	 */
	public function no_items() {
		if ( 'orphans' === $this->mode && '' === $this->filters['search'] && '' === $this->filters['type'] ) {
			echo '<span class="ils-none">' . Internal_Link_Suggestions_Icons::get( 'circle-check', 16 ) . esc_html__( 'No orphaned content: every post has at least one link from another post.', 'internal-link-suggestions' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			return;
		}

		esc_html_e( 'No posts match.', 'internal-link-suggestions' );
	}

	/**
	 * Row markup: the post ID lets the page's script add rows below it.
	 *
	 * @param array $item Row.
	 * @return void
	 */
	public function single_row( $item ) {
		printf( '<tr class="ils-row" data-post="%d">', (int) $item['post_id'] );
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * Title, type and row actions.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_title( $item ) {
		$post_id = (int) $item['post_id'];
		$edit    = current_user_can( 'edit_post', $post_id ) ? get_edit_post_link( $post_id ) : '';
		$type    = get_post_type_object( $item['post_type'] );
		$title   = esc_html( $item['title'] );

		$out  = '<strong class="ils-post-title">' . ( $edit ? '<a href="' . esc_url( $edit ) . '">' . $title . '</a>' : $title ) . '</strong>';
		$out .= '<span class="ils-type">' . esc_html( $type ? $type->labels->singular_name : $item['post_type'] ) . '</span>';

		if ( (int) $item['protected'] ) {
			$out .= '<span class="ils-type is-muted">' . esc_html__( 'Password protected', 'internal-link-suggestions' ) . '</span>';
		}

		$actions = array();
		if ( $edit ) {
			$actions['ils_edit'] = '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'internal-link-suggestions' ) . '</a>';
		}
		$actions['ils_view'] = '<a href="' . esc_url( get_permalink( $post_id ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'internal-link-suggestions' ) . '</a>';

		if ( current_user_can( 'edit_post', $post_id ) ) {
			$excluded = (int) $item['excluded'];

			$actions['ils_exclude'] = sprintf(
				'<button type="button" class="button-link" data-ils-exclude="%1$d" data-excluded="%2$d">%3$s</button>',
				$post_id,
				$excluded,
				$excluded ? esc_html__( 'Make it a link target again', 'internal-link-suggestions' ) : esc_html__( 'Not a link target', 'internal-link-suggestions' )
			);
		}

		return $out . $this->row_actions( $actions );
	}

	/**
	 * Published date.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_ils_published( $item ) {
		return Internal_Link_Suggestions_Admin::date( (int) $item['published'] );
	}

	/**
	 * Word count.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_ils_words( $item ) {
		return esc_html( number_format_i18n( (int) $item['words'] ) );
	}

	/**
	 * Links in: a button that lists them.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_ils_inbound( $item ) {
		return $this->count_button( $item, (int) $item['inbound'], 'in' );
	}

	/**
	 * Links out: a button that lists them.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_ils_outbound( $item ) {
		return $this->count_button( $item, (int) $item['outbound'], 'out' );
	}

	/**
	 * A link count that opens the list of links.
	 *
	 * @param array  $item      Row.
	 * @param int    $count     Count.
	 * @param string $direction in or out.
	 * @return string
	 */
	private function count_button( $item, $count, $direction ) {
		$class = 'ils-count';

		if ( 0 === $count ) {
			$orphan = 'in' === $direction && ! (int) $item['excluded'] && ! in_array( (int) $item['post_id'], Internal_Link_Suggestions_Plugin::always_linked(), true );
			return '<span class="' . $class . ' is-zero' . ( $orphan ? ' is-orphan' : '' ) . '">0</span>';
		}

		return sprintf(
			'<button type="button" class="%1$s" data-ils-links="%2$d" data-direction="%3$s" aria-expanded="false">%4$s%5$s</button>',
			esc_attr( $class ),
			(int) $item['post_id'],
			esc_attr( $direction ),
			Internal_Link_Suggestions_Icons::get( 'in' === $direction ? 'arrow-down-left' : 'arrow-up-right', 14 ),
			esc_html( number_format_i18n( $count ) )
		);
	}

	/**
	 * The "Find links" button on the orphan report.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_ils_find( $item ) {
		if ( (int) $item['protected'] ) {
			return '';
		}

		return sprintf(
			'<button type="button" class="button ils-find" data-ils-find="%1$d" aria-expanded="false">%2$s%3$s</button>',
			(int) $item['post_id'],
			Internal_Link_Suggestions_Icons::get( 'sparkles', 15 ),
			esc_html__( 'Find links', 'internal-link-suggestions' )
		);
	}

	/**
	 * Fallback column output.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return '';
	}
}
