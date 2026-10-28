/**
 * The Internal Link Suggestions box in the classic editor.
 *
 * Same two lists as the block editor's sidebar. Insert link wraps the words
 * in the Visual tab (one step on the editor's Undo) or in the Text tab's
 * HTML, changing nothing else. InternalLinkSuggestions comes from common.js.
 */

/* global InternalLinkSuggestions */
( function ( window, document, ILS ) {
	'use strict';

	var root = document.getElementById( 'ils-classic' );

	if ( ! root || ! ILS || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}

	var el = ILS.el;
	var icon = ILS.icon;
	var i18n = ILS.i18n;
	var config = ILS.config;
	var state = { tab: 'out', out: null, inbound: null, changed: false, inserted: {} };
	var body = el( 'div', { className: 'ils-tab-body' } );
	var tabs = el( 'div', { className: 'ils-seg', role: 'tablist' } );

	function editor() {
		var mce = window.tinymce && window.tinymce.get( 'content' );
		var visual = !! ( mce && ! mce.isHidden() );
		var textarea = document.getElementById( 'content' );
		var title = document.getElementById( 'title' );

		return {
			mce: mce,
			visual: visual,
			textarea: textarea,
			content: visual ? mce.getContent() : textarea ? textarea.value : '',
			title: title ? title.value : '',
		};
	}

	function keywords() {
		var field = document.getElementById( 'ils-keywords' );

		return field ? field.value : ( config.keywords || [] ).join( '\n' );
	}

	function published() {
		var status = document.getElementById( 'original_post_status' );

		return status && status.value === 'publish';
	}

	function insert( item ) {
		var ed = editor();

		if ( ed.visual ) {
			var link = null;

			ed.mce.undoManager.transact( function () {
				link = ILS.wrapNode( ed.mce.getBody(), item.anchor, item.url, item.context );
			} );

			if ( link ) {
				ed.mce.nodeChanged();
				ed.mce.setDirty( true );
				ed.mce.fire( 'change' );
				if ( link.scrollIntoView ) {
					link.scrollIntoView( { block: 'center' } );
				}
				return true;
			}

			return false;
		}

		if ( ed.textarea ) {
			var html = ILS.wrapRaw( ed.textarea.value, item.anchor, item.url );

			if ( html !== null ) {
				ed.textarea.value = html;
				ed.textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				ed.textarea.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				return true;
			}
		}

		return false;
	}

	function note( text, type ) {
		return el( 'p', { className: 'ils-note' + ( type ? ' is-' + type : '' ) }, [ icon( 'info' ), el( 'span', {}, [ text ] ) ] );
	}

	function loading() {
		return el( 'p', { className: 'ils-loading' }, [ icon( 'loader-circle', 'ils-spin' ), i18n.loading ] );
	}

	function button( label, iconName, onClick ) {
		return el( 'button', { type: 'button', className: 'button button-small ils-btn', onClick: onClick }, [ icon( iconName ), label ] );
	}

	function head( item ) {
		return el( 'div', { className: 'ils-item-head' }, [
			el( 'a', { className: 'ils-item-title', href: item.url, target: '_blank', rel: 'noopener', text: item.title } ),
			el( 'span', { className: 'ils-item-meta', text: [ item.type, item.date ].filter( Boolean ).join( ' · ' ) } ),
			item.orphaned ? el( 'span', { className: 'ils-badge', text: i18n.orphaned } ) : null,
		] );
	}

	// Loading --------------------------------------------------------------------

	function loadOut() {
		var ed = editor();

		state.out = { status: 'loading', items: state.out ? state.out.items : [] };
		state.changed = false;
		draw();

		ILS.request( '/outbound', { post_id: config.postId, title: ed.title, content: ed.content, keywords: keywords() } ).then(
			function ( response ) {
				state.out = { status: 'done', items: response.items || [], linked: response.linked || 0, index: response.index || {} };
				state.inserted = {};
				draw();
			},
			function ( error ) {
				state.out = { status: 'error', error: ILS.errorText( error ), items: [] };
				draw();
			}
		);
	}

	function loadIn() {
		var ed = editor();

		state.inbound = { status: 'loading', items: state.inbound ? state.inbound.items : [] };
		draw();

		ILS.request( '/inbound', { post_id: config.postId, title: ed.title, content: ed.content, keywords: keywords() } ).then(
			function ( response ) {
				state.inbound = { status: 'done', items: response.items || [], existing: response.existing || 0, linkable: !! response.linkable };
				draw();
			},
			function ( error ) {
				state.inbound = { status: 'error', error: ILS.errorText( error ), items: [] };
				draw();
			}
		);
	}

	// Drawing --------------------------------------------------------------------

	function drawTabs() {
		var count = state.out && state.out.items ? state.out.items.length : 0;

		tabs.textContent = '';
		[
			[ 'out', i18n.linkTo, 'arrow-up-right', count ],
			[ 'in', i18n.linkHere, 'arrow-down-left', 0 ],
		].forEach( function ( tab ) {
			tabs.appendChild(
				el(
					'button',
					{
						type: 'button',
						role: 'tab',
						'aria-selected': state.tab === tab[ 0 ] ? 'true' : 'false',
						className: 'ils-seg-tab' + ( state.tab === tab[ 0 ] ? ' is-active' : '' ),
						onClick: function () {
							state.tab = tab[ 0 ];
							if ( tab[ 0 ] === 'in' && ! state.inbound ) {
								loadIn();
							} else {
								draw();
							}
						},
					},
					[ icon( tab[ 2 ] ), tab[ 1 ], tab[ 3 ] ? el( 'span', { className: 'ils-tab-count', text: String( tab[ 3 ] ) } ) : null ]
				)
			);
		} );
	}

	function drawOut() {
		var out = state.out;

		body.appendChild( el( 'p', { className: 'ils-intro', text: i18n.outIntro } ) );
		body.appendChild(
			el( 'div', { className: 'ils-toolbar' }, [
				button( i18n.refresh, 'refresh-cw', loadOut ),
				out.status === 'loading' ? icon( 'loader-circle', 'ils-spin' ) : null,
				state.changed && out.status !== 'loading' ? el( 'span', { className: 'ils-changed', text: i18n.changed } ) : null,
			] )
		);

		if ( out.status === 'error' ) {
			body.appendChild( note( out.error, 'error' ) );
			return;
		}

		if ( out.status === 'loading' && ! out.items.length ) {
			body.appendChild( loading() );
			return;
		}

		var index = out.index || {};
		if ( index.scanning ) {
			body.appendChild( note( i18n.scanning ) );
		} else if ( ! index.posts ) {
			body.appendChild( note( i18n.empty ) );
		}

		if ( ! out.items.length ) {
			body.appendChild( note( i18n.noneOut ) );
			return;
		}

		var list = el( 'div', { className: 'ils-list' } );

		out.items.forEach( function ( item ) {
			var actions = el( 'span', { className: 'ils-item-actions' } );
			var error = el( 'p', { className: 'ils-error', hidden: true, text: i18n.notFound } );
			var card = el( 'div', { className: 'ils-item' + ( state.inserted[ item.id ] ? ' is-done' : '' ) }, [
				head( item ),
				ILS.sentence( item ),
				el( 'div', { className: 'ils-item-foot' }, [ ILS.strength( item ), el( 'span', { className: 'ils-reason', text: item.reason } ), actions ] ),
				error,
			] );

			if ( state.inserted[ item.id ] ) {
				actions.appendChild( el( 'span', { className: 'ils-done' }, [ icon( 'circle-check' ), i18n.inserted ] ) );
			} else {
				actions.appendChild(
					button( i18n.insert, 'link', function () {
						if ( insert( item ) ) {
							state.inserted[ item.id ] = true;
							state.changed = false;
							draw();
						} else {
							error.hidden = false;
						}
					} )
				);
			}

			list.appendChild( card );
		} );

		body.appendChild( list );

		if ( out.linked ) {
			body.appendChild( el( 'p', { className: 'ils-muted ils-small', text: i18n.linkedCount.replace( '%d', out.linked ) } ) );
		}
	}

	function drawIn() {
		var data = state.inbound;

		body.appendChild( el( 'p', { className: 'ils-intro', text: i18n.inIntro } ) );

		if ( ! published() ) {
			body.appendChild( note( i18n.publishFirst, 'warning' ) );
		} else if ( data.status === 'done' && ! data.linkable ) {
			body.appendChild( note( i18n.excludedNote, 'warning' ) );
		}

		body.appendChild( el( 'div', { className: 'ils-toolbar' }, [ button( i18n.refresh, 'refresh-cw', loadIn ), data.status === 'loading' ? icon( 'loader-circle', 'ils-spin' ) : null ] ) );

		if ( data.status === 'error' ) {
			body.appendChild( note( data.error, 'error' ) );
			return;
		}

		if ( data.status === 'loading' && ! data.items.length ) {
			body.appendChild( loading() );
			return;
		}

		if ( ! data.items.length ) {
			body.appendChild( note( i18n.noneIn ) );
			return;
		}

		var list = el( 'div', { className: 'ils-list' } );
		ILS.renderInbound( list, data.items, { targetId: config.postId, canAdd: published() && data.linkable } );
		body.appendChild( list );

		if ( data.existing ) {
			body.appendChild( el( 'p', { className: 'ils-muted ils-small', text: i18n.existingCount.replace( '%d', data.existing ) } ) );
		}
	}

	function draw() {
		drawTabs();
		body.textContent = '';

		if ( state.tab === 'in' ) {
			drawIn();
		} else {
			drawOut();
		}
	}

	// Keywords and exclude -------------------------------------------------------------

	function settings() {
		var status = el( 'span', { className: 'ils-save-state' } );
		var timer = null;

		function save( data ) {
			status.textContent = i18n.saving;
			status.className = 'ils-save-state is-saving';
			data.post_id = config.postId;
			ILS.request( '/post-settings', data ).then(
				function () {
					status.textContent = i18n.saved;
					status.className = 'ils-save-state is-saved';
				},
				function () {
					status.textContent = i18n.error;
					status.className = 'ils-save-state is-error';
				}
			);
		}

		var textarea = el( 'textarea', {
			id: 'ils-keywords',
			className: 'widefat',
			rows: 2,
			onInput: function () {
				window.clearTimeout( timer );
				timer = window.setTimeout( function () {
					save( { keywords: textarea.value } );
				}, 900 );
			},
		} );
		textarea.value = ( config.keywords || [] ).join( '\n' );

		var checkbox = el( 'input', {
			type: 'checkbox',
			id: 'ils-exclude',
			onChange: function () {
				save( { excluded: checkbox.checked } );
			},
		} );
		checkbox.checked = !! config.excluded;

		var seo = config.seoKeywords && config.seoKeywords.length
			? el(
					'p',
					{ className: 'ils-seo' },
					[ el( 'span', { className: 'ils-muted', text: i18n.seoKeywords } ) ].concat(
						config.seoKeywords.map( function ( keyword ) {
							return el( 'span', { className: 'ils-chip' }, [ icon( 'tag' ), keyword ] );
						} )
					)
			  )
			: null;

		return el( 'div', { className: 'ils-classic-settings' }, [
			el( 'div', { className: 'ils-field' }, [
				el( 'label', { for: 'ils-keywords', className: 'ils-label', text: i18n.keywords } ),
				textarea,
				el( 'p', { className: 'description', text: i18n.keywordsHelp } ),
				seo,
			] ),
			el( 'div', { className: 'ils-field' }, [
				el( 'label', { className: 'ils-check' }, [ checkbox, el( 'span', { text: i18n.exclude } ) ] ),
				el( 'p', { className: 'description', text: i18n.excludeHelp } ),
				status,
			] ),
		] );
	}

	root.textContent = '';
	root.appendChild( tabs );
	root.appendChild( body );
	root.appendChild( settings() );

	// Note when the draft changes after suggestions were found.
	function markChanged() {
		if ( state.out && state.out.status === 'done' && ! state.changed ) {
			state.changed = true;
			if ( state.tab === 'out' ) {
				draw();
			}
		}
	}

	var textarea = document.getElementById( 'content' );
	if ( textarea ) {
		textarea.addEventListener( 'input', markChanged );
	}

	if ( window.tinymce && window.tinymce.on ) {
		window.tinymce.on( 'AddEditor', function ( event ) {
			if ( event.editor && event.editor.id === 'content' ) {
				event.editor.on( 'input change undo redo', markChanged );
			}
		} );
	}

	var existing = window.tinymce && window.tinymce.get( 'content' );
	if ( existing ) {
		existing.on( 'input change undo redo', markChanged );
	}

	loadOut();
} )( window, document, window.InternalLinkSuggestions );
