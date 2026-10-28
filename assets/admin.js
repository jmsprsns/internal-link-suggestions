/**
 * Content Powered > Internal Link Suggestions page behaviour. Only loaded on
 * that page. InternalLinkSuggestions comes from common.js.
 *
 * - Runs the scan while the page is open (WP-Cron can be slow on quiet sites).
 * - Find links: posts that could link to an orphaned post, with Add link.
 * - Link counts: the posts behind each number.
 * - "Not a link target", and Undo on the Added Links tab.
 */

/* global InternalLinkSuggestions */
( function ( window, document, ILS ) {
	'use strict';

	if ( ! ILS || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}

	var el = ILS.el;
	var icon = ILS.icon;
	var i18n = ILS.i18n;

	// Scan -----------------------------------------------------------------------

	function number( value ) {
		return Number( value ).toLocaleString();
	}

	function showProgress( state ) {
		var text = i18n.progress.replace( '%1$s', number( state.done ) ).replace( '%2$s', number( state.total ) );
		var percent = state.total ? Math.min( 100, Math.round( ( 100 * state.done ) / state.total ) ) : 0;
		var box = document.querySelector( '[data-ils-scan]' );
		var cell = document.querySelector( '[data-ils-scan-cell]' );

		if ( box ) {
			box.querySelector( '[data-ils-scan-text]' ).textContent = state.status === 'done' ? i18n.scanDone : text;
			box.querySelector( '.ils-progress' ).setAttribute( 'aria-valuenow', String( percent ) );
			box.querySelector( '.ils-progress > span' ).style.width = ( state.status === 'done' ? 100 : percent ) + '%';
		}

		if ( cell ) {
			cell.textContent = state.status === 'done' ? i18n.scanDone : text;
		}
	}

	function scan( restart ) {
		ILS.request( '/scan', { restart: !! restart } ).then(
			function ( state ) {
				showProgress( state );

				if ( state.status === 'running' ) {
					window.setTimeout( scan, 1200 );
				} else {
					window.setTimeout( function () {
						window.location.reload();
					}, 600 );
				}
			},
			function () {
				window.setTimeout( scan, 5000 );
			}
		);
	}

	if ( ILS.config.scanning ) {
		scan( false );
	}

	var rescan = document.querySelector( '[data-ils-rescan]' );
	if ( rescan ) {
		rescan.addEventListener( 'click', function () {
			rescan.disabled = true;
			rescan.insertBefore( icon( 'loader-circle', 'ils-spin' ), rescan.firstChild );
			scan( true );
		} );
	}

	// Rows below rows ------------------------------------------------------------

	function detailsRow( row, key ) {
		var next = row.nextElementSibling;

		if ( next && next.classList.contains( 'ils-details' ) ) {
			if ( next.getAttribute( 'data-key' ) === key ) {
				return { row: next, existed: true };
			}
			next.parentNode.removeChild( next );
		}

		var inner = el( 'div', { className: 'ils-details-inner' } );
		var cell = el( 'td', { colspan: row.cells.length }, [ inner ] );
		var details = el( 'tr', { className: 'ils-details', 'data-key': key }, [ cell ] );

		row.parentNode.insertBefore( details, row.nextSibling );

		return { row: details, existed: false, inner: inner };
	}

	function closeDetails( row ) {
		var next = row.nextElementSibling;

		if ( next && next.classList.contains( 'ils-details' ) ) {
			next.parentNode.removeChild( next );
		}

		row.classList.remove( 'is-open' );
		Array.prototype.forEach.call( row.querySelectorAll( '[aria-expanded]' ), function ( button ) {
			button.setAttribute( 'aria-expanded', 'false' );
		} );
	}

	function loading( inner ) {
		inner.textContent = '';
		inner.appendChild( el( 'p', { className: 'ils-loading' }, [ icon( 'loader-circle', 'ils-spin' ), i18n.loading ] ) );
	}

	function failed( inner, error ) {
		inner.textContent = '';
		inner.appendChild( el( 'p', { className: 'ils-error', text: ILS.errorText( error ) } ) );
	}

	// Find links (orphaned content) -----------------------------------------------------

	function findLinks( button ) {
		var row = button.closest( 'tr' );
		var postId = Number( button.getAttribute( 'data-ils-find' ) );
		var open = button.getAttribute( 'aria-expanded' ) === 'true';

		if ( open ) {
			closeDetails( row );
			return;
		}

		var details = detailsRow( row, 'find' );
		var inner = details.inner;

		row.classList.add( 'is-open' );
		button.setAttribute( 'aria-expanded', 'true' );
		loading( inner );

		ILS.request( '/inbound', { post_id: postId } ).then(
			function ( response ) {
				inner.textContent = '';
				inner.appendChild( el( 'p', { className: 'ils-details-title' }, [ icon( 'sparkles' ), i18n.findTitle ] ) );

				if ( ! response.items || ! response.items.length ) {
					inner.appendChild( el( 'p', { className: 'ils-note' }, [ icon( 'info' ), el( 'span', { text: i18n.noneIn } ) ] ) );
					return;
				}

				var list = el( 'div', { className: 'ils-list ils-list-wide' } );
				inner.appendChild( list );

				ILS.renderInbound( list, response.items, {
					targetId: postId,
					canAdd: response.linkable,
					onAdded: function () {
						row.classList.add( 'is-linked' );
					},
				} );
			},
			function ( error ) {
				failed( inner, error );
			}
		);
	}

	// Link lists (report) -------------------------------------------------------------

	function linkList( title, links, iconName ) {
		var list = el( 'ul', { className: 'ils-links' } );

		links.forEach( function ( link ) {
			list.appendChild(
				el( 'li', {}, [
					el( 'span', { className: 'ils-links-post' }, [
						link.edit ? el( 'a', { href: link.edit, text: link.title } ) : el( 'span', { text: link.title } ),
						el( 'a', { className: 'ils-view', href: link.url, target: '_blank', rel: 'noopener', 'aria-label': i18n.view }, [ icon( 'external-link' ) ] ),
					] ),
					link.anchor ? el( 'mark', { className: 'ils-anchor', text: link.anchor } ) : el( 'span', { className: 'ils-muted', text: i18n.noAnchor } ),
				] )
			);
		} );

		return el( 'div', { className: 'ils-links-col' }, [ el( 'p', { className: 'ils-details-title' }, [ icon( iconName ), title + ' (' + links.length + ')' ] ), links.length ? list : el( 'p', { className: 'ils-muted', text: '—' } ) ] );
	}

	function showLinks( button ) {
		var row = button.closest( 'tr' );
		var postId = Number( button.getAttribute( 'data-ils-links' ) );
		var open = button.getAttribute( 'aria-expanded' ) === 'true';

		if ( open ) {
			closeDetails( row );
			return;
		}

		var details = detailsRow( row, 'links' );

		Array.prototype.forEach.call( row.querySelectorAll( '[data-ils-links]' ), function ( other ) {
			other.setAttribute( 'aria-expanded', 'true' );
		} );
		row.classList.add( 'is-open' );

		if ( details.existed ) {
			return;
		}

		loading( details.inner );

		ILS.request( '/links?post_id=' + postId, undefined, 'GET' ).then(
			function ( response ) {
				details.inner.textContent = '';
				details.inner.appendChild( el( 'div', { className: 'ils-links-cols' }, [ linkList( i18n.linksIn, response.in || [], 'arrow-down-left' ), linkList( i18n.linksOut, response.out || [], 'arrow-up-right' ) ] ) );
			},
			function ( error ) {
				failed( details.inner, error );
			}
		);
	}

	// Not a link target ---------------------------------------------------------------

	function toggleExclude( button ) {
		var row = button.closest( 'tr' );
		var postId = Number( button.getAttribute( 'data-ils-exclude' ) );
		var excluded = button.getAttribute( 'data-excluded' ) === '1';

		button.disabled = true;

		ILS.request( '/post-settings', { post_id: postId, excluded: ! excluded } ).then(
			function ( response ) {
				button.disabled = false;
				button.setAttribute( 'data-excluded', response.excluded ? '1' : '0' );
				button.textContent = response.excluded ? i18n.excludeOff : i18n.excludeOn;
				row.classList.toggle( 'is-excluded', !! response.excluded );

				var noteText = response.excluded ? i18n.excludedNote : i18n.restoredNote;
				var note = row.querySelector( '.ils-row-note' );

				if ( ! note ) {
					note = el( 'span', { className: 'ils-row-note' } );
					row.querySelector( '.column-title' ).appendChild( note );
				}
				note.textContent = noteText;
			},
			function ( error ) {
				button.disabled = false;
				window.alert( ILS.errorText( error ) ); // eslint-disable-line no-alert
			}
		);
	}

	// Undo (Added Links) ----------------------------------------------------------------

	function undo( button ) {
		var row = button.closest( 'tr' );

		button.disabled = true;

		ILS.request( '/undo', { log: Number( button.getAttribute( 'data-ils-undo' ) ) } ).then(
			function ( response ) {
				row.classList.add( 'is-undone' );
				button.parentNode.replaceChild( el( 'span', { className: 'ils-status', title: response.message, text: i18n.undone } ), button );
			},
			function ( error ) {
				button.disabled = false;
				window.alert( ILS.errorText( error ) ); // eslint-disable-line no-alert
			}
		);
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target.closest( '[data-ils-find],[data-ils-links],[data-ils-exclude],[data-ils-undo]' );

		if ( ! target ) {
			return;
		}

		event.preventDefault();

		if ( target.hasAttribute( 'data-ils-find' ) ) {
			findLinks( target );
		} else if ( target.hasAttribute( 'data-ils-links' ) ) {
			showLinks( target );
		} else if ( target.hasAttribute( 'data-ils-exclude' ) ) {
			toggleExclude( target );
		} else {
			undo( target );
		}
	} );
} )( window, document, window.InternalLinkSuggestions );
