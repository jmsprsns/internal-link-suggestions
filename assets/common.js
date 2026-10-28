/**
 * Shared by the editor panels and the report page: REST requests, the
 * suggestion cards, and wrapping a link around words without touching the
 * rest of the markup.
 *
 * Everything from the server is put on the page as text nodes, never as
 * HTML. The only markup inserted is the bundled SVG icons that PHP passes in
 * internal_link_suggestions.icons.
 */

/* global wp, internal_link_suggestions */
( function ( window, document ) {
	'use strict';

	var config = window.internal_link_suggestions || {};
	var i18n = config.i18n || {};

	// Elements whose text never gets a link (mirrors Text::SKIP_TAGS in PHP).
	var SKIP = /^(A|H[1-6]|CODE|PRE|KBD|SAMP|VAR|SCRIPT|STYLE|TEXTAREA|BUTTON|SELECT|OPTION|LABEL|SVG|MATH|IFRAME|OBJECT|VIDEO|AUDIO|NOSCRIPT|TEMPLATE|CITE|TITLE|FIGCAPTION)$/;
	var BLOCK = /^(P|DIV|LI|UL|OL|DL|DT|DD|BLOCKQUOTE|TABLE|TR|TD|TH|SECTION|ARTICLE|FIGURE|DETAILS|SUMMARY|ADDRESS|BODY)$/;

	function collapse( text ) {
		return String( text || '' ).replace( /[\s ]+/g, ' ' ).trim();
	}

	function escapeRegExp( text ) {
		return text.replace( /[.*+?^${}()|[\]\\/]/g, '\\$&' );
	}

	function safeUrl( url ) {
		return /^https?:\/\//i.test( String( url || '' ) );
	}

	function escAttr( text ) {
		return String( text ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	}

	// The anchor's words as whole words, any whitespace between them
	// (mirrors Text::anchor_pattern() in PHP). Group 2 is the anchor.
	function anchorRegExp( anchor ) {
		var words = collapse( anchor ).split( ' ' ).filter( Boolean );

		if ( ! words.length ) {
			return null;
		}

		var body = words.map( escapeRegExp ).join( '[\\s\\u00a0]+' );

		try {
			return new RegExp( '(^|[^\\p{L}\\p{N}])(' + body + ')(?![\\p{L}\\p{N}])', 'u' );
		} catch ( e ) {
			return new RegExp( '(^|[^A-Za-z0-9\\u00C0-\\uFFFF])(' + body + ')(?![A-Za-z0-9\\u00C0-\\uFFFF])' );
		}
	}

	function skipped( node, root ) {
		for ( var n = node.parentNode; n && n !== root; n = n.parentNode ) {
			if ( n.nodeType === 1 && SKIP.test( n.nodeName.toUpperCase() ) ) {
				return true;
			}
		}

		return false;
	}

	function blockOf( node, root ) {
		for ( var n = node.parentNode; n && n !== root; n = n.parentNode ) {
			if ( n.nodeType === 1 && BLOCK.test( n.nodeName.toUpperCase() ) ) {
				return n;
			}
		}

		return root;
	}

	/**
	 * Wrap a link around the first place under root where the anchor's words
	 * sit in one text node that can take a link, preferring a paragraph that
	 * holds the suggestion's sentence.
	 *
	 * @param {Node}   root    Element to search.
	 * @param {string} anchor  Words to link.
	 * @param {string} url     Link (http or https only).
	 * @param {string} context Sentence the words were suggested in.
	 * @return {Element|null} The new link.
	 */
	function wrapNode( root, anchor, url, context ) {
		var re = anchorRegExp( anchor );

		if ( ! re || ! safeUrl( url ) ) {
			return null;
		}

		var doc = root.ownerDocument;
		var walker = doc.createTreeWalker( root, 4, null, false );
		var want = collapse( context );
		var first = null;
		var preferred = null;
		var node;

		while ( ( node = walker.nextNode() ) ) {
			if ( skipped( node, root ) ) {
				continue;
			}

			var m = re.exec( node.nodeValue );

			if ( ! m ) {
				continue;
			}

			var hit = {
				node: node,
				start: m.index + m[ 1 ].length,
				end: m.index + m[ 1 ].length + m[ 2 ].length,
			};

			if ( want && collapse( blockOf( node, root ).textContent ).indexOf( want ) !== -1 ) {
				preferred = hit;
				break;
			}

			if ( ! first ) {
				first = hit;
			}
		}

		var place = preferred || first;

		if ( ! place ) {
			return null;
		}

		var range = doc.createRange();
		range.setStart( place.node, place.start );
		range.setEnd( place.node, place.end );

		var link = doc.createElement( 'a' );
		link.setAttribute( 'href', url );
		range.surroundContents( link );

		return link;
	}

	// An inert document: nothing in it loads or runs.
	function inertBox( html ) {
		var doc = document.implementation.createHTMLDocument( '' );
		var box = doc.createElement( 'div' );
		box.innerHTML = html;

		return box;
	}

	/**
	 * Wrap a link inside an HTML string (a block's content).
	 *
	 * @return {string|null} New HTML, or null if the words aren't there.
	 */
	function wrapHtml( html, anchor, url, context ) {
		var box = inertBox( html );

		return wrapNode( box, anchor, url, context ) ? box.innerHTML : null;
	}

	function textOf( html ) {
		return collapse( inertBox( html ).textContent );
	}

	/**
	 * Wrap a link inside raw HTML as typed (the classic editor's Text tab),
	 * changing nothing else in it.
	 *
	 * @return {string|null} New HTML, or null if the words aren't there.
	 */
	function wrapRaw( raw, anchor, url ) {
		var words = collapse( anchor ).split( ' ' ).filter( Boolean );

		if ( ! words.length || ! safeUrl( url ) ) {
			return null;
		}

		var part = function ( word ) {
			return escapeRegExp( word ).replace( /&/g, '(?:&amp;|&)' ).replace( /['’]/g, "(?:'|’|&#8217;|&rsquo;|&#0?39;)" );
		};
		var re = new RegExp( '(^|[^A-Za-z0-9\\u00C0-\\uFFFF])(' + words.map( part ).join( '(?:[\\s\\u00a0]|&nbsp;)+' ) + ')(?![A-Za-z0-9\\u00C0-\\uFFFF])' );
		var tokens = String( raw ).split( /(<!--[\s\S]*?-->|<\/?[a-zA-Z][^>"']*(?:(?:"[^"]*"|'[^']*')[^>"']*)*>|\[\/?[a-zA-Z_][\w-]*(?:[\s/][^[\]]*)?\])/ );
		var skip = [];
		var done = false;

		for ( var i = 0; i < tokens.length; i++ ) {
			var token = tokens[ i ];

			if ( i % 2 === 1 ) {
				var tag = /^<(\/?)([a-zA-Z][a-zA-Z0-9-]*)/.exec( token );

				if ( tag && SKIP.test( tag[ 2 ].toUpperCase() ) ) {
					var name = tag[ 2 ].toLowerCase();

					if ( tag[ 1 ] ) {
						var at = skip.lastIndexOf( name );
						if ( at !== -1 ) {
							skip.length = at;
						}
					} else if ( ! /\/\s*>$/.test( token ) ) {
						skip.push( name );
					}
				}
				continue;
			}

			if ( done || skip.length ) {
				continue;
			}

			var m = re.exec( token );

			if ( m ) {
				var start = m.index + m[ 1 ].length;
				tokens[ i ] = token.slice( 0, start ) + '<a href="' + escAttr( url ) + '">' + m[ 2 ] + '</a>' + token.slice( start + m[ 2 ].length );
				done = true;
			}
		}

		return done ? tokens.join( '' ) : null;
	}

	// Requests -------------------------------------------------------------------

	function request( path, data, method ) {
		return wp.apiFetch( {
			path: '/' + config.namespace + path,
			method: method || 'POST',
			data: data,
		} );
	}

	function errorText( error ) {
		return ( error && error.message ) || i18n.error || 'Error';
	}

	// DOM ------------------------------------------------------------------------

	/**
	 * Build an element. Strings in children become text nodes.
	 */
	function el( tag, attrs, children ) {
		var node = document.createElement( tag );

		Object.keys( attrs || {} ).forEach( function ( key ) {
			var value = attrs[ key ];

			if ( value === null || value === undefined || value === false ) {
				return;
			}

			if ( key === 'href' && ! safeUrl( value ) && ! /^\/(?!\/)/.test( String( value ) ) ) {
				return;
			}

			if ( key === 'className' ) {
				node.className = value;
			} else if ( key === 'text' ) {
				node.textContent = value;
			} else if ( key.indexOf( 'on' ) === 0 && typeof value === 'function' ) {
				node.addEventListener( key.slice( 2 ).toLowerCase(), value );
			} else {
				node.setAttribute( key, value === true ? '' : String( value ) );
			}
		} );

		( children || [] ).forEach( function ( child ) {
			if ( child === null || child === undefined || child === false || child === '' ) {
				return;
			}
			node.appendChild( typeof child === 'string' || typeof child === 'number' ? document.createTextNode( String( child ) ) : child );
		} );

		return node;
	}

	// A bundled icon (SVG markup from PHP's icon helper).
	function icon( name, className ) {
		var span = document.createElement( 'span' );
		span.className = 'ils-i' + ( className ? ' ' + className : '' );
		span.setAttribute( 'aria-hidden', 'true' );
		span.innerHTML = ( config.icons && config.icons[ name ] ) || '';

		return span;
	}

	function sentence( item ) {
		return el( 'p', { className: 'ils-sentence' }, [ item.before, el( 'mark', {}, [ item.anchor ] ), item.after ] );
	}

	function strength( item ) {
		return el( 'span', { className: 'ils-strength is-' + item.strength, title: item.reason }, [ el( 'span', { className: 'ils-dot' } ), i18n[ item.strength ] || item.strength ] );
	}

	/**
	 * Cards for older posts that could link to targetId, each with Add link.
	 *
	 * @param {Element} container Where to draw them.
	 * @param {Array}   items     From the /inbound route.
	 * @param {Object}  options   targetId, canAdd (bool), onAdded( item, log ).
	 */
	function renderInbound( container, items, options ) {
		container.textContent = '';

		items.forEach( function ( item ) {
			var foot = el( 'div', { className: 'ils-item-foot' }, [ strength( item ), el( 'span', { className: 'ils-reason', text: item.reason } ) ] );
			var actions = el( 'div', { className: 'ils-item-actions' } );

			foot.appendChild( actions );

			var card = el( 'div', { className: 'ils-item' }, [
				el( 'div', { className: 'ils-item-head' }, [
					el( 'a', { className: 'ils-item-title', href: item.url, target: '_blank', rel: 'noopener', text: item.title } ),
					el( 'span', { className: 'ils-item-meta', text: item.type } ),
				] ),
				sentence( item ),
				foot,
			] );

			drawInboundActions( actions, item, options );
			container.appendChild( card );
		} );
	}

	function drawInboundActions( actions, item, options ) {
		actions.textContent = '';

		if ( item.log ) {
			actions.appendChild( el( 'span', { className: 'ils-done' }, [ icon( 'circle-check' ), i18n.added ] ) );
			actions.appendChild(
				el( 'button', {
					type: 'button',
					className: 'button-link ils-undo',
					text: i18n.undo,
					onClick: function ( event ) {
						event.target.disabled = true;
						request( '/undo', { log: item.log } ).then(
							function () {
								item.log = 0;
								item.undone = true;
								drawInboundActions( actions, item, options );
							},
							function ( error ) {
								event.target.disabled = false;
								window.alert( errorText( error ) ); // eslint-disable-line no-alert
							}
						);
					},
				} )
			);
			return;
		}

		if ( item.canAdd && options.canAdd !== false ) {
			var button = el(
				'button',
				{
					type: 'button',
					className: 'button button-small ils-add',
					onClick: function () {
						button.disabled = true;
						button.textContent = i18n.adding;
						request( '/link', {
							source_id: item.id,
							target_id: options.targetId,
							anchor: item.anchor,
							context: item.context,
						} ).then(
							function ( response ) {
								item.log = response.log;
								drawInboundActions( actions, item, options );
								if ( options.onAdded ) {
									options.onAdded( item, response.log );
								}
							},
							function ( error ) {
								button.disabled = false;
								button.textContent = i18n.add;
								actions.appendChild( el( 'span', { className: 'ils-error', text: errorText( error ) } ) );
							}
						);
					},
				},
				[ icon( 'plus' ), i18n.add ]
			);

			if ( item.undone ) {
				actions.appendChild( el( 'span', { className: 'ils-muted', text: i18n.undone } ) );
			}
			actions.appendChild( button );
			return;
		}

		if ( item.blocked ) {
			actions.appendChild( el( 'span', { className: 'ils-muted ils-blocked', text: item.blocked } ) );
		}

		if ( item.edit ) {
			actions.appendChild( el( 'a', { className: 'button button-small', href: item.edit, target: '_blank', rel: 'noopener' }, [ icon( 'square-pen' ), i18n.open ] ) );
		}
	}

	window.InternalLinkSuggestions = {
		config: config,
		i18n: i18n,
		collapse: collapse,
		safeUrl: safeUrl,
		wrapNode: wrapNode,
		wrapHtml: wrapHtml,
		wrapRaw: wrapRaw,
		textOf: textOf,
		request: request,
		errorText: errorText,
		el: el,
		icon: icon,
		sentence: sentence,
		strength: strength,
		renderInbound: renderInbound,
	};
} )( window, document );
