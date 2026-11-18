/**
 * Runs the Vik Rent Items import in small batches so the web server
 * doesn't time out, and shows progress while it works.
 */
( function () {
	'use strict';

	var cfg = window.taasVriImport;
	var button = document.getElementById( 'taas-vri-start' );
	var box = document.getElementById( 'taas-vri-progress' );
	if ( ! cfg || ! button || ! box ) {
		return;
	}
	var message = box.querySelector( 'p' );
	var MAX_RETRIES = 3;

	function format( text, values ) {
		var i = 0;
		return text.replace( /%(\d\$)?[sd]/g, function ( match, position ) {
			var index = position ? parseInt( position, 10 ) - 1 : i++;
			return String( values[ index ] );
		} );
	}

	function show( text, type ) {
		box.className = 'notice inline notice-' + ( type || 'info' );
		box.hidden = false;
		message.textContent = text;
	}

	function stop( error ) {
		show( format( cfg.i18n.stopped, [ error.message || String( error ) ] ), 'error' );
		button.disabled = false;
	}

	function step( offset, retries ) {
		var body = new FormData();
		body.append( 'action', cfg.action );
		body.append( 'nonce', cfg.nonce );
		body.append( 'offset', offset );

		fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				if ( response.status >= 500 ) {
					throw { retry: true, message: 'HTTP ' + response.status };
				}
				return response.json();
			} )
			.then( function ( result ) {
				if ( ! result || ! result.success ) {
					throw new Error( ( result && result.data && result.data.message ) || 'unknown error' );
				}
				var data = result.data;
				if ( data.next === null ) {
					show( format( cfg.i18n.progress, [ data.total, data.total ] ) );
					window.location.href = cfg.doneUrl;
					return;
				}
				show( format( cfg.i18n.progress, [ data.next, data.total ] ) );
				step( data.next, 0 );
			} )
			.catch( function ( error ) {
				// A timeout or network blip: the batch may have partly finished,
				// and finished items are skipped, so just try the same batch again.
				var transient = error && ( error.retry || error instanceof TypeError );
				if ( transient && retries < MAX_RETRIES ) {
					show( cfg.i18n.retrying );
					window.setTimeout( function () {
						step( offset, retries + 1 );
					}, 3000 );
					return;
				}
				stop( error );
			} );
	}

	button.addEventListener( 'click', function () {
		button.disabled = true;
		show( format( cfg.i18n.progress, [ 0, '…' ] ) );
		step( 0, 0 );
	} );
} )();
