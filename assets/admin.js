/**
 * Сохранение переводов на странице «Перевод строк».
 *
 * Ванильный fetch без зависимостей: экран целиком заменится визуальным
 * редактором на Этапе 2, вкладывать в него сборщик смысла нет.
 */
( function () {
	'use strict';

	var settings = window.wpMlpAdmin;

	/*
	 * Раньше здесь был молчаливый return. Если данные не долетели до скрипта
	 * (не сработал wp_localize_script, конфликт имён, скрипт подключён мимо
	 * WordPress), страница выглядела рабочей, а кнопки не делали ничего и
	 * не оставляли следов — ни в Console, ни в Network. Теперь причина видна.
	 */
	if ( ! settings || ! settings.root ) {
		if ( window.console ) {
			window.console.error(
				'[wp-mlp] Скрипт загружен, но данные wpMlpAdmin отсутствуют — кнопки работать не будут.'
			);
		}

		return;
	}

	if ( window.console ) {
		window.console.info( '[wp-mlp] admin.js готов, REST: ' + settings.root );
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

	/**
	 * Удаляет перевод строки.
	 *
	 * @param {HTMLButtonElement} button Нажатая кнопка.
	 */
	function remove( button ) {
		var row = button.closest( 'tr' );
		var input = row ? row.querySelector( '.wp-mlp-input' ) : null;

		if ( ! row || ! input || ! window.confirm( settings.i18n.confirmDelete ) ) {
			return;
		}

		var sourceId = input.getAttribute( 'data-source-id' );
		var locale = input.getAttribute( 'data-locale' );

		button.disabled = true;
		setStatus( row, settings.i18n.deleting, 'saving' );

		fetch( settings.root + encodeURIComponent( sourceId ) + '/' + encodeURIComponent( locale ), {
			method: 'DELETE',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': settings.nonce }
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}

				return response.json();
			} )
			.then( function ( data ) {
				input.value = '';
				setStatus( row, data.status_label, data.status );
				button.remove();
			} )
			.catch( function () {
				setStatus( row, settings.i18n.failed, 'failed' );
				button.disabled = false;
			} );
	}

	/**
	 * Просит ИИ перевести строку и подставляет результат в поле.
	 *
	 * Не сохраняет: перевод нужно проверить и нажать «Сохранить» самому,
	 * ничего не отправляется в базу автоматически.
	 *
	 * @param {HTMLButtonElement} button Нажатая кнопка.
	 */
	function translate( button ) {
		var row = button.closest( 'tr' );
		var input = row ? row.querySelector( '.wp-mlp-input' ) : null;

		if ( ! row || ! input ) {
			return;
		}

		var sourceId = input.getAttribute( 'data-source-id' );
		var locale = input.getAttribute( 'data-locale' );

		button.disabled = true;
		setStatus( row, settings.i18n.translating, 'saving' );

		fetch( settings.root + encodeURIComponent( sourceId ) + '/' + encodeURIComponent( locale ) + '/suggest', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': settings.nonce }
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					return response.json().then( function ( data ) {
						throw new Error( data.message || 'HTTP ' + response.status );
					} );
				}

				return response.json();
			} )
			.then( function ( data ) {
				input.value = data.suggested_text;
				setStatus( row, settings.i18n.aiSuggested, 'machine' );
				input.focus();
			} )
			.catch( function ( error ) {
				setStatus( row, error.message || settings.i18n.aiFailed, 'failed' );
			} )
			.finally( function () {
				button.disabled = false;
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		// Цель клика не всегда элемент (текстовый узел, сам документ) —
		// без этой проверки closest() бросил бы исключение.
		if ( ! ( event.target instanceof Element ) ) {
			return;
		}

		var save_button = event.target.closest( '.wp-mlp-save' );

		if ( save_button ) {
			event.preventDefault();
			save( save_button );

			return;
		}

		var translate_button = event.target.closest( '.wp-mlp-translate' );

		if ( translate_button ) {
			event.preventDefault();
			translate( translate_button );

			return;
		}

		var delete_button = event.target.closest( '.wp-mlp-delete' );

		if ( delete_button ) {
			event.preventDefault();
			remove( delete_button );

			return;
		}

		// Массовое удаление необратимо, поэтому спрашиваем до отправки формы.
		var confirm_button = event.target.closest( '[data-mlp-confirm]' );

		if ( confirm_button && ! window.confirm( confirm_button.getAttribute( 'data-mlp-confirm' ) ) ) {
			event.preventDefault();
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
