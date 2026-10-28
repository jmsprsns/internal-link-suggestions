<?php
/**
 * The suggestions in the post editor: an "Internal Links" sidebar in the
 * block editor (plus a reminder in the pre-publish checks), and a meta box
 * in the classic editor.
 *
 * @package Internal_Link_Suggestions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Editor integration.
 */
final class Internal_Link_Suggestions_Editor {

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

		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_classic' ) );
	}

	/**
	 * The post being edited, when it gets suggestions.
	 *
	 * @return WP_Post|null
	 */
	private function current_post() {
		if ( ! $this->plugin->get_setting( 'editor' ) ) {
			return null;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		// Only the post editor: not the site, widget or template editors.
		if ( ! $screen || 'post' !== $screen->base ) {
			return null;
		}

		$post = get_post();

		if ( ! $post || ! in_array( $post->post_type, $this->plugin->post_types(), true ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return null;
		}

		return $post;
	}

	/**
	 * Data both editors need.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	private function script_data( WP_Post $post ) {
		$state = Internal_Link_Suggestions_Indexer::get_state();
		$icons = array();

		foreach ( array( 'waypoints', 'link', 'link-2', 'arrow-up-right', 'arrow-down-left', 'external-link', 'square-pen', 'refresh-cw', 'circle-check', 'undo-2', 'loader-circle', 'info', 'tag', 'sparkles', 'unlink', 'plus' ) as $name ) {
			$icons[ $name ] = Internal_Link_Suggestions_Icons::get( $name, 16 );
		}

		return array(
			'postId'      => (int) $post->ID,
			'namespace'   => Internal_Link_Suggestions_REST::NS,
			'keywords'    => Internal_Link_Suggestions_Indexer::own_keywords( $post->ID ),
			'seoKeywords' => Internal_Link_Suggestions_Indexer::seo_keywords( $post->ID ),
			'excluded'    => Internal_Link_Suggestions_Indexer::is_excluded( $post->ID ),
			'indexed'     => Internal_Link_Suggestions_DB::count_posts(),
			'scanning'    => 'running' === $state['status'],
			'reportUrl'   => current_user_can( Internal_Link_Suggestions_Admin::CAPABILITY ) ? Internal_Link_Suggestions_Admin::tab_url() : '',
			'icons'       => $icons,
			'i18n'        => array(
				'title'          => __( 'Internal Links', 'internal-link-suggestions' ),
				'linkTo'         => __( 'Link to', 'internal-link-suggestions' ),
				'linkHere'       => __( 'Link here', 'internal-link-suggestions' ),
				'outIntro'       => __( 'Older posts your draft already talks about. Insert link turns the highlighted words into the link.', 'internal-link-suggestions' ),
				'inIntro'        => __( 'Older posts that mention this topic but don\'t link here yet. Add link puts the link in that post for you.', 'internal-link-suggestions' ),
				'refresh'        => __( 'Refresh', 'internal-link-suggestions' ),
				'changed'        => __( 'Your draft has changed since these were found.', 'internal-link-suggestions' ),
				'loading'        => __( 'Finding suggestions…', 'internal-link-suggestions' ),
				'insert'         => __( 'Insert link', 'internal-link-suggestions' ),
				'inserted'       => __( 'Linked', 'internal-link-suggestions' ),
				'notFound'       => __( 'Those words have changed in your draft. Refresh for new suggestions.', 'internal-link-suggestions' ),
				'add'            => __( 'Add link', 'internal-link-suggestions' ),
				'adding'         => __( 'Adding…', 'internal-link-suggestions' ),
				'added'          => __( 'Link added', 'internal-link-suggestions' ),
				'undo'           => __( 'Undo', 'internal-link-suggestions' ),
				'undone'         => __( 'Link removed', 'internal-link-suggestions' ),
				'open'           => __( 'Open post', 'internal-link-suggestions' ),
				'edit'           => __( 'Edit', 'internal-link-suggestions' ),
				'view'           => __( 'View', 'internal-link-suggestions' ),
				'orphaned'       => __( 'No links yet', 'internal-link-suggestions' ),
				'strong'         => __( 'Strong match', 'internal-link-suggestions' ),
				'good'           => __( 'Good match', 'internal-link-suggestions' ),
				'fair'           => __( 'Possible match', 'internal-link-suggestions' ),
				'noneOut'        => __( 'No suggestions yet. They appear once your draft mentions topics your other posts cover.', 'internal-link-suggestions' ),
				'noneIn'         => __( 'No older posts mention this topic without linking here. Adding link keywords below can help.', 'internal-link-suggestions' ),
				/* translators: %d: number of posts. */
				'linkedCount'    => __( 'Your draft already links to %d of your posts.', 'internal-link-suggestions' ),
				/* translators: %d: number of posts. */
				'existingCount'  => __( '%d posts already link here.', 'internal-link-suggestions' ),
				'publishFirst'   => __( 'Publish this post first. Links can be added to older posts once it has a public address.', 'internal-link-suggestions' ),
				'excludedNote'   => __( 'This post is set as not a link target, so links to it aren\'t added.', 'internal-link-suggestions' ),
				'scanning'       => __( 'Your posts are still being indexed, so there may be more suggestions in a few minutes.', 'internal-link-suggestions' ),
				'empty'          => __( 'No posts are indexed yet. The first scan runs in the background after activation.', 'internal-link-suggestions' ),
				'keywords'       => __( 'Link keywords', 'internal-link-suggestions' ),
				'keywordsHelp'   => __( 'Phrases other posts can link to this one with, besides its title. One per line or separated by commas.', 'internal-link-suggestions' ),
				'seoKeywords'    => __( 'Also used, from your SEO plugin:', 'internal-link-suggestions' ),
				'exclude'        => __( 'Don\'t suggest links to this post', 'internal-link-suggestions' ),
				'excludeHelp'    => __( 'For pages like thank-you or landing pages. It also stays off the orphaned content report.', 'internal-link-suggestions' ),
				'saved'          => __( 'Saved', 'internal-link-suggestions' ),
				'saving'         => __( 'Saving…', 'internal-link-suggestions' ),
				'error'          => __( 'Something went wrong. Please try again.', 'internal-link-suggestions' ),
				'prePublish'     => __( 'Internal links', 'internal-link-suggestions' ),
				/* translators: %d: number of suggestions. */
				'prePublishSome' => __( '%d older posts could be linked from this one.', 'internal-link-suggestions' ),
				'prePublishNone' => __( 'No new internal link suggestions.', 'internal-link-suggestions' ),
				'review'         => __( 'Review suggestions', 'internal-link-suggestions' ),
				'report'         => __( 'Orphaned content report', 'internal-link-suggestions' ),
				'outOf'          => __( 'Suggested link', 'internal-link-suggestions' ),
				'in'             => __( 'in', 'internal-link-suggestions' ),
			),
		);
	}

	/**
	 * Pass the script data as JSON (wp_localize_script would turn numbers and
	 * booleans into strings).
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	private function add_data( WP_Post $post ) {
		wp_add_inline_script( 'internal-link-suggestions-common', 'var internal_link_suggestions = ' . wp_json_encode( $this->script_data( $post ), JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
	}

	/**
	 * Block editor: the sidebar and pre-publish panel.
	 *
	 * @return void
	 */
	public function enqueue_block_editor() {
		$post = $this->current_post();

		if ( ! $post ) {
			return;
		}

		wp_enqueue_style( 'internal-link-suggestions-editor', INTERNAL_LINK_SUGGESTIONS_URL . 'assets/editor.css', array(), INTERNAL_LINK_SUGGESTIONS_VERSION );
		wp_enqueue_script( 'internal-link-suggestions-common', INTERNAL_LINK_SUGGESTIONS_URL . 'assets/common.js', array( 'wp-api-fetch' ), INTERNAL_LINK_SUGGESTIONS_VERSION, true );
		wp_enqueue_script(
			'internal-link-suggestions-editor',
			INTERNAL_LINK_SUGGESTIONS_URL . 'assets/editor.js',
			array( 'internal-link-suggestions-common', 'wp-plugins', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-editor', 'wp-edit-post', 'wp-block-editor' ),
			INTERNAL_LINK_SUGGESTIONS_VERSION,
			true
		);

		$this->add_data( $post );
	}

	/**
	 * Classic editor: the meta box below the content.
	 *
	 * @param string       $post_type Post type.
	 * @param WP_Post|null $post      Post.
	 * @return void
	 */
	public function add_meta_box( $post_type, $post = null ) {
		if ( ! $post instanceof WP_Post || ! $this->plugin->get_setting( 'editor' ) || ! in_array( $post_type, $this->plugin->post_types(), true ) ) {
			return;
		}

		add_meta_box(
			'internal-link-suggestions',
			__( 'Internal Link Suggestions', 'internal-link-suggestions' ),
			array( $this, 'render_meta_box' ),
			$post_type,
			'normal',
			'default',
			// The block editor has its own sidebar instead.
			array( '__back_compat_meta_box' => true )
		);
	}

	/**
	 * The classic editor meta box. Filled in by classic.js.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		?>
		<div id="ils-classic" class="ils-panel" data-post="<?php echo esc_attr( (string) $post->ID ); ?>">
			<p class="ils-loading"><?php esc_html_e( 'Loading…', 'internal-link-suggestions' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Classic editor scripts, only where the meta box is.
	 *
	 * @param string $hook Admin page.
	 * @return void
	 */
	public function enqueue_classic( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		$post = $this->current_post();

		if ( ! $post || ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) ) {
			return;
		}

		wp_enqueue_style( 'internal-link-suggestions-editor', INTERNAL_LINK_SUGGESTIONS_URL . 'assets/editor.css', array(), INTERNAL_LINK_SUGGESTIONS_VERSION );
		wp_enqueue_script( 'internal-link-suggestions-common', INTERNAL_LINK_SUGGESTIONS_URL . 'assets/common.js', array( 'wp-api-fetch' ), INTERNAL_LINK_SUGGESTIONS_VERSION, true );
		wp_enqueue_script( 'internal-link-suggestions-classic', INTERNAL_LINK_SUGGESTIONS_URL . 'assets/classic.js', array( 'internal-link-suggestions-common' ), INTERNAL_LINK_SUGGESTIONS_VERSION, true );

		$this->add_data( $post );
	}
}
