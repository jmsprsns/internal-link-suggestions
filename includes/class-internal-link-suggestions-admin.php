<?php
/**
 * Admin side: the Content Powered > Internal Link Suggestions page (orphaned
 * content, link report, added links, settings) and the Plugins-screen links.
 *
 * @package Internal_Link_Suggestions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Report and settings page controller.
 */
final class Internal_Link_Suggestions_Admin {

	/**
	 * Page slug, also used as the settings group.
	 */
	const PAGE = 'internal-link-suggestions';

	/**
	 * Lucide icon shown beside the menu item and page title.
	 */
	const ICON = 'waypoints';

	/**
	 * Capability for the page.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Tabs and their icons.
	 */
	const TABS = array(
		'orphans'  => 'unlink',
		'report'   => 'chart-column',
		'log'      => 'history',
		'settings' => 'settings',
	);

	/**
	 * Where Link Audit and Reports lives, for the pairing note.
	 */
	const LINK_AUDIT_URL = 'https://www.contentpowered.com/wordpress-plugins/link-audit-reports/';

	/**
	 * Plugin instance.
	 *
	 * @var Internal_Link_Suggestions_Plugin
	 */
	private $plugin;

	/**
	 * Hook suffix of the page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * The report table on the current tab.
	 *
	 * @var Internal_Link_Suggestions_List_Table|null
	 */
	private $table = null;

	/**
	 * Report totals for the current request.
	 *
	 * @var array|null
	 */
	private $totals = null;

	/**
	 * Constructor.
	 *
	 * @param Internal_Link_Suggestions_Plugin $plugin Plugin instance.
	 */
	public function __construct( Internal_Link_Suggestions_Plugin $plugin ) {
		$this->plugin = $plugin;

		Internal_Link_Suggestions_Menu::init();
		Internal_Link_Suggestions_Feedback::init();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		add_filter( 'set-screen-option', array( $this, 'save_screen_option' ), 10, 3 );
		add_filter( 'set_screen_option_' . Internal_Link_Suggestions_List_Table::PER_PAGE_OPTION, array( $this, 'save_screen_option' ), 10, 3 );

		add_filter(
			'plugin_action_links_' . plugin_basename( INTERNAL_LINK_SUGGESTIONS_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	// Helpers -------------------------------------------------------------------

	/**
	 * A bundled icon.
	 *
	 * @param string $name  Icon name.
	 * @param int    $size  Size in px.
	 * @param string $class Extra classes.
	 * @return string Inline SVG.
	 */
	public static function icon( $name, $size = 18, $class = '' ) {
		return Internal_Link_Suggestions_Icons::get( $name, $size, $class );
	}

	/**
	 * URL of a tab.
	 *
	 * @param string $tab  Tab.
	 * @param array  $args Extra query args.
	 * @return string
	 */
	public static function tab_url( $tab = 'orphans', array $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::PAGE,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * A date, with the time on hover.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	public static function date( $time ) {
		if ( ! $time ) {
			return '<span class="ils-muted">&mdash;</span>';
		}

		return sprintf(
			'<time datetime="%1$s" title="%2$s">%3$s</time>',
			esc_attr( gmdate( 'c', $time ) ),
			esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) ),
			esc_html( wp_date( get_option( 'date_format' ), $time ) )
		);
	}

	/**
	 * The current tab.
	 *
	 * @return string
	 */
	private static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'orphans'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.

		return isset( self::TABS[ $tab ] ) ? $tab : 'orphans';
	}

	/**
	 * A tab's label.
	 *
	 * @param string $tab Tab.
	 * @return string
	 */
	private static function tab_label( $tab ) {
		$labels = array(
			'orphans'  => __( 'Orphaned Content', 'internal-link-suggestions' ),
			'report'   => __( 'Link Report', 'internal-link-suggestions' ),
			'log'      => __( 'Added Links', 'internal-link-suggestions' ),
			'settings' => __( 'Settings', 'internal-link-suggestions' ),
		);

		return $labels[ $tab ];
	}

	/**
	 * The report filters from the URL.
	 *
	 * @return array{view:string,type:string,search:string}
	 */
	private function filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Filters only read.
		$view   = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'all';
		$type   = isset( $_GET['ils_type'] ) ? sanitize_key( wp_unslash( $_GET['ils_type'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable

		return array(
			'view'   => in_array( $view, Internal_Link_Suggestions_List_Table::VIEWS, true ) ? $view : 'all',
			'type'   => in_array( $type, $this->plugin->post_types(), true ) ? $type : '',
			'search' => substr( trim( $search ), 0, 100 ),
		);
	}

	/**
	 * Report totals (cached for the request).
	 *
	 * @return array
	 */
	private function totals() {
		if ( null === $this->totals ) {
			$this->totals = Internal_Link_Suggestions_DB::totals( Internal_Link_Suggestions_Plugin::always_linked() );
		}

		return $this->totals;
	}

	// Menu and assets -----------------------------------------------------------

	/**
	 * Add report and settings links to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links (Deactivate, etc.).
	 * @return array
	 */
	public function add_settings_link( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}

		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( self::tab_url() ), esc_html__( 'Reports', 'internal-link-suggestions' ) ),
			sprintf( '<a href="%s">%s</a>', esc_url( self::tab_url( 'settings' ) ), esc_html__( 'Settings', 'internal-link-suggestions' ) )
		);

		return $links;
	}

