/**
 * "Internal Links" sidebar in the block editor, and a reminder in the
 * pre-publish checks.
 *
 * Link to: older posts the draft already talks about. Insert link wraps the
 * highlighted words, in the block they're in, in a link (the editor's own
 * Undo takes it out again). Link here: older posts that mention this one;
 * Add link edits that post on the server, with its own Undo.
 * InternalLinkSuggestions comes from common.js.
 */

/* global wp, InternalLinkSuggestions */
( function ( wp, ILS ) {
	'use strict';

	if ( ! wp || ! ILS || ! wp.plugins || ! wp.element || ! wp.components || ! wp.data || ! wp.apiFetch ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var useSelect = wp.data.useSelect;
	var components = wp.components;
	var editorApi = wp.editor || {};
	var editPost = wp.editPost || {};
	var PluginSidebar = editorApi.PluginSidebar || editPost.PluginSidebar;
	var PluginSidebarMoreMenuItem = editorApi.PluginSidebarMoreMenuItem || editPost.PluginSidebarMoreMenuItem;
	var PluginPrePublishPanel = editorApi.PluginPrePublishPanel || editPost.PluginPrePublishPanel;
	var config = ILS.config;
	var i18n = ILS.i18n;
	var NAME = 'internal-link-suggestions';
	var BLOCKS = [ 'core/paragraph', 'core/list', 'core/list-item', 'core/quote', 'core/freeform' ];

	if ( ! PluginSidebar || ! useSelect ) {
		return;
	}

	// The link keywords as typed, sent with every request.
	var keywordsText = ( config.keywords || [] ).join( '\n' );

	// Outbound suggestions, shared by the sidebar and the pre-publish panel.
	var store = { out: null, listeners: [], request: 0 };

	function setOut( value ) {
		store.out = value;
		store.listeners.forEach( function ( listener ) {
			listener( value );
		} );
	}

	function useOut() {
		var state = useState( store.out );

		useEffect( function () {
			var listener = function ( value ) {
				state[ 1 ]( value );
			};

			store.listeners.push( listener );

			return function () {
				store.listeners = store.listeners.filter( function ( fn ) {
					return fn !== listener;
				} );
			};
		}, [] );

		return state[ 0 ];
	}

	function fetchOut() {
		var select = wp.data.select;
		var editor = select( 'core/editor' );
		var id = ++store.request;
		var blocks = select( 'core/block-editor' ).getBlocks();

		setOut( Object.assign( { items: [] }, store.out || {}, { status: 'loading' } ) );

		ILS.request( '/outbound', {
			post_id: config.postId,
			title: editor.getEditedPostAttribute( 'title' ) || '',
			content: editor.getEditedPostContent(),
			keywords: keywordsText,
		} ).then(
			function ( response ) {
				if ( id === store.request ) {
					setOut( {
						status: 'done',
						items: response.items || [],
						linked: response.linked || 0,
						index: response.index || {},
						blocks: blocks,
						inserted: {},
						failed: {},
					} );
				}
			},
			function ( error ) {
				if ( id === store.request ) {
					setOut( { status: 'error', error: ILS.errorText( error ), items: [] } );
				}
			}
		);
	}

	// Inserting ------------------------------------------------------------------

	function htmlOf( value ) {
		if ( typeof value === 'string' ) {
			return value;
		}

		return value && typeof value.toHTMLString === 'function' ? value.toHTMLString() : null;
	}

	// Rich text attributes are RichTextData objects in WordPress 6.5+.
	function richValue( html, original ) {
		var RichTextData = wp.richText && wp.richText.RichTextData;

		if ( original && typeof original === 'object' && RichTextData && RichTextData.fromHTMLString ) {
			return RichTextData.fromHTMLString( html );
		}

		return html;
	}

	function showBlock( clientId ) {
		var dispatch = wp.data.dispatch( 'core/block-editor' );

		if ( dispatch.flashBlock ) {
			dispatch.flashBlock( clientId );
		}

		var frame = document.querySelector( 'iframe[name="editor-canvas"]' );
		var doc = frame && frame.contentDocument ? frame.contentDocument : document;
		var node = doc.querySelector( '[data-block="' + clientId + '"]' );

		if ( node && node.scrollIntoView ) {
			node.scrollIntoView( { block: 'center', behavior: 'smooth' } );
		}
	}

	/**
	 * Wrap the item's words in a link, in the block whose text holds the
	 * suggestion's sentence (or failing that, the first block with them).
	 *
	 * @return {boolean} Whether the words were found.
	 */
	function insertLink( item ) {
		var tries = [];
		var want = ILS.collapse( item.context );

		( function walk( blocks ) {
			blocks.forEach( function ( block ) {
				if ( BLOCKS.indexOf( block.name ) !== -1 ) {
					[ 'content', 'values', 'value' ].forEach( function ( attr ) {
						var html = htmlOf( block.attributes[ attr ] );
						if ( html ) {
							tries.push( { block: block, attr: attr, html: html } );
						}
					} );
				}
				if ( block.innerBlocks && block.innerBlocks.length ) {
					walk( block.innerBlocks );
				}
			} );
		} )( wp.data.select( 'core/block-editor' ).getBlocks() );

		for ( var pass = 0; pass < 2; pass++ ) {
			for ( var i = 0; i < tries.length; i++ ) {
				var attempt = tries[ i ];

				if ( pass === 0 && ( ! want || ILS.textOf( attempt.html ).indexOf( want ) === -1 ) ) {
					continue;
				}

				var html = ILS.wrapHtml( attempt.html, item.anchor, item.url, '' );

				if ( html === null ) {
					continue;
				}

				var attributes = {};
				attributes[ attempt.attr ] = richValue( html, attempt.block.attributes[ attempt.attr ] );
				wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( attempt.block.clientId, attributes );
				showBlock( attempt.block.clientId );

				return true;
			}
		}

		return false;
	}

	// Pieces ---------------------------------------------------------------------

	function Icon( props ) {
		return el( 'span', {
			className: 'ils-i' + ( props.className ? ' ' + props.className : '' ),
			'aria-hidden': 'true',
			dangerouslySetInnerHTML: { __html: ( config.icons && config.icons[ props.name ] ) || '' },
		} );
	}

	function Button( props ) {
		return el(
			'button',
			{
				type: 'button',
				className: 'components-button is-small is-compact ' + ( props.primary ? 'is-primary' : 'is-secondary' ) + ' ils-btn',
				onClick: props.onClick,
				disabled: props.disabled,
			},
			props.icon ? el( Icon, { name: props.icon } ) : null,
			props.children
		);
	}

	function Spinner( props ) {
		return el( 'p', { className: 'ils-loading' }, el( Icon, { name: 'loader-circle', className: 'ils-spin' } ), props.text );
	}

	function Note( props ) {
		return el( 'p', { className: 'ils-note' + ( props.type ? ' is-' + props.type : '' ) }, el( Icon, { name: props.icon || 'info' } ), el( 'span', null, props.children ) );
	}

	function Strength( props ) {
		var item = props.item;

		return el( 'span', { className: 'ils-strength is-' + item.strength, title: item.reason }, el( 'span', { className: 'ils-dot' } ), i18n[ item.strength ] || item.strength );
	}

	function Sentence( props ) {
		var item = props.item;

		return el( 'p', { className: 'ils-sentence' }, item.before, el( 'mark', null, item.anchor ), item.after );
	}

	function Title( props ) {
		var item = props.item;

		return el(
			'div',
			{ className: 'ils-item-head' },
			ILS.safeUrl( item.url ) ? el( 'a', { className: 'ils-item-title', href: item.url, target: '_blank', rel: 'noopener noreferrer' }, item.title ) : el( 'span', { className: 'ils-item-title' }, item.title ),
			el( 'span', { className: 'ils-item-meta' }, [ item.type, item.date ].filter( Boolean ).join( ' · ' ) ),
			item.orphaned ? el( 'span', { className: 'ils-badge' }, i18n.orphaned ) : null
		);
	}

	function sprintf( text, value ) {
		return String( text ).replace( '%d', value );
	}

	// Link to --------------------------------------------------------------------

	function OutTab() {
		var out = useOut();
		var blocks = useSelect( function ( select ) {
			return select( 'core/block-editor' ).getBlocks();
		}, [] );

		useEffect( function () {
			if ( ! store.out || store.out.status === 'error' ) {
				fetchOut();
			}
		}, [] );

		if ( ! out || ( out.status === 'loading' && ! out.items.length ) ) {
			return el( Spinner, { text: i18n.loading } );
		}

		if ( out.status === 'error' ) {
			return el( Fragment, null, el( Note, { type: 'error' }, out.error ), el( Button, { onClick: fetchOut, icon: 'refresh-cw' }, i18n.refresh ) );
		}

		var changed = out.status === 'done' && out.blocks !== blocks;
		var index = out.index || {};

		function insert( item ) {
			var ok = insertLink( item );
			var next = Object.assign( {}, out, {
				inserted: Object.assign( {}, out.inserted ),
				failed: Object.assign( {}, out.failed ),
				blocks: wp.data.select( 'core/block-editor' ).getBlocks(),
			} );

			if ( ok ) {
				next.inserted[ item.id ] = true;
				delete next.failed[ item.id ];
			} else {
				next.failed[ item.id ] = true;
			}

			setOut( next );
		}

		return el(
			Fragment,
			null,
			el( 'p', { className: 'ils-intro' }, i18n.outIntro ),
			el(
				'div',
				{ className: 'ils-toolbar' },
				el( Button, { onClick: fetchOut, icon: 'refresh-cw', disabled: out.status === 'loading' }, i18n.refresh ),
				out.status === 'loading' ? el( Icon, { name: 'loader-circle', className: 'ils-spin' } ) : null,
				changed && out.status !== 'loading' ? el( 'span', { className: 'ils-changed' }, i18n.changed ) : null
			),
			index.scanning ? el( Note, null, i18n.scanning ) : null,
			! index.posts && ! index.scanning ? el( Note, null, i18n.empty ) : null,
			out.items.length
				? el(
						'div',
						{ className: 'ils-list' },
						out.items.map( function ( item ) {
							var done = !! out.inserted[ item.id ];

							return el(
								'div',
								{ key: item.id, className: 'ils-item' + ( done ? ' is-done' : '' ) },
								el( Title, { item: item } ),
								el( Sentence, { item: item } ),
								el(
									'div',
									{ className: 'ils-item-foot' },
									el( Strength, { item: item } ),
									el( 'span', { className: 'ils-reason' }, item.reason ),
									el(
										'span',
										{ className: 'ils-item-actions' },
										done
											? el( 'span', { className: 'ils-done' }, el( Icon, { name: 'circle-check' } ), i18n.inserted )
											: el(
													Button,
													{
														icon: 'link',
														onClick: function () {
															insert( item );
														},
													},
													i18n.insert
											  )
									)
								),
								out.failed[ item.id ] ? el( 'p', { className: 'ils-error' }, i18n.notFound ) : null
							);
						} )
				  )
				: el( Note, { icon: 'sparkles' }, i18n.noneOut ),
			out.linked ? el( 'p', { className: 'ils-muted ils-small' }, sprintf( i18n.linkedCount, out.linked ) ) : null
		);
	}

	// Link here ------------------------------------------------------------------

	function InTab() {
		var state = useState( { status: 'loading', items: [] } );
		var data = state[ 0 ];
		var setData = state[ 1 ];
		var request = useRef( 0 );
		var saved = useSelect( function ( select ) {
			var editor = select( 'core/editor' );

			return {
				status: editor.getCurrentPostAttribute( 'status' ),
				password: editor.getCurrentPostAttribute( 'password' ),
			};
		}, [] );

		function load() {
			var editor = wp.data.select( 'core/editor' );
			var id = ++request.current;

			setData( function ( previous ) {
				return Object.assign( {}, previous, { status: 'loading' } );
			} );

			ILS.request( '/inbound', {
				post_id: config.postId,
				title: editor.getEditedPostAttribute( 'title' ) || '',
				content: editor.getEditedPostContent(),
				keywords: keywordsText,
			} ).then(
				function ( response ) {
					if ( id === request.current ) {
						setData( { status: 'done', items: response.items || [], existing: response.existing || 0, linkable: !! response.linkable } );
					}
				},
				function ( error ) {
					if ( id === request.current ) {
						setData( { status: 'error', error: ILS.errorText( error ), items: [] } );
					}
				}
			);
		}

		useEffect( load, [ saved.status ] );

		function update( id, changes ) {
			setData( function ( previous ) {
				return Object.assign( {}, previous, {
					items: previous.items.map( function ( item ) {
						return item.id === id ? Object.assign( {}, item, changes ) : item;
					} ),
				} );
			} );
		}

		function add( item ) {
			update( item.id, { busy: true, error: '' } );
			ILS.request( '/link', { source_id: item.id, target_id: config.postId, anchor: item.anchor, context: item.context } ).then(
				function ( response ) {
					update( item.id, { busy: false, log: response.log, undone: false } );
				},
				function ( error ) {
					update( item.id, { busy: false, error: ILS.errorText( error ) } );
				}
			);
		}

		function undo( item ) {
			update( item.id, { busy: true, error: '' } );
			ILS.request( '/undo', { log: item.log } ).then(
				function () {
					update( item.id, { busy: false, log: 0, undone: true } );
				},
				function ( error ) {
					update( item.id, { busy: false, error: ILS.errorText( error ) } );
				}
			);
		}

		if ( data.status === 'loading' && ! data.items.length ) {
			return el( Spinner, { text: i18n.loading } );
		}

		if ( data.status === 'error' ) {
			return el( Fragment, null, el( Note, { type: 'error' }, data.error ), el( Button, { onClick: load, icon: 'refresh-cw' }, i18n.refresh ) );
		}

		var published = saved.status === 'publish' && ! saved.password;

		function actions( item ) {
			if ( item.log ) {
				return el(
					'span',
					{ className: 'ils-item-actions' },
					el( 'span', { className: 'ils-done' }, el( Icon, { name: 'circle-check' } ), i18n.added ),
					el(
						'button',
						{
							type: 'button',
							className: 'components-button is-link ils-undo',
							disabled: item.busy,
							onClick: function () {
								undo( item );
							},
						},
						i18n.undo
					)
				);
			}

			if ( published && data.linkable && item.canAdd ) {
				return el(
					'span',
					{ className: 'ils-item-actions' },
					item.undone ? el( 'span', { className: 'ils-muted' }, i18n.undone ) : null,
					el(
						Button,
						{
							icon: 'plus',
							disabled: item.busy,
							onClick: function () {
								add( item );
							},
						},
						item.busy ? i18n.adding : i18n.add
					)
				);
			}

			return el(
				'span',
				{ className: 'ils-item-actions' },
				published && item.blocked ? el( 'span', { className: 'ils-muted ils-blocked' }, item.blocked ) : null,
				item.edit && ILS.safeUrl( item.edit ) ? el( 'a', { className: 'components-button is-small is-compact is-tertiary ils-btn', href: item.edit, target: '_blank', rel: 'noopener noreferrer' }, el( Icon, { name: 'square-pen' } ), i18n.open ) : null
			);
		}

		return el(
			Fragment,
			null,
			el( 'p', { className: 'ils-intro' }, i18n.inIntro ),
			! published ? el( Note, { type: 'warning' }, i18n.publishFirst ) : null,
			published && ! data.linkable ? el( Note, { type: 'warning' }, i18n.excludedNote ) : null,
			el(
				'div',
				{ className: 'ils-toolbar' },
				el( Button, { onClick: load, icon: 'refresh-cw', disabled: data.status === 'loading' }, i18n.refresh ),
				data.status === 'loading' ? el( Icon, { name: 'loader-circle', className: 'ils-spin' } ) : null
			),
			data.items.length
				? el(
						'div',
						{ className: 'ils-list' },
						data.items.map( function ( item ) {
							return el(
								'div',
								{ key: item.id, className: 'ils-item' + ( item.log ? ' is-done' : '' ) },
								el( Title, { item: item } ),
								el( Sentence, { item: item } ),
								el( 'div', { className: 'ils-item-foot' }, el( Strength, { item: item } ), el( 'span', { className: 'ils-reason' }, item.reason ), actions( item ) ),
								item.error ? el( 'p', { className: 'ils-error' }, item.error ) : null
							);
						} )
				  )
				: el( Note, { icon: 'sparkles' }, i18n.noneIn ),
			data.existing ? el( 'p', { className: 'ils-muted ils-small' }, sprintf( i18n.existingCount, data.existing ) ) : null
		);
	}

	// Keywords and exclude -------------------------------------------------------------

	function PostSettings() {
		var textState = useState( keywordsText );
		var excludedState = useState( !! config.excluded );
		var savingState = useState( '' );
		var timer = useRef( null );
		var setSaving = savingState[ 1 ];

		function save( data ) {
			setSaving( 'saving' );
			ILS.request( '/post-settings', Object.assign( { post_id: config.postId }, data ) ).then(
				function () {
					setSaving( 'saved' );
				},
				function () {
					setSaving( 'error' );
				}
			);
		}

		useEffect( function () {
			return function () {
				window.clearTimeout( timer.current );
			};
		}, [] );

		var labels = { saving: i18n.saving, saved: i18n.saved, error: i18n.error };

		return el(
			components.PanelBody,
			{ title: i18n.keywords, initialOpen: !! keywordsText, className: 'ils-settings' },
			el( components.TextareaControl, {
				label: i18n.keywords,
				hideLabelFromVision: true,
				help: i18n.keywordsHelp,
				value: textState[ 0 ],
				rows: 3,
				onChange: function ( value ) {
					keywordsText = value;
					textState[ 1 ]( value );
					window.clearTimeout( timer.current );
					timer.current = window.setTimeout( function () {
						save( { keywords: value } );
					}, 900 );
				},
			} ),
			config.seoKeywords && config.seoKeywords.length
				? el(
						'div',
						{ className: 'ils-seo' },
						el( 'span', { className: 'ils-muted' }, i18n.seoKeywords ),
						config.seoKeywords.map( function ( keyword ) {
							return el( 'span', { key: keyword, className: 'ils-chip' }, el( Icon, { name: 'tag' } ), keyword );
						} )
				  )
				: null,
			el( components.CheckboxControl, {
				label: i18n.exclude,
				help: i18n.excludeHelp,
				checked: excludedState[ 0 ],
				onChange: function ( value ) {
					excludedState[ 1 ]( value );
					config.excluded = value;
					save( { excluded: value } );
				},
			} ),
			savingState[ 0 ] ? el( 'p', { className: 'ils-save-state is-' + savingState[ 0 ] }, labels[ savingState[ 0 ] ] ) : null
		);
	}

	// Sidebar --------------------------------------------------------------------

	function Sidebar() {
		var tabState = useState( 'out' );
		var tab = tabState[ 0 ];
		var out = useOut();
		var count = out && out.items ? out.items.length : 0;

		function tabButton( id, label, icon, badge ) {
			return el(
				'button',
				{
					type: 'button',
					role: 'tab',
					'aria-selected': tab === id ? 'true' : 'false',
					className: 'ils-seg-tab' + ( tab === id ? ' is-active' : '' ),
					onClick: function () {
						tabState[ 1 ]( id );
					},
				},
				el( Icon, { name: icon } ),
				label,
				badge ? el( 'span', { className: 'ils-tab-count' }, badge ) : null
			);
		}

		return el(
			'div',
			{ className: 'ils-panel ils-sidebar' },
			el( 'div', { className: 'ils-seg', role: 'tablist' }, tabButton( 'out', i18n.linkTo, 'arrow-up-right', count ), tabButton( 'in', i18n.linkHere, 'arrow-down-left', 0 ) ),
			el( 'div', { className: 'ils-tab-body', role: 'tabpanel' }, tab === 'out' ? el( OutTab ) : el( InTab ) ),
			el( PostSettings ),
			config.reportUrl ? el( 'p', { className: 'ils-report-link' }, el( 'a', { href: config.reportUrl, target: '_blank', rel: 'noopener noreferrer' }, el( Icon, { name: 'unlink' } ), i18n.report ) ) : null
		);
	}

	function openSidebar() {
		[ 'core/editor', 'core/edit-post' ].forEach( function ( name ) {
			var store = wp.data.dispatch( name );
			if ( store && store.closePublishSidebar ) {
				store.closePublishSidebar();
			}
		} );

		var editPostStore = wp.data.dispatch( 'core/edit-post' );
		if ( editPostStore && editPostStore.openGeneralSidebar ) {
			editPostStore.openGeneralSidebar( NAME + '/sidebar' );
		}
	}

	function PrePublishBody() {
		var out = useOut();

		useEffect( fetchOut, [] );

		if ( ! out || out.status === 'loading' ) {
			return el( Spinner, { text: i18n.loading } );
		}

		var count = out.items ? out.items.length : 0;

		return el(
			Fragment,
			null,
			el( 'p', null, count ? sprintf( i18n.prePublishSome, count ) : i18n.prePublishNone ),
			count ? el( Button, { onClick: openSidebar, icon: 'waypoints' }, i18n.review ) : null
		);
	}

	var toolbarIcon = el( Icon, { name: 'waypoints', className: 'ils-toolbar-icon' } );

	wp.plugins.registerPlugin( NAME, {
		icon: toolbarIcon,
		render: function () {
			return el(
				Fragment,
				null,
				PluginSidebarMoreMenuItem ? el( PluginSidebarMoreMenuItem, { target: 'sidebar', icon: toolbarIcon }, i18n.title ) : null,
				el( PluginSidebar, { name: 'sidebar', title: i18n.title, icon: toolbarIcon }, el( Sidebar ) ),
				PluginPrePublishPanel ? el( PluginPrePublishPanel, { title: i18n.prePublish, initialOpen: true, className: 'ils-prepublish' }, el( PrePublishBody ) ) : null
			);
		},
	} );
} )( window.wp, window.InternalLinkSuggestions );
