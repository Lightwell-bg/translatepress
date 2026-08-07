/**
 * Панель визуального редактора.
 *
 * Живёт в админке, поэтому только здесь есть REST-nonce: страница в iframe
 * ничего не сохраняет, а лишь сообщает, по какому элементу нажали.
 */
( function () {
	'use strict';

	var settings = window.wpMlpEditor;
	var root = document.querySelector( '.wp-mlp-editor' );

	if ( ! settings || ! root ) {
		if ( window.console ) {
			window.console.error( '[wp-mlp] Редактор: данные wpMlpEditor или разметка панели не найдены.' );
		}

		return;
	}

	var frame = document.getElementById( 'mlp-editor-preview' );
	var form = root.querySelector( '.wp-mlp-editor__form' );
	var hint = root.querySelector( '.wp-mlp-editor__hint' );
	var kindLabel = root.querySelector( '.wp-mlp-editor__kind' );
	var statusLine = root.querySelector( '.wp-mlp-editor__status' );
	var blockBox = root.querySelector( '.wp-mlp-editor__block' );
	var sourceField = document.getElementById( 'mlp-editor-source' );
	var targetField = document.getElementById( 'mlp-editor-target' );
	var statusField = document.getElementById( 'mlp-editor-status' );
	var locale = root.getAttribute( 'data-locale' );

	var current = null;
	var blockHtml = null;

	/**
	 * Показывает короткое сообщение под кнопками.
	 *
	 * @param {string} text  Текст.
	 * @param {string} state Модификатор класса.
	 */
	function say( text, state ) {
		statusLine.textContent = text || '';
		statusLine.className = 'wp-mlp-editor__status' + ( state ? ' is-' + state : '' );
	}

	/**
	 * Название вида строки для заголовка панели.
	 *
	 * @param {Object} item Выбранная строка.
	 * @return {string}
	 */
	function kindTitle( item ) {
		if ( 'attribute' === item.kind ) {
			return settings.i18n.attribute + ': ' + item.attribute;
		}

		return 'html_block' === item.kind ? settings.i18n.htmlBlock : settings.i18n.text;
	}

	/**
	 * Отправляет сообщение в предпросмотр.
	 *
	 * @param {Object} data Полезная нагрузка.
	 */
	function toPreview( data ) {
		if ( frame && frame.contentWindow ) {
			frame.contentWindow.postMessage(
				Object.assign( { source: 'wp-mlp-editor' }, data ),
				window.location.origin
			);
		}
	}

	/**
	 * Загружает строку и её переводы.
	 *
	 * @param {Object} item Выбранная строка.
	 */
	function load( item ) {
		current = item;

		hint.hidden = true;
		form.hidden = false;
		kindLabel.textContent = kindTitle( item );
		say( settings.i18n.loading );

		fetch( settings.sources + encodeURIComponent( item.id ), {
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
				var translation = data.translations[ locale ] || { translated_text: '', status: 'missing' };

				sourceField.value = data.source_text;
				targetField.value = translation.translated_text;
				statusField.value = translation.status;
				current.kind = data.kind;
				kindLabel.textContent = kindTitle( current );
				say( '' );
				targetField.focus();
			} )
			.catch( function () {
				say( settings.i18n.failed, 'error' );
			} );
	}

	/**
	 * Сохраняет перевод.
	 */
	function save() {
		if ( ! current ) {
			return;
		}

		say( settings.i18n.saving );

		fetch( settings.saveRoot + encodeURIComponent( current.id ) + '/' + encodeURIComponent( locale ), {
			method: 'PUT',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': settings.nonce
			},
			body: JSON.stringify( {
				translated_text: targetField.value,
				status: statusField.value
			} )
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}

				return response.json();
			} )
			.then( function ( data ) {
				targetField.value = data.translated_text;
				statusField.value = data.status;
				say( settings.i18n.saved, 'ok' );

				toPreview( {
					type: 'apply',
					id: current.id,
					kind: current.kind,
					attribute: current.attribute,
					text: data.translated_text
				} );
			} )
			.catch( function () {
				say( settings.i18n.failed, 'error' );
			} );
	}

	/**
	 * Просит ИИ перевести выбранную строку и подставляет результат в поле.
	 *
	 * Не сохраняет: перевод остаётся черновиком, пока не нажать «Сохранить».
	 */
	function translateWithAi() {
		if ( ! current ) {
			return;
		}

		say( settings.i18n.translating );

		fetch( settings.saveRoot + encodeURIComponent( current.id ) + '/' + encodeURIComponent( locale ) + '/suggest', {
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
				targetField.value = data.suggested_text;
				statusField.value = data.status;
				say( settings.i18n.aiSuggested, 'ok' );
				targetField.focus();
			} )
			.catch( function ( error ) {
				say( error.message || settings.i18n.aiFailed, 'error' );
			} );
	}

	/**
	 * Удаляет перевод выбранной строки.
	 */
	function remove() {
		if ( ! current || ! window.confirm( settings.i18n.confirmDelete ) ) {
			return;
		}

		say( settings.i18n.saving );

		fetch( settings.saveRoot + encodeURIComponent( current.id ) + '/' + encodeURIComponent( locale ), {
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
			.then( function () {
				targetField.value = '';
				statusField.value = 'missing';
				say( settings.i18n.saved, 'ok' );
				toPreview( { type: 'reload' } );
			} )
			.catch( function () {
				say( settings.i18n.failed, 'error' );
			} );
	}

	/**
	 * Превращает выбранный абзац в translation block.
	 */
	function makeBlock() {
		if ( ! blockHtml || ! window.confirm( settings.i18n.confirmBlock ) ) {
			return;
		}

		say( settings.i18n.saving );

		fetch( settings.blocks, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': settings.nonce
			},
			body: JSON.stringify( { html: blockHtml } )
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}

				return response.json();
			} )
			.then( function () {
				say( settings.i18n.blockCreated, 'ok' );

				// Разбор страницы изменился: блок теперь одна строка вместо кусков.
				toPreview( { type: 'reload' } );
			} )
			.catch( function () {
				say( settings.i18n.failed, 'error' );
			} );
	}

	window.addEventListener( 'message', function ( event ) {
		if ( event.origin !== window.location.origin || ! event.data ) {
			return;
		}

		if ( 'wp-mlp-preview' !== event.data.source ) {
			return;
		}

		if ( 'navigate' === event.data.type ) {
			frame.src = event.data.url;

			return;
		}

		if ( 'select' !== event.data.type || ! event.data.strings.length ) {
			return;
		}

		blockHtml = event.data.blockCandidate ? event.data.blockHtml : null;
		blockBox.hidden = ! blockHtml;

		// У элемента может быть и текст, и alt: берём первую строку,
		// остальные доступны по клику на самом атрибуте.
		load( event.data.strings[ 0 ] );
	} );

	/**
	 * Вешает обработчик, если элемент есть в разметке.
	 *
	 * Раньше три кнопки подключались напрямую через getElementById().
	 * Отсутствие любой из них (её может не быть по условию рендера) роняло
	 * весь скрипт на TypeError — вместе со всеми остальными кнопками.
	 *
	 * @param {string}   id      Идентификатор элемента.
	 * @param {Function} handler Обработчик клика.
	 */
	function onClick( id, handler ) {
		var element = document.getElementById( id );

		if ( element ) {
			element.addEventListener( 'click', handler );
		}
	}

	onClick( 'mlp-editor-save', save );
	onClick( 'mlp-editor-delete', remove );
	onClick( 'mlp-editor-make-block', makeBlock );
	onClick( 'mlp-editor-translate', translateWithAi );

	targetField.addEventListener( 'keydown', function ( event ) {
		if ( event.ctrlKey && 'Enter' === event.key ) {
			event.preventDefault();
			save();
		}
	} );
}() );