	/**
	 * Add the page under the shared Content Powered menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$icon       = self::icon( self::ICON, 17, 'internal-link-suggestions-menu-icon' );
		$menu_title = $icon . '<span class="internal-link-suggestions-menu-label">' . esc_html__( 'Internal Link Suggestions', 'internal-link-suggestions' ) . '</span>';

		$this->hook_suffix = add_submenu_page(
			Internal_Link_Suggestions_Menu::SLUG,
			__( 'Internal Link Suggestions', 'internal-link-suggestions' ),
			$menu_title,
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'load-' . $this->hook_suffix, array( $this, 'load_page' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}
	}

	/**
	 * Before the page is drawn: tidy the URL after a filter, set up the table.
	 *
	 * @return void
	 */
	public function load_page() {
		$tab = self::current_tab();

		if ( ! in_array( $tab, array( 'orphans', 'report' ), true ) ) {
			return;
		}

		// The table's form also sends a nonce and referer: keep them out of the URL.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only rebuilds the URL.
		if ( ! empty( $_GET['_wpnonce'] ) || ! empty( $_GET['_wp_http_referer'] ) || isset( $_GET['filter_action'] ) ) {
			$filters = $this->filters();
			$args    = array_filter(
				array(
					'view'     => 'all' === $filters['view'] ? '' : $filters['view'],
					'ils_type' => $filters['type'],
					's'        => $filters['search'],
					'orderby'  => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '',
					'order'    => isset( $_GET['order'] ) && 'asc' === $_GET['order'] ? 'asc' : '',
				)
			);
			wp_safe_redirect( self::tab_url( $tab, $args ) );
			exit;
		}
		// phpcs:enable

		add_screen_option(
			'per_page',
			array(
				'default' => 20,
				'option'  => Internal_Link_Suggestions_List_Table::PER_PAGE_OPTION,
			)
		);

		$this->table = new Internal_Link_Suggestions_List_Table( $tab, $this->filters(), $this->totals() );
	}

	/**
	 * Save the rows-per-page screen option.
	 *
	 * @param mixed  $status Value to save, or false.
	 * @param string $option Option name.
	 * @param mixed  $value  Submitted value.
	 * @return mixed
	 */
	public function save_screen_option( $status, $option, $value ) {
		if ( Internal_Link_Suggestions_List_Table::PER_PAGE_OPTION === $option ) {
			return max( 5, min( 200, absint( $value ) ) );
		}

		return $status;
	}

	/**
	 * Body class for the page, so its full-width header can line up.
	 *
	 * @param string $classes Classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && $this->hook_suffix && $screen->id === $this->hook_suffix ) {
			$classes .= ' ils-page';
		}

		return $classes;
	}

	/**
	 * Tiny inline style so the menu icon sits neatly beside its label, even
	 * when a long plugin name wraps onto a second line.
	 *
	 * @return void
	 */
	public function print_menu_icon_style() {
		echo '<style id="internal-link-suggestions-menu-icon-style">'
			. '#adminmenu a:has(>.internal-link-suggestions-menu-icon){display:flex;align-items:flex-start;gap:7px}'
			. '#adminmenu .internal-link-suggestions-menu-icon{flex:none;margin-top:1px;opacity:.7}'
			. '#adminmenu li.current .internal-link-suggestions-menu-icon,#adminmenu a:hover .internal-link-suggestions-menu-icon,#adminmenu a:focus .internal-link-suggestions-menu-icon{opacity:1}'
			. '</style>';
	}

