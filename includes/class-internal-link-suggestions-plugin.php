<?php
/**
 * Main plugin orchestrator: owns the settings and boots every part.
 *
 * @package Internal_Link_Suggestions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton.
 */
final class Internal_Link_Suggestions_Plugin {

	/**
	 * Option key holding the plugin's settings array.
	 */
	const OPTION = 'internal_link_suggestions_settings';

	/**
	 * Single shared instance.
	 *
	 * @var Internal_Link_Suggestions_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Indexer.
	 *
	 * @var Internal_Link_Suggestions_Indexer
	 */
	private $indexer;

	/**
	 * Matcher, made when first needed.
	 *
	 * @var Internal_Link_Suggestions_Matcher|null
	 */
	private $matcher = null;

	/**
	 * Linker, made when first needed.
	 *
	 * @var Internal_Link_Suggestions_Linker|null
	 */
	private $linker = null;

	/**
	 * Settings cache.
	 *
	 * @var array|null
	 */
	private $settings = null;

	/**
	 * Get (and lazily create) the shared instance.
	 *
	 * @return Internal_Link_Suggestions_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}

		return self::$instance;
	}

	/**
	 * Nothing runs in the constructor, so instance() is safe to call from
	 * anywhere (activation, uninstall checks) before boot().
	 */
	private function __construct() {}

	/**
	 * Wire everything up.
	 *
	 * @return void
	 */
	private function boot() {
		$this->indexer = new Internal_Link_Suggestions_Indexer( $this );

		new Internal_Link_Suggestions_REST( $this );
		new Internal_Link_Suggestions_Editor( $this );

		if ( is_admin() ) {
			new Internal_Link_Suggestions_Admin( $this );
			add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		}

		// Settings that change what's indexed restart the scan. The first
		// save of an option fires add_option_*, not update_option_*.
		add_action( 'update_option_' . self::OPTION, array( $this, 'settings_changed' ), 10, 2 );
		add_action( 'add_option_' . self::OPTION, array( $this, 'settings_added' ), 10, 2 );
	}

	/**
	 * Create missing tables, and start the first scan on a site that never
	 * had one (e.g. the plugin was copied in rather than activated).
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		Internal_Link_Suggestions_DB::maybe_upgrade();

		if ( 'none' === Internal_Link_Suggestions_Indexer::get_state()['status'] ) {
			Internal_Link_Suggestions_Indexer::start_scan();
		}
	}

	/**
	 * The indexer.
	 *
	 * @return Internal_Link_Suggestions_Indexer
	 */
	public function indexer() {
		return $this->indexer;
	}

	/**
	 * The suggestion engine.
	 *
	 * @return Internal_Link_Suggestions_Matcher
	 */
	public function matcher() {
		if ( null === $this->matcher ) {
			$this->matcher = new Internal_Link_Suggestions_Matcher( $this );
		}

		return $this->matcher;
	}

	/**
	 * The link editor.
	 *
	 * @return Internal_Link_Suggestions_Linker
	 */
	public function linker() {
		if ( null === $this->linker ) {
			$this->linker = new Internal_Link_Suggestions_Linker( $this );
		}

		return $this->linker;
	}

	/**
	 * Default value for every setting.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'post_types'   => array( 'post', 'page' ),
			'editor'       => 1,
			'limit'        => 15,
			'max_words'    => 8,
			'ignore_words' => '',
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		if ( null === $this->settings ) {
			$saved          = get_option( self::OPTION, array() );
			$this->settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}

		return $this->settings;
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key from {@see defaults()}.
	 * @return mixed Null for an unknown key.
	 */
	public function get_setting( $key ) {
		$settings = $this->get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Public post types a site can include, as name => label. Media is left
	 * out: attachments have no content to link from.
	 *
	 * @return array<string,string>
	 */
	public static function available_post_types() {
		$out = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' !== $type->name ) {
				$out[ $type->name ] = (string) $type->labels->name;
			}
		}

		return $out;
	}

	/**
	 * Included post types that exist right now.
	 *
	 * @return string[]
	 */
	public function post_types() {
		$types = array_values( array_intersect( (array) $this->get_setting( 'post_types' ), array_keys( self::available_post_types() ) ) );

		/**
		 * Filters the post types that get suggestions, are suggested, and are
		 * listed in the reports.
		 *
		 * @param string[] $types Post type names.
		 */
		return array_values( array_map( 'strval', (array) apply_filters( 'internal_link_suggestions_post_types', $types ) ) );
	}

	/**
	 * Settings were saved: forget the cache, and rescan if the included
	 * post types changed.
	 *
	 * @param mixed $old   Old value.
	 * @param mixed $value New value.
	 * @return void
	 */
	public function settings_changed( $old, $value ) {
		$this->settings = null;
		Internal_Link_Suggestions_Text::reset();

		$before = is_array( $old ) && isset( $old['post_types'] ) ? (array) $old['post_types'] : self::defaults()['post_types'];
		$after  = is_array( $value ) && isset( $value['post_types'] ) ? (array) $value['post_types'] : array();

		sort( $before );
		sort( $after );

		if ( $before !== $after ) {
			Internal_Link_Suggestions_Indexer::start_scan();
		}
	}

	/**
	 * First save of the settings.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 * @return void
	 */
	public function settings_added( $option, $value ) {
		$this->settings_changed( self::defaults(), $value );
	}

	/**
	 * Posts that are linked from every page by design (the front page, the
	 * blog page, the privacy policy, shop pages), so never called orphaned.
	 *
	 * @return int[]
	 */
	public static function always_linked() {
		$ids = array(
			(int) get_option( 'page_on_front' ),
			(int) get_option( 'page_for_posts' ),
			(int) get_option( 'wp_page_for_privacy_policy' ),
		);

		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'shop', 'cart', 'checkout', 'myaccount', 'terms' ) as $page ) {
				$ids[] = (int) wc_get_page_id( $page );
			}
		}

		/**
		 * Filters the posts never listed as orphaned content.
		 *
		 * @param int[] $ids Post IDs.
		 */
		$ids = (array) apply_filters( 'internal_link_suggestions_always_linked', $ids );

		return array_values(
			array_filter(
				array_unique( array_map( 'intval', $ids ) ),
				function ( $id ) {
					return $id > 0;
				}
			)
		);
	}
}
