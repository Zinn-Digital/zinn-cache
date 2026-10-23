/**
 * Zinn® Cache status screen: purge addresses, check what is caching, start or stop the paced refresh.
 *
 * Loaded on the Zinn® Cache screen only. Every request goes to the plugin's REST routes with the
 * signed-in administrator's nonce; the routes check `manage_options` themselves.
 *
 * @package Zinn\Cache
 */
( function () {
	'use strict';

	var box = document.querySelector( '[data-zinn-cache-tools]' );
	var t = window.zinnCacheStatus || {};
	if ( ! box || ! window.fetch ) {
		return;
	}
	var rest = box.getAttribute( 'data-rest' );
	var nonce = box.getAttribute( 'data-nonce' );

	function el( tag, attrs, text ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			node.setAttribute( k, attrs[ k ] );
		} );
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	function call( path, body ) {
		return fetch( rest + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
			body: JSON.stringify( body || {} )
		} ).then( function ( r ) {
			return r.json().then( function ( json ) {
				if ( ! r.ok ) {
					throw new Error( ( json && json.message ) || ( t.failed + ' (HTTP ' + r.status + ')' ) );
				}
				return json;
			} );
		} );
	}

	function results( out, layers ) {
		out.textContent = '';
		var list = el( 'ul' );
		Object.keys( layers || {} ).forEach( function ( layer ) {
			var r = layers[ layer ];
			var line = layer + ': ' + ( r.ok || 0 ) + ' ' + t.ok;
			if ( r.queued ) {
				line += ', ' + r.queued + ' ' + t.queued;
			}
			if ( r.failed ) {
				line += ', ' + r.failed + ' ' + t.failedCount + ( r.error ? ' — ' + r.error : '' );
			}
			list.appendChild( el( 'li', {}, line ) );
		} );
		out.appendChild( list.childNodes.length ? list : el( 'p', {}, t.nothing ) );
	}

	function section( title, help ) {
		var wrap = el( 'div', { 'class': 'zinn-cache-tool' } );
		wrap.appendChild( el( 'h2', {}, title ) );
		if ( help ) {
			wrap.appendChild( el( 'p', { 'class': 'description' }, help ) );
		}
		box.appendChild( wrap );
		return wrap;
	}

	function busy( button, on ) {
		button.disabled = on;
		button.setAttribute( 'aria-busy', on ? 'true' : 'false' );
	}

	// 1. Purge addresses.
	var purge = section( t.purgeTitle, t.purgeHelp );
	var label = el( 'label', { 'for': 'zinn-cache-purge-urls' }, t.purgeLabel );
	var area = el( 'textarea', { id: 'zinn-cache-purge-urls', rows: '4', 'class': 'large-text code', placeholder: '/about/' } );
	var go = el( 'button', { type: 'button', 'class': 'button button-primary' }, t.purgeButton );
	var out = el( 'div', { 'aria-live': 'polite' } );
	purge.appendChild( label );
	purge.appendChild( area );
	purge.appendChild( el( 'p' ) ).appendChild( go );
	purge.appendChild( out );
	go.addEventListener( 'click', function () {
		var urls = area.value.split( /\r?\n/ ).map( function ( s ) {
			return s.trim();
		} ).filter( Boolean );
		if ( ! urls.length ) {
			return;
		}
		busy( go, true );
		out.textContent = t.working;
		call( 'purge-urls', { urls: urls } ).then( function ( json ) {
			results( out, json.results );
		} ).catch( function ( e ) {
			out.textContent = e.message;
		} ).then( function () {
			busy( go, false );
		} );
	} );

	// 2. Check what is caching (and a hit sample).
	var check = section( t.checkTitle, t.checkHelp );
	var checkBtn = el( 'button', { type: 'button', 'class': 'button' }, t.checkButton );
	var checkOut = el( 'div', { 'aria-live': 'polite' } );
	check.appendChild( el( 'p' ) ).appendChild( checkBtn );
	check.appendChild( checkOut );
	checkBtn.addEventListener( 'click', function () {
		busy( checkBtn, true );
		checkOut.textContent = t.working;
		call( 'probe', { sample: true } ).then( function ( json ) {
			checkOut.textContent = '';
			var s = json.sample || { sampled: 0, blind: 0, layers: {} };
			checkOut.appendChild( el( 'p', {}, t.sampled.replace( '%1$d', s.sampled ).replace( '%2$d', s.blind ) ) );
			var list = el( 'ul' );
			Object.keys( s.layers ).forEach( function ( layer ) {
				var counts = s.layers[ layer ];
				var hits = counts.HIT || 0;
				var parts = Object.keys( counts ).map( function ( k ) {
					return k + ' ' + counts[ k ];
				} );
				list.appendChild( el( 'li', {}, layer + ': ' + Math.round( 100 * hits / Math.max( 1, s.sampled ) ) + '% ' + t.hits + ' (' + parts.join( ', ' ) + ')' ) );
			} );
			checkOut.appendChild( list.childNodes.length ? list : el( 'p', {}, t.noLayers ) );
			checkOut.appendChild( el( 'p', { 'class': 'description' }, t.reload ) );
		} ).catch( function ( e ) {
			checkOut.textContent = e.message;
		} ).then( function () {
			busy( checkBtn, false );
		} );
	} );

	// 3. The paced refresh.
	var refresh = section( t.refreshTitle, t.refreshHelp );
	var start = el( 'button', { type: 'button', 'class': 'button' }, t.refreshStart );
	var stop = el( 'button', { type: 'button', 'class': 'button' }, t.refreshStop );
	var refreshOut = el( 'div', { 'aria-live': 'polite' } );
	var row = el( 'p' );
	row.appendChild( start );
	row.appendChild( document.createTextNode( ' ' ) );
	row.appendChild( stop );
	refresh.appendChild( row );
	refresh.appendChild( refreshOut );
	[ [ start, 'start' ], [ stop, 'stop' ] ].forEach( function ( pair ) {
		pair[ 0 ].addEventListener( 'click', function () {
			busy( pair[ 0 ], true );
			call( 'refresh', { action: pair[ 1 ] } ).then( function ( json ) {
				refreshOut.textContent = json.running ? t.refreshRunning : t.refreshStopped;
			} ).catch( function ( e ) {
				refreshOut.textContent = e.message;
			} ).then( function () {
				busy( pair[ 0 ], false );
			} );
		} );
	} );
}() );
