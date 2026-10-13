/**
 * Кнопка «Показать на DE» / «Скрыть на DE» в админ-панели сайта.
 *
 * Дёргает тот же REST-маршрут, что и переключатели в админке, и перезагружает
 * страницу, когда сервер подтвердил новое состояние. Тексты ставятся через
 * textContent.
 */
( function () {
	'use strict';

	var config = window.mlpAdminBarVisibility;

	if ( ! config || ! config.url || ! config.nodeId ) {
		return;
	}

	var busy = false;

	document.addEventListener( 'click', function ( event ) {
		var node = document.getElementById( 'wp-admin-bar-' + config.nodeId );

		if ( ! node || ! event.target || ! node.contains( event.target ) ) {
			return;
		}

		event.preventDefault();

		if ( busy ) {
			return;
		}

		var link = node.querySelector( 'a' );
		var original = link ? link.textContent : '';

		/**
		 * Показывает текст на кнопке.
		 *
		 * @param {string} text Текст.
		 */
		function say( text ) {
			if ( link ) {
				link.textContent = text;
			}
		}

		busy = true;
		say( config.i18n.saving );

		fetch( config.url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce
			},
			body: JSON.stringify( {
				ids: [ config.postId ],
				locale: config.locale,
				visible: ! config.visible
			} )
		} )
			.then( function ( response ) {
				return response.json().catch( function () {
					return null;
				} ).then( function ( data ) {
					return { ok: response.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				if ( ! result.ok || ! result.data || ! Array.isArray( result.data.states ) ) {
					var message = result.data && 'string' === typeof result.data.message ? result.data.message : config.i18n.failed;

					say( message );
					busy = false;

					window.setTimeout( function () {
						say( original );
					}, 4000 );

					return;
				}

				window.location.reload();
			} )
			.catch( function () {
				say( config.i18n.failed );
				busy = false;

				window.setTimeout( function () {
					say( original );
				}, 4000 );
			} );
	} );
}() );
