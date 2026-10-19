/**
 * Переключатели «показывать на сайте» (список записей, визуальный редактор).
 *
 * Один обработчик на весь документ: строки списка после быстрого
 * редактирования перерисовываются WordPress, и обработчики, повешенные на
 * сами чекбоксы, пропали бы вместе со старыми строками.
 *
 * Правда — ответ сервера: после запроса чекбокс ставится в то состояние,
 * которое пришло в ответе, а не в то, на которое щёлкнул пользователь (запись
 * могли изменить в соседней вкладке).
 */
( function () {
	'use strict';

	var config = window.lingocanvasVisibility;

	if ( ! config || ! config.url ) {
		return;
	}

	/**
	 * Место для сообщения рядом с переключателем.
	 *
	 * @param {HTMLInputElement} input Чекбокс.
	 * @return {Element|null}
	 */
	function messageBox( input ) {
		var label = input.closest( '.mlp-vis' );

		if ( ! label ) {
			return null;
		}

		var next = label.nextElementSibling;

		return next && next.classList.contains( 'mlp-vis__msg' ) ? next : null;
	}

	/**
	 * Пишет сообщение рядом с переключателем (только текст).
	 *
	 * @param {HTMLInputElement} input Чекбокс.
	 * @param {string}           text  Текст.
	 * @param {boolean}          isError Это ошибка.
	 */
	function say( input, text, isError ) {
		var box = messageBox( input );

		if ( ! box ) {
			return;
		}

		box.textContent = text;
		box.classList.toggle( 'mlp-vis__msg--error', !! isError );
	}

	/**
	 * Текст ошибки из ответа REST.
	 *
	 * @param {*} data Тело ответа.
	 * @return {string}
	 */
	function errorText( data ) {
		if ( data && 'string' === typeof data.message && data.message ) {
			return data.message;
		}

		return config.i18n.failed;
	}

	document.addEventListener( 'change', function ( event ) {
		var input = event.target;

		if ( ! input || ! input.classList || ! input.classList.contains( 'mlp-vis-toggle' ) ) {
			return;
		}

		// Без data-post-id это не строка списка, а поле формы (метабокс сохраняется формой).
		var postId = parseInt( input.getAttribute( 'data-post-id' ), 10 );
		var locale = input.getAttribute( 'data-locale' );

		if ( ! postId || ! locale || 'true' === input.getAttribute( 'data-busy' ) ) {
			return;
		}

		var wanted = input.checked;

		// Пока идёт запрос, повторный щелчок невозможен.
		input.setAttribute( 'data-busy', 'true' );
		input.disabled = true;
		say( input, config.i18n.saving, false );

		fetch( config.url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce
			},
			body: JSON.stringify( { ids: [ postId ], locale: locale, visible: wanted } )
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
					input.checked = ! wanted;
					say( input, errorText( result.data ), true );

					return;
				}

				var state = null;

				result.data.states.forEach( function ( item ) {
					if ( item && item.id === postId ) {
						state = !! item.visible;
					}
				} );

				if ( null !== state ) {
					input.checked = state;
				}

				say( input, '', false );
			} )
			.catch( function () {
				input.checked = ! wanted;
				say( input, config.i18n.network, true );
			} )
			.then( function () {
				input.removeAttribute( 'data-busy' );
				input.disabled = false;
			} );
	} );
}() );