	/**
	 * Load the page's styles and scripts.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		$state = Internal_Link_Suggestions_Indexer::get_state();
		$icons = array();

		foreach ( array( 'arrow-up-right', 'arrow-down-left', 'external-link', 'square-pen', 'circle-check', 'undo-2', 'loader-circle', 'info', 'tag', 'sparkles', 'link', 'plus' ) as $name ) {
			$icons[ $name ] = self::icon( $name, 16 );
		}

		wp_enqueue_style( 'internal-link-suggestions-editor', INTERNAL_LINK_SUGGESTIONS_URL . 'assets/editor.css', array(), INTERNAL_LINK_SUGGESTIONS_VERSION );
		wp_enqueue_style( 'internal-link-suggestions-admin', INTERNAL_LINK_SUGGESTIONS_URL . 'assets/admin.css', array( 'internal-link-suggestions-editor' ), INTERNAL_LINK_SUGGESTIONS_VERSION );
		wp_enqueue_script( 'internal-link-suggestions-common', INTERNAL_LINK_SUGGESTIONS_URL . 'assets/common.js', array( 'wp-api-fetch' ), INTERNAL_LINK_SUGGESTIONS_VERSION, true );
		wp_enqueue_script( 'internal-link-suggestions-admin', INTERNAL_LINK_SUGGESTIONS_URL . 'assets/admin.js', array( 'internal-link-suggestions-common' ), INTERNAL_LINK_SUGGESTIONS_VERSION, true );

		$data = array(
			'namespace' => Internal_Link_Suggestions_REST::NS,
			'scanning'  => 'running' === $state['status'],
			'icons'     => $icons,
			'i18n'      => array(
				'loading'      => __( 'Looking through your posts…', 'internal-link-suggestions' ),
				'add'          => __( 'Add link', 'internal-link-suggestions' ),
				'adding'       => __( 'Adding…', 'internal-link-suggestions' ),
				'added'        => __( 'Link added', 'internal-link-suggestions' ),
				'undo'         => __( 'Undo', 'internal-link-suggestions' ),
				'undone'       => __( 'Link removed', 'internal-link-suggestions' ),
				'edit'         => __( 'Edit', 'internal-link-suggestions' ),
				'view'         => __( 'View', 'internal-link-suggestions' ),
				'open'         => __( 'Open post', 'internal-link-suggestions' ),
				'strong'       => __( 'Strong match', 'internal-link-suggestions' ),
				'good'         => __( 'Good match', 'internal-link-suggestions' ),
				'fair'         => __( 'Possible match', 'internal-link-suggestions' ),
				'noneIn'       => __( 'No posts mention this one\'s title or keywords yet. Add link keywords in its editor (Internal Links panel) to widen the search, or link to it from a new post.', 'internal-link-suggestions' ),
				'findTitle'    => __( 'Posts that could link here', 'internal-link-suggestions' ),
				'linksIn'      => __( 'Linked from', 'internal-link-suggestions' ),
				'linksOut'     => __( 'Links to', 'internal-link-suggestions' ),
				'anchor'       => __( 'Anchor text', 'internal-link-suggestions' ),
				'noAnchor'     => __( '(image or empty link)', 'internal-link-suggestions' ),
				'error'        => __( 'Something went wrong. Please try again.', 'internal-link-suggestions' ),
				'excludeOn'    => __( 'Not a link target', 'internal-link-suggestions' ),
				'excludeOff'   => __( 'Make it a link target again', 'internal-link-suggestions' ),
				'excludedNote' => __( 'Hidden from this report and from suggestions.', 'internal-link-suggestions' ),
				'restoredNote' => __( 'It can be suggested as a link again.', 'internal-link-suggestions' ),
				/* translators: 1: posts scanned, 2: posts in total. */
				'progress'     => __( '%1$s of %2$s posts', 'internal-link-suggestions' ),
				'scanDone'     => __( 'Scan finished. Loading the report…', 'internal-link-suggestions' ),
			),
		);

		wp_add_inline_script( 'internal-link-suggestions-common', 'var internal_link_suggestions = ' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
	}

	// Settings --------------------------------------------------------------------

	/**
	 * Register the settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::PAGE,
			Internal_Link_Suggestions_Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Internal_Link_Suggestions_Plugin::defaults(),
			)
		);
	}

	/**
	 * Clean the submitted settings before they are saved.
	 *
	 * @param mixed $input Raw form values.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$types = isset( $input['post_types'] ) && is_array( $input['post_types'] ) ? array_map( 'sanitize_key', $input['post_types'] ) : array();
		$types = array_values( array_intersect( $types, array_keys( Internal_Link_Suggestions_Plugin::available_post_types() ) ) );

		if ( ! $types ) {
			add_settings_error( Internal_Link_Suggestions_Plugin::OPTION, 'ils_types', __( 'Pick at least one type of content. Posts were kept.', 'internal-link-suggestions' ) );
			$types = array( 'post' );
		}

		$ignore = isset( $input['ignore_words'] ) && is_string( $input['ignore_words'] ) ? sanitize_textarea_field( $input['ignore_words'] ) : '';

		return array(
			'post_types'   => $types,
			'editor'       => empty( $input['editor'] ) ? 0 : 1,
			'limit'        => isset( $input['limit'] ) ? max( 5, min( 50, absint( $input['limit'] ) ) ) : 15,
			'max_words'    => isset( $input['max_words'] ) ? max( 2, min( 12, absint( $input['max_words'] ) ) ) : 8,
			'ignore_words' => Internal_Link_Suggestions_Text::cut( $ignore, 2000 ),
		);
	}

	// Page --------------------------------------------------------------------------

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$tab = self::current_tab();
		?>
		<div class="ils-header">
			<div class="ils-header-title">
				<span class="ils-header-icon"><?php echo self::icon( self::ICON, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<span class="ils-version"><?php echo esc_html( 'v' . INTERNAL_LINK_SUGGESTIONS_VERSION ); ?></span>
			</div>
			<nav class="ils-tabs" aria-label="<?php esc_attr_e( 'Sections', 'internal-link-suggestions' ); ?>">
				<?php foreach ( self::TABS as $id => $icon ) : ?>
					<a href="<?php echo esc_url( self::tab_url( $id ) ); ?>" class="ils-tab<?php echo $id === $tab ? ' is-active' : ''; ?>"<?php echo $id === $tab ? ' aria-current="page"' : ''; ?>>
						<?php echo self::icon( $icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php echo esc_html( self::tab_label( $id ) ); ?>
						<?php if ( 'orphans' === $id && $this->totals()['orphans'] ) : ?>
							<span class="ils-tab-count"><?php echo esc_html( number_format_i18n( $this->totals()['orphans'] ) ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>

		<div class="wrap ils-wrap">
			<hr class="wp-header-end">

			<?php
			// Pages outside the Settings menu have to show "Settings saved." themselves.
			settings_errors();

			switch ( $tab ) {
				case 'report':
					$this->render_report_tab();
					break;
				case 'log':
					$this->render_log_tab();
					break;
				case 'settings':
					$this->render_settings_tab();
					break;
				default:
					$this->render_orphans_tab();
			}
			?>
		</div>
		<?php
	}

	/**
	 * The scan's progress, while it runs.
	 *
	 * @return void
	 */
	private function render_scan_box() {
		$state = Internal_Link_Suggestions_Indexer::get_state();

		if ( 'running' !== $state['status'] ) {
			return;
		}

		$percent = $state['total'] ? min( 100, round( 100 * $state['done'] / $state['total'] ) ) : 0;
		?>
		<div class="ils-scan" data-ils-scan>
			<span class="ils-scan-icon"><?php echo self::icon( 'loader-circle', 22, 'ils-spin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
			<span class="ils-scan-body">
				<strong><?php esc_html_e( 'Reading your posts', 'internal-link-suggestions' ); ?></strong>
				<span><?php esc_html_e( 'Every post is read once to learn what it\'s about and which posts it links to. Numbers below fill in as it goes, and suggestions get better when it\'s done.', 'internal-link-suggestions' ); ?></span>
				<span class="ils-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $percent ); ?>"><span style="width:<?php echo esc_attr( (string) $percent ); ?>%"></span></span>
				<span class="ils-muted" data-ils-scan-text>
					<?php
					/* translators: 1: posts scanned, 2: posts in total. */
					echo esc_html( sprintf( __( '%1$s of %2$s posts', 'internal-link-suggestions' ), number_format_i18n( (int) $state['done'] ), number_format_i18n( (int) $state['total'] ) ) );
					?>
				</span>
			</span>
		</div>
		<?php
	}

	/**
	 * The summary numbers.
	 *
	 * @return void
	 */
	private function render_stats() {
		$totals = $this->totals();
		$share  = $totals['posts'] ? round( 100 * $totals['orphans'] / $totals['posts'] ) : 0;
		$stats  = array(
			array( 'files', number_format_i18n( $totals['posts'] ), __( 'Posts and pages indexed', 'internal-link-suggestions' ), '' ),
			array( 'link', number_format_i18n( $totals['links'] ), __( 'Internal links between them', 'internal-link-suggestions' ), '' ),
			/* translators: %s: percentage of posts. */
			array( 'unlink', number_format_i18n( $totals['orphans'] ), sprintf( __( 'Orphaned (%s%% of posts): nothing links to them', 'internal-link-suggestions' ), number_format_i18n( $share ) ), $totals['orphans'] ? 'is-warn' : 'is-good' ),
			array( 'arrow-up-right', number_format_i18n( $totals['no_outbound'] ), __( 'Link to no other post', 'internal-link-suggestions' ), '' ),
		);
		?>
		<div class="ils-stats">
			<?php foreach ( $stats as $stat ) : ?>
				<div class="ils-stat <?php echo esc_attr( $stat[3] ); ?>">
					<span class="ils-stat-icon"><?php echo self::icon( $stat[0], 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="ils-stat-num"><?php echo esc_html( $stat[1] ); ?></span>
					<span class="ils-stat-label"><?php echo esc_html( $stat[2] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * The table with its search box.
	 *
	 * @param string $tab Tab.
	 * @return void
	 */
	private function render_table( $tab ) {
		if ( ! $this->table ) {
			return;
		}

		$this->table->prepare_items();
		$filters = $this->filters();
		?>
		<form method="get" class="ils-report">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
			<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
			<?php if ( 'report' === $tab && 'all' !== $filters['view'] ) : ?>
				<input type="hidden" name="view" value="<?php echo esc_attr( $filters['view'] ); ?>">
			<?php endif; ?>
			<div class="ils-report-head">
				<?php $this->table->views(); ?>
				<?php $this->table->search_box( __( 'Search titles', 'internal-link-suggestions' ), 'ils-search' ); ?>
			</div>
			<?php $this->table->display(); ?>
		</form>
		<?php
	}

	/**
	 * Orphaned content tab.
	 *
	 * @return void
	 */
	private function render_orphans_tab() {
		$this->render_scan_box();
		$this->render_stats();
		?>
		<div class="ils-intro-box">
			<span class="ils-intro-icon"><?php echo self::icon( 'unlink', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
			<p>
				<?php esc_html_e( 'These posts aren\'t linked from any of your other posts or pages, so readers rarely find them and search engines treat them as less important. Find links lists older posts that already mention the topic; Add link puts the link in for you, and you can undo it any time.', 'internal-link-suggestions' ); ?>
			</p>
		</div>
		<?php
		$this->render_table( 'orphans' );
	}

	/**
	 * Link report tab.
	 *
	 * @return void
	 */
	private function render_report_tab() {
		$this->render_scan_box();
		$this->render_stats();
		$this->render_table( 'report' );
		$this->render_pairing( 'ils-pairing-inline' );
	}

	/**
	 * Added links tab: the log, with Undo.
	 *
	 * @return void
	 */
	private function render_log_tab() {
		$page     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Paging only.
		$per_page = 25;
		$result   = Internal_Link_Suggestions_DB::log_page( $per_page, $page );
		$pages    = (int) ceil( $result['total'] / $per_page );
		?>
		<div class="ils-intro-box">
			<span class="ils-intro-icon"><?php echo self::icon( 'history', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
			<p><?php esc_html_e( 'Every link this plugin added to an older post. Undo takes the link out and leaves the words as they were. Links you insert while writing are part of your own edit, so the editor\'s Undo and revisions cover those.', 'internal-link-suggestions' ); ?></p>
		</div>

		<?php if ( ! $result['rows'] ) : ?>
			<div class="ils-empty-card">
				<?php echo self::icon( 'history', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
				<p><?php esc_html_e( 'No links added yet. Use Find links on the Orphaned Content tab, or the Link here tab in the editor\'s Internal Links panel.', 'internal-link-suggestions' ); ?></p>
			</div>
			<?php
			return;
		endif;
		?>

		<table class="wp-list-table widefat fixed striped ils-log">
			<thead>
				<tr>
					<th scope="col" class="ils-log-when"><?php esc_html_e( 'Added', 'internal-link-suggestions' ); ?></th>
					<th scope="col"><?php esc_html_e( 'In this post', 'internal-link-suggestions' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Linked words', 'internal-link-suggestions' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Links to', 'internal-link-suggestions' ); ?></th>
					<th scope="col" class="ils-log-who"><?php esc_html_e( 'By', 'internal-link-suggestions' ); ?></th>
					<th scope="col" class="ils-log-actions"><span class="screen-reader-text"><?php esc_html_e( 'Undo', 'internal-link-suggestions' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $result['rows'] as $row ) : ?>
					<?php
					$source = get_post( (int) $row['source_id'] );
					$target = get_post( (int) $row['target_id'] );
					$user   = get_userdata( (int) $row['user_id'] );
					$undone = 'added' !== $row['status'];
					?>
					<tr data-log="<?php echo esc_attr( (string) $row['id'] ); ?>"<?php echo $undone ? ' class="is-undone"' : ''; ?>>
						<td class="ils-log-when"><?php echo self::date( (int) $row['created'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in date(). ?></td>
						<td><?php echo $this->post_cell( $source ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in post_cell(). ?></td>
						<td><mark class="ils-anchor"><?php echo esc_html( $row['anchor'] ); ?></mark></td>
						<td><?php echo $this->post_cell( $target, (string) $row['url'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in post_cell(). ?></td>
						<td class="ils-log-who">
							<?php if ( $user ) : ?>
								<span class="ils-person"><?php echo get_avatar( $user->ID, 20 ); ?><?php echo esc_html( $user->display_name ); ?></span>
							<?php else : ?>
								<span class="ils-muted">&mdash;</span>
							<?php endif; ?>
						</td>
						<td class="ils-log-actions">
							<?php if ( $undone ) : ?>
								<span class="ils-status"><?php esc_html_e( 'Undone', 'internal-link-suggestions' ); ?></span>
							<?php elseif ( $source && current_user_can( 'edit_post', $source->ID ) ) : ?>
								<button type="button" class="button button-small" data-ils-undo="<?php echo esc_attr( (string) $row['id'] ); ?>"><?php echo self::icon( 'undo-2', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Undo', 'internal-link-suggestions' ); ?></button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $pages > 1 ) : ?>
			<div class="ils-pager">
				<?php
				echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core markup.
					array(
						'base'    => add_query_arg( 'paged', '%#%', self::tab_url( 'log' ) ),
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					)
				);
				?>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * A post's title with edit/view links for the log.
	 *
	 * @param WP_Post|null $post Post.
	 * @param string       $url  Fallback URL when it's gone.
	 * @return string
	 */
	private function post_cell( $post, $url = '' ) {
		if ( ! $post ) {
			return '<span class="ils-muted">' . esc_html__( '(deleted)', 'internal-link-suggestions' ) . '</span>' . ( $url ? '<br><span class="ils-url">' . esc_html( $url ) . '</span>' : '' );
		}

		$title = '' === trim( $post->post_title ) ? __( '(no title)', 'internal-link-suggestions' ) : Internal_Link_Suggestions_Text::plain( $post->post_title );
		$edit  = current_user_can( 'edit_post', $post->ID ) ? get_edit_post_link( $post->ID ) : '';

		return '<strong>' . ( $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . '</strong>'
			. ' <a class="ils-view" href="' . esc_url( get_permalink( $post ) ) . '" target="_blank" rel="noopener" aria-label="' . esc_attr__( 'View', 'internal-link-suggestions' ) . '">' . self::icon( 'external-link', 13 ) . '</a>';
	}

	/**
	 * Settings tab.
	 *
	 * @return void
	 */
	private function render_settings_tab() {
		$settings = $this->plugin->get_settings();
		$included = $this->plugin->post_types();
		$option   = Internal_Link_Suggestions_Plugin::OPTION;
		$state    = Internal_Link_Suggestions_Indexer::get_state();
		$totals   = $this->totals();
		?>
		<div id="poststuff">
			<div id="post-body" class="metabox-holder columns-2">
				<div id="post-body-content">
					<form action="options.php" method="post" id="ils-settings">
						<?php settings_fields( self::PAGE ); ?>

						<div class="postbox ils-box">
							<div class="postbox-header">
								<h2 class="hndle"><?php echo self::icon( 'files', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Content', 'internal-link-suggestions' ); ?></h2>
							</div>
							<div class="inside">
								<p class="ils-intro"><?php esc_html_e( 'Published content of these types is suggested as a link, gets suggestions in its editor, and is listed in the reports. Changing this starts a fresh scan.', 'internal-link-suggestions' ); ?></p>
								<table class="form-table" role="presentation">
									<tr>
										<th scope="row"><?php esc_html_e( 'Post types', 'internal-link-suggestions' ); ?></th>
										<td>
											<fieldset class="ils-types">
												<legend class="screen-reader-text"><?php esc_html_e( 'Post types', 'internal-link-suggestions' ); ?></legend>
												<?php foreach ( Internal_Link_Suggestions_Plugin::available_post_types() as $type => $label ) : ?>
													<label class="ils-check">
														<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[post_types][]" value="<?php echo esc_attr( $type ); ?>"<?php checked( in_array( $type, $included, true ) ); ?>>
														<span><?php echo esc_html( $label ); ?> <code><?php echo esc_html( $type ); ?></code></span>
													</label>
												<?php endforeach; ?>
											</fieldset>
										</td>
									</tr>
								</table>
							</div>
						</div>

						<div class="postbox ils-box">
							<div class="postbox-header">
								<h2 class="hndle"><?php echo self::icon( 'sparkles', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Suggestions', 'internal-link-suggestions' ); ?></h2>
							</div>
							<div class="inside">
								<table class="form-table" role="presentation">
									<tr>
										<th scope="row"><?php esc_html_e( 'In the editor', 'internal-link-suggestions' ); ?></th>
										<td>
											<label class="ils-check">
												<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[editor]" value="1"<?php checked( 1, (int) $settings['editor'] ); ?>>
												<span><?php esc_html_e( 'Show link suggestions while writing', 'internal-link-suggestions' ); ?></span>
											</label>
											<p class="description"><?php esc_html_e( 'An Internal Links panel in the block editor (the waypoints icon at the top right), or a box under the content in the classic editor.', 'internal-link-suggestions' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="ils-limit"><?php esc_html_e( 'Suggestions shown', 'internal-link-suggestions' ); ?></label></th>
										<td>
											<input type="number" id="ils-limit" class="small-text" min="5" max="50" name="<?php echo esc_attr( $option ); ?>[limit]" value="<?php echo esc_attr( (string) (int) $settings['limit'] ); ?>">
											<p class="description"><?php esc_html_e( 'The most suggestions listed each way, best first.', 'internal-link-suggestions' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="ils-max-words"><?php esc_html_e( 'Longest anchor', 'internal-link-suggestions' ); ?></label></th>
										<td>
											<span class="ils-inline">
												<input type="number" id="ils-max-words" class="small-text" min="2" max="12" name="<?php echo esc_attr( $option ); ?>[max_words]" value="<?php echo esc_attr( (string) (int) $settings['max_words'] ); ?>">
												<span><?php esc_html_e( 'words', 'internal-link-suggestions' ); ?></span>
											</span>
											<p class="description"><?php esc_html_e( 'Short, specific anchor text reads naturally and tells readers and search engines what the linked post is about.', 'internal-link-suggestions' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="ils-ignore"><?php esc_html_e( 'Words to ignore', 'internal-link-suggestions' ); ?></label></th>
										<td>
											<textarea id="ils-ignore" class="large-text" rows="3" name="<?php echo esc_attr( $option ); ?>[ignore_words]" placeholder="<?php esc_attr_e( 'e.g. your brand name, separated by commas', 'internal-link-suggestions' ); ?>"><?php echo esc_textarea( (string) $settings['ignore_words'] ); ?></textarea>
											<p class="description"><?php esc_html_e( 'Words that appear in almost every post (like your brand or product name) and shouldn\'t make posts look related. Common words like "the" and "how" are always ignored.', 'internal-link-suggestions' ); ?></p>
										</td>
									</tr>
								</table>
							</div>
						</div>
					</form>

					<div class="postbox ils-box">
						<div class="postbox-header">
							<h2 class="hndle"><?php echo self::icon( 'database', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Index', 'internal-link-suggestions' ); ?></h2>
						</div>
						<div class="inside">
							<p class="ils-intro"><?php esc_html_e( 'Suggestions and reports read from an index of your published posts: the words each one is about and the internal links between them. It was built by a scan when the plugin was activated, and each post is re-read whenever it\'s saved, so it stays current on its own. A rescan is only needed after posts were changed directly in the database or by an import that skipped WordPress.', 'internal-link-suggestions' ); ?></p>
							<table class="ils-kv">
								<tr>
									<th scope="row"><?php esc_html_e( 'Posts indexed', 'internal-link-suggestions' ); ?></th>
									<td><?php echo esc_html( number_format_i18n( $totals['posts'] ) ); ?></td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Internal links', 'internal-link-suggestions' ); ?></th>
									<td><?php echo esc_html( number_format_i18n( $totals['links'] ) ); ?></td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Last full scan', 'internal-link-suggestions' ); ?></th>
									<td data-ils-scan-cell>
										<?php
										if ( 'running' === $state['status'] ) {
											/* translators: 1: posts scanned, 2: posts in total. */
											echo esc_html( sprintf( __( 'Running now: %1$s of %2$s posts', 'internal-link-suggestions' ), number_format_i18n( (int) $state['done'] ), number_format_i18n( (int) $state['total'] ) ) );
										} else {
											echo self::date( (int) $state['finished'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in date().
										}
										?>
									</td>
								</tr>
							</table>
							<button type="button" class="button" data-ils-rescan>
								<?php echo self::icon( 'refresh-cw', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
								<?php esc_html_e( 'Rescan all content', 'internal-link-suggestions' ); ?>
							</button>
						</div>
					</div>
				</div>

				<div id="postbox-container-1" class="postbox-container">
					<div class="ils-sidebar">
						<div class="postbox ils-box ils-save-box">
							<div class="postbox-header">
								<h2 class="hndle"><?php echo self::icon( 'save', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Save', 'internal-link-suggestions' ); ?></h2>
							</div>
							<div class="inside">
								<p><?php esc_html_e( 'Changes apply to the next suggestions. A change of post types starts a fresh scan in the background.', 'internal-link-suggestions' ); ?></p>
								<?php submit_button( __( 'Save Changes', 'internal-link-suggestions' ), 'primary', 'submit', false, array( 'form' => 'ils-settings' ) ); ?>
							</div>
						</div>

						<div class="postbox ils-box">
							<div class="postbox-header">
								<h2 class="hndle"><?php echo self::icon( 'lightbulb', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Good to know', 'internal-link-suggestions' ); ?></h2>
							</div>
							<div class="inside">
								<ul class="ils-checklist">
									<li><?php echo self::icon( 'tag', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Posts are matched by their title, their link keywords, and focus keywords from Yoast SEO, Rank Math, SEOPress or All in One SEO.', 'internal-link-suggestions' ); ?></span></li>
									<li><?php echo self::icon( 'text-cursor-input', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Links only go into paragraphs and list items: never headings, existing links, buttons, code or shortcodes.', 'internal-link-suggestions' ); ?></span></li>
									<li><?php echo self::icon( 'unlink', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Orphaned means no link from another post\'s content. Menus and widgets don\'t count, and the front page, blog page and privacy policy are never listed.', 'internal-link-suggestions' ); ?></span></li>
									<li><?php echo self::icon( 'calendar', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Adding a link to an older post keeps its "last modified" date and saves a revision first.', 'internal-link-suggestions' ); ?></span></li>
								</ul>
							</div>
						</div>

						<?php $this->render_pairing( 'postbox ils-box' ); ?>

						<?php $this->render_updates_card(); ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * The note about Link Audit and Reports (outbound links to other sites).
	 *
	 * @param string $class Wrapper classes.
	 * @return void
	 */
	private function render_pairing( $class ) {
		$active = defined( 'LINK_AUDIT_REPORTS_VERSION' );
		$url    = $active ? admin_url( 'admin.php?page=link-audit-reports' ) : self::LINK_AUDIT_URL;
		$text   = $active
			? __( 'Link Audit and Reports is installed too: it covers the other half, every link from your posts to other sites, with nofollow and sponsored fixes.', 'internal-link-suggestions' )
			: __( 'This plugin handles links between your own posts. For links to other sites (nofollow, sponsored, a whitelist and blacklist), pair it with our free Link Audit and Reports.', 'internal-link-suggestions' );

		if ( false !== strpos( $class, 'postbox' ) ) :
			?>
			<div class="<?php echo esc_attr( $class ); ?> ils-pairing">
				<div class="postbox-header">
					<h2 class="hndle"><?php echo self::icon( 'globe', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Outbound links', 'internal-link-suggestions' ); ?></h2>
				</div>
				<div class="inside">
					<p><?php echo esc_html( $text ); ?></p>
					<a class="button" href="<?php echo esc_url( $url ); ?>"<?php echo $active ? '' : ' target="_blank" rel="noopener"'; ?>>
						<?php echo self::icon( $active ? 'arrow-right' : 'external-link', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php echo esc_html( $active ? __( 'Open Link Audit and Reports', 'internal-link-suggestions' ) : __( 'Get Link Audit and Reports', 'internal-link-suggestions' ) ); ?>
					</a>
				</div>
			</div>
			<?php
			return;
		endif;
		?>
		<p class="<?php echo esc_attr( $class ); ?>">
			<?php echo self::icon( 'globe', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
			<span>
				<?php echo esc_html( $text ); ?>
				<a href="<?php echo esc_url( $url ); ?>"<?php echo $active ? '' : ' target="_blank" rel="noopener"'; ?>><?php echo esc_html( $active ? __( 'Open it', 'internal-link-suggestions' ) : __( 'Learn more', 'internal-link-suggestions' ) ); ?></a>
			</span>
		</p>
		<?php
	}

	/**
	 * Show where updates come from, so it's easy to confirm the repo is wired up.
	 *
	 * @return void
	 */
	private function render_updates_card() {
		$repo_label = preg_replace( '#^https?://[^/]+/#', '', untrailingslashit( INTERNAL_LINK_SUGGESTIONS_REPO ) );
		$check_url  = Internal_Link_Suggestions_Updater::check_now_url();
		?>
		<div class="postbox ils-box ils-updates-box">
			<div class="postbox-header">
				<h2 class="hndle"><?php echo self::icon( 'refresh-cw', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Updates', 'internal-link-suggestions' ); ?></h2>
			</div>
			<div class="inside">
				<p>
					<?php
					/* translators: %s: installed plugin version. */
					printf( esc_html__( 'Installed version: %s', 'internal-link-suggestions' ), '<strong>' . esc_html( INTERNAL_LINK_SUGGESTIONS_VERSION ) . '</strong>' );
					?>
				</p>

				<?php if ( ! Internal_Link_Suggestions_Updater::has_repo() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: header name, 2: main plugin file. */
							esc_html__( 'No repo set. Add it to the %1$s header in %2$s to turn on automatic updates.', 'internal-link-suggestions' ),
							'<code>Update URI</code>',
							'<code>' . esc_html( plugin_basename( INTERNAL_LINK_SUGGESTIONS_FILE ) ) . '</code>'
						);
						?>
					</p>
				<?php elseif ( Internal_Link_Suggestions_Updater::is_dev_copy() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: repo, 2: constant name. */
							esc_html__( 'Releases come from %1$s, but update checks are off on this development copy so an update can\'t overwrite your work. Define %2$s as false in wp-config.php to test updates here.', 'internal-link-suggestions' ),
							'<a href="' . esc_url( INTERNAL_LINK_SUGGESTIONS_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>',
							'<code>INTERNAL_LINK_SUGGESTIONS_DEV_MODE</code>'
						);
						?>
					</p>
				<?php else : ?>
					<p>
						<?php
						printf(
							/* translators: %s: repo. */
							esc_html__( 'Updates come from the latest release of %s.', 'internal-link-suggestions' ),
							'<a href="' . esc_url( INTERNAL_LINK_SUGGESTIONS_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>'
						);
						?>
					</p>
					<?php if ( $check_url ) : ?>
						<p><a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php echo self::icon( 'refresh-cw', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Check for updates', 'internal-link-suggestions' ); ?></a></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
