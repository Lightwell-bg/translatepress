/**
 * Сохранение переводов на странице «Перевод строк».
 *
 * Ванильный fetch без зависимостей: экран целиком заменится визуальным
 * редактором на Этапе 2, вкладывать в него сборщик смысла нет.
 */
( function () {
	'use strict';

	var settings = window.wpMlpAdmin;

	if ( ! settings || ! settings.root ) {
		return;
	}

	/**
	 * Показывает состояние строки в колонке статуса.
	 *
	 * @param {HTMLElement} row   Строка таблицы.
	 * @param {string}      text  Подпись.
	 * @param {string}      state Модификатор класса.
	 */
	function setStatus( row, text, state ) {
		var cell = row.querySelector( '.wp-mlp-status' );

		if ( ! cell ) {
			return;
		}

		cell.textContent = text;
		cell.className = 'wp-mlp-status wp-mlp-status--' + state;
	}

	/**
	 * Отправляет перевод на сервер.
	 *
	 * @param {HTMLButtonElement} button Нажатая кнопка.
	 */
	function save( button ) {
		var row = button.closest( 'tr' );
		var input = row ? row.querySelector( '.wp-mlp-input' ) : null;

		if ( ! row || ! input ) {
			return;
		}

		var sourceId = input.getAttribute( 'data-source-id' );
		var locale = input.getAttribute( 'data-locale' );

		button.disabled = true;
		setStatus( row, settings.i18n.saving, 'saving' );

		fetch( settings.root + encodeURIComponent( sourceId ) + '/' + encodeURIComponent( locale ), {
			method: 'PUT',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': settings.nonce
			},
			body: JSON.stringify( { translated_text: input.value } )
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}

				return response.json();
			} )
			.then( function ( data ) {
				input.value = data.translated_text;
				setStatus( row, data.status_label || settings.i18n.saved, data.status );
			} )
			.catch( function () {
				setStatus( row, settings.i18n.failed, 'failed' );
			} )
			.finally( function () {
				button.disabled = false;
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.wp-mlp-save' );

		if ( button ) {
			event.preventDefault();
			save( button );
		}
	} );

	// Ctrl+Enter в поле перевода сохраняет строку, не отрывая рук от клавиатуры.
	document.addEventListener( 'keydown', function ( event ) {
		if ( ! event.ctrlKey || 'Enter' !== event.key ) {
			return;
		}

		var input = event.target.closest( '.wp-mlp-input' );
		var row = input ? input.closest( 'tr' ) : null;
		var button = row ? row.querySelector( '.wp-mlp-save' ) : null;

		if ( button ) {
			event.preventDefault();
			save( button );
		}
	} );
}() );
