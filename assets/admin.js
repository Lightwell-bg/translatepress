/**
 * Сохранение переводов на странице «Перевод строк».
 *
 * Ванильный fetch без зависимостей: экран целиком заменится визуальным
 * редактором на Этапе 2, вкладывать в него сборщик смысла нет.
 */
( function () {
	'use strict';

	var settings = window.lingocanvasAdmin;

	/*
	 * Раньше здесь был молчаливый return. Если данные не долетели до скрипта
	 * (не сработал wp_localize_script, конфликт имён, скрипт подключён мимо
	 * WordPress), страница выглядела рабочей, а кнопки не делали ничего и
	 * не оставляли следов — ни в Console, ни в Network. Теперь причина видна.
	 */
	if ( ! settings || ! settings.root ) {
		if ( window.console ) {
			window.console.error(
				'[lingocanvas] Скрипт загружен, но данные lingocanvasAdmin отсутствуют — кнопки работать не будут.'
			);
		}

		return;
	}

	if ( window.console ) {
		window.console.info( '[lingocanvas] admin.js готов, REST: ' + settings.root );
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
		var confirm_button = event.target.closest( '[data-lingocanvas-confirm]' );

		if ( confirm_button && ! window.confirm( confirm_button.getAttribute( 'data-lingocanvas-confirm' ) ) ) {
			event.preventDefault();
		}
	} );

	/**
	 * Поиск записи для фильтра «Где встречается».
	 *
	 * Записи в списке не перечислены (их могут быть тысячи): при выборе
	 * «Отдельная запись…» показывается поле поиска, а выбранная запись
	 * записывается в значение этого пункта как `object:ID`.
	 */
	( function () {
		var select = document.getElementById( 'mlp-scope' );
		var wrap = document.getElementById( 'mlp-scope-picker' );
		var input = document.getElementById( 'mlp-scope-search' );
		var list = document.getElementById( 'mlp-scope-list' );
		var objectOption = select ? select.querySelector( 'option[data-lingocanvas-object]' ) : null;

		if ( ! select || ! wrap || ! input || ! list || ! objectOption || ! settings.pagesRoot ) {
			return;
		}

		var i18n = settings.i18n || {};
		var timer = 0;
		var requestId = 0;
		var controller = null;
		var options = [];
		var active = -1;
		var chosenTitle = input.value;

		function isObjectMode() {
			return select.options[ select.selectedIndex ] === objectOption;
		}

		function open() {
			list.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
		}

		function close() {
			list.hidden = true;
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
			active = -1;
		}

		function sync() {
			wrap.hidden = ! isObjectMode();

			if ( wrap.hidden ) {
				close();
			}
		}

		function message( text ) {
			options = [];
			list.textContent = '';
			active = -1;

			var li = document.createElement( 'li' );
			li.className = 'wp-mlp-editor-picker__message';
			li.setAttribute( 'role', 'presentation' );
			li.textContent = text;
			list.appendChild( li );
			open();
		}

		function choose( item ) {
			objectOption.value = 'object:' + item.id;
			chosenTitle = item.title || item.path || '';
			input.value = chosenTitle;
			close();
		}

		function highlight( index ) {
			var nodes = list.querySelectorAll( '[role="option"]' );

			if ( active >= 0 && nodes[ active ] ) {
				nodes[ active ].setAttribute( 'aria-selected', 'false' );
				nodes[ active ].classList.remove( 'is-active' );
			}

			active = index;

			if ( active >= 0 && nodes[ active ] ) {
				nodes[ active ].setAttribute( 'aria-selected', 'true' );
				nodes[ active ].classList.add( 'is-active' );
				input.setAttribute( 'aria-activedescendant', nodes[ active ].id );
				nodes[ active ].scrollIntoView( { block: 'nearest' } );
			} else {
				input.removeAttribute( 'aria-activedescendant' );
			}
		}

		function render( items ) {
			// Адреса без записи (id = 0) фильтром области не поддерживаются.
			options = items.filter( function ( item ) {
				return item && item.id > 0;
			} ).slice( 0, 20 );
			list.textContent = '';
			active = -1;
			input.removeAttribute( 'aria-activedescendant' );

			if ( ! options.length ) {
				message( i18n.pickerEmpty || '' );

				return;
			}

			options.forEach( function ( item, index ) {
				var li = document.createElement( 'li' );
				var title = document.createElement( 'strong' );
				var meta = document.createElement( 'span' );

				li.id = 'mlp-scope-option-' + index;
				li.className = 'wp-mlp-editor-picker__option';
				li.setAttribute( 'role', 'option' );
				li.setAttribute( 'aria-selected', 'false' );
				title.textContent = item.title || item.path;
				meta.className = 'wp-mlp-editor-picker__meta';
				meta.textContent = ( item.type_label ? item.type_label + ' · ' : '' ) + item.path;
				li.appendChild( title );
				li.appendChild( meta );

				// mousedown, а не click: иначе blur поля закроет список раньше клика.
				li.addEventListener( 'mousedown', function ( event ) {
					event.preventDefault();
					choose( item );
				} );
				li.addEventListener( 'mousemove', function () {
					if ( active !== index ) {
						highlight( index );
					}
				} );
				list.appendChild( li );
			} );

			open();
		}

		function search( query ) {
			var id = ++requestId;

			if ( controller ) {
				controller.abort();
			}

			controller = 'function' === typeof AbortController ? new AbortController() : null;
			message( i18n.pickerLoading || '' );

			var init = { credentials: 'same-origin', headers: { 'X-WP-Nonce': settings.nonce } };

			if ( controller ) {
				init.signal = controller.signal;
			}

			window.fetch( settings.pagesRoot + '?search=' + encodeURIComponent( query ), init )
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( 'http ' + response.status );
					}

					return response.json();
				} )
				.then( function ( items ) {
					if ( id === requestId ) {
						render( Array.isArray( items ) ? items : [] );
					}
				} )
				.catch( function ( error ) {
					if ( id === requestId && ! ( error && 'AbortError' === error.name ) ) {
						message( i18n.pickerError || '' );
					}
				} );
		}

		function cancel() {
			window.clearTimeout( timer );
			requestId++;

			if ( controller ) {
				controller.abort();
				controller = null;
			}
		}

		select.addEventListener( 'change', function () {
			sync();

			if ( isObjectMode() ) {
				input.focus();
			}
		} );

		input.addEventListener( 'input', function () {
			var query = input.value.trim();

			cancel();

			if ( query.length < 2 ) {
				close();

				return;
			}

			message( i18n.pickerLoading || '' );
			timer = window.setTimeout( function () {
				search( query );
			}, 250 );
		} );

		input.addEventListener( 'keydown', function ( event ) {
			var count = options.length;

			if ( 'ArrowDown' === event.key || 'ArrowUp' === event.key ) {
				event.preventDefault();

				if ( count && ! list.hidden ) {
					highlight( ( active + ( 'ArrowDown' === event.key ? 1 : -1 ) + count ) % count );
				}
			} else if ( 'Enter' === event.key ) {
				// Enter в поле поиска выбирает подсвеченную запись, а не отправляет форму.
				if ( ! list.hidden ) {
					event.preventDefault();

					if ( active >= 0 && options[ active ] ) {
						choose( options[ active ] );
					}
				}
			} else if ( 'Escape' === event.key ) {
				cancel();
				close();
			}
		} );

		document.addEventListener( 'mousedown', function ( event ) {
			if ( ! wrap.contains( event.target ) ) {
				cancel();
				close();
				input.value = chosenTitle;
			}
		} );
	}() );

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
