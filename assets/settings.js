/**
 * Перестановка языков на странице «Языки сайта».
 *
 * Порядок строк в таблице и есть порядок языков в переключателе на сайте:
 * форма отправляет поля в том порядке, в каком они стоят в разметке, а
 * сервер складывает языки в том порядке, в каком их получил. Отдельного
 * поля с номером поэтому нет.
 */
( function () {
	'use strict';

	var table = document.querySelector( '.mlp-languages-table' );

	if ( ! table ) {
		return;
	}

	var body = table.querySelector( 'tbody' );

	if ( ! body ) {
		return;
	}

	/*
	 * Кнопки лежат в разметке скрытыми и показываются отсюда: без скрипта
	 * переставить строку всё равно нечем, а кнопка, которая ничего не
	 * делает, хуже отсутствующей.
	 */
	Array.prototype.forEach.call(
		body.querySelectorAll( '.mlp-order-up, .mlp-order-down' ),
		function ( button ) {
			button.hidden = false;
		}
	);

	/**
	 * Перенумеровывает поля формы по текущему порядку строк.
	 *
	 * Имя поля содержит индекс — `languages[0][locale]`. Переставить строки
	 * и не тронуть имена значит отправить прежний порядок: сервер собирает
	 * массив по индексам из имён, а не по тому, где строка оказалась.
	 */
	function renumber() {
		Array.prototype.forEach.call( body.rows, function ( row, index ) {
			Array.prototype.forEach.call(
				row.querySelectorAll( 'input[name^="languages["], select[name^="languages["]' ),
				function ( field ) {
					field.name = field.name.replace( /^languages\[[^\]]+\]/, 'languages[' + index + ']' );
				}
			);

			// Файл флага связан с языком номером строки: flag_file_3 <-> languages[3].
			Array.prototype.forEach.call(
				row.querySelectorAll( 'input[name^="flag_file_"]' ),
				function ( field ) {
					field.name = 'flag_file_' + index;
				}
			);
		} );
	}

	/**
	 * Переставляет строку на одну позицию.
	 *
	 * @param {HTMLTableRowElement} row  Строка языка.
	 * @param {number}              step -1 вверх, 1 вниз.
	 */
	function move( row, step ) {
		var rows = Array.prototype.slice.call( body.rows );
		var from = rows.indexOf( row );
		var to = from + step;

		if ( from < 0 || to < 0 || to >= rows.length ) {
			return;
		}

		if ( step < 0 ) {
			body.insertBefore( row, rows[ to ] );
		} else {
			body.insertBefore( rows[ to ], row );
		}

		renumber();
	}

	body.addEventListener( 'click', function ( event ) {
		var remove = event.target.closest( '.mlp-row-remove' );

		// Убрать ещё не сохранённую строку.
		if ( remove ) {
			event.preventDefault();
			body.removeChild( remove.closest( 'tr' ) );
			renumber();
			refreshOptions();

			return;
		}

		var button = event.target.closest( '.mlp-order-up, .mlp-order-down' );

		if ( ! button ) {
			return;
		}

		event.preventDefault();
		move( button.closest( 'tr' ), button.classList.contains( 'mlp-order-up' ) ? -1 : 1 );

		// Фокус остаётся на кнопке, уехавшей вместе со строкой.
		button.focus();
	} );

	/*
	 * Добавление языка выбором из списка. Пустая строка-заготовка для
	 * случая без JS здесь не нужна: её заменяет поле «Добавить язык».
	 * Строки собираются из <template> через DOM API и textContent/value —
	 * никакой вставки HTML с пользовательскими данными.
	 */
	var picker = document.querySelector( '.mlp-language-picker' );
	var template = document.getElementById( 'mlp-language-row-template' );
	var pickerInput = picker ? picker.querySelector( '#mlp-language-picker-input' ) : null;
	var datalist = picker ? picker.querySelector( 'datalist' ) : null;
	var addButton = picker ? picker.querySelector( '#mlp-language-picker-add' ) : null;
	var allOptions = [];

	/**
	 * Значения поля в строке по окончанию имени, например `[slug]`.
	 *
	 * @param {string} suffix Окончание имени поля.
	 * @return {Array<string>} Непустые значения в нижнем регистре.
	 */
	function columnValues( suffix ) {
		return Array.prototype.map.call(
			body.querySelectorAll( 'input[name$="' + suffix + '"]' ),
			function ( field ) {
				return field.value.trim().toLowerCase();
			}
		).filter( Boolean );
	}

	/**
	 * Прячет из списка языки, которые уже есть в таблице.
	 */
	function refreshOptions() {
		if ( ! datalist ) {
			return;
		}

		var used = columnValues( '[wp_locale]' );

		while ( datalist.firstChild ) {
			datalist.removeChild( datalist.firstChild );
		}

		allOptions.forEach( function ( option ) {
			if ( -1 === used.indexOf( option.getAttribute( 'data-locale' ).toLowerCase() ) ) {
				datalist.appendChild( option );
			}
		} );
	}

	/**
	 * Emoji-флаг по коду страны: две региональные буквы Unicode.
	 *
	 * @param {string} country Две латинские буквы.
	 * @return {string} Флаг.
	 */
	function emojiFlag( country ) {
		return Array.prototype.map.call( country.toUpperCase(), function ( letter ) {
			return String.fromCodePoint( 0x1F1E6 + letter.charCodeAt( 0 ) - 65 );
		} ).join( '' );
	}

	/**
	 * Данные языка по тексту из поля: пункт списка или локаль, вписанная руками.
	 *
	 * @param {string} text Текст поля.
	 * @return {?{locale: string, code: string, name: string}} null — ввод недопустим.
	 */
	function resolve( text ) {
		var found = null;

		if ( '' === text ) {
			return { locale: '', code: '', flag: '', name: '' };
		}

		allOptions.forEach( function ( option ) {
			if ( option.value === text ) {
				found = {
					locale: option.getAttribute( 'data-locale' ),
					code: option.getAttribute( 'data-code' ),
					flag: option.getAttribute( 'data-flag' ) || '',
					name: option.getAttribute( 'data-native' )
				};
			}
		} );

		if ( found ) {
			return found;
		}

		var locale = text.replace( /-/g, '_' );

		if ( ! /^[A-Za-z]{2,3}(_[A-Za-z0-9]+)*$/.test( locale ) ) {
			return null;
		}

		var region = locale.split( '_' )[ 1 ] || '';

		return {
			locale: locale,
			code: locale.split( '_' )[ 0 ].toLowerCase(),
			flag: /^[A-Za-z]{2}$/.test( region ) ? emojiFlag( region ) : '',
			name: ''
		};
	}

	/**
	 * Дописывает строку нового языка в конец таблицы.
	 */
	function addLanguage() {
		var data = resolve( pickerInput.value.trim() );

		if ( ! data ) {
			pickerInput.setCustomValidity( pickerInput.getAttribute( 'data-invalid' ) || 'de_DE' );
			pickerInput.reportValidity();
			pickerInput.setCustomValidity( '' );

			return;
		}

		var code = data.code;
		var slug = data.code;
		var full = data.locale.toLowerCase().replace( /_/g, '-' );

		// Код или слаг уже заняты (en для en_GB, pt для pt_BR): берём полную локаль.
		if ( '' !== code && ( -1 !== columnValues( '[locale]' ).indexOf( code ) || -1 !== columnValues( '[slug]' ).indexOf( code ) ) ) {
			code = full;
			slug = full;
		}

		var row = document.importNode( template.content.querySelector( 'tr' ), true );
		var values = { '[locale]': code, '[slug]': slug, '[label]': data.name, '[wp_locale]': data.locale, '[flag]': data.flag || '' };

		Object.keys( values ).forEach( function ( suffix ) {
			row.querySelector( 'input[name$="' + suffix + '"]' ).value = values[ suffix ];
		} );

		Array.prototype.forEach.call( row.querySelectorAll( '.mlp-order-up, .mlp-order-down' ), function ( button ) {
			button.hidden = false;
		} );

		body.appendChild( row );
		renumber();
		pickerInput.value = '';
		refreshOptions();
		row.querySelector( 'input[name$="[label]"]' ).focus();
	}

	if ( picker && template && pickerInput && datalist && addButton ) {
		allOptions = Array.prototype.slice.call( datalist.querySelectorAll( 'option' ) );

		Array.prototype.forEach.call( body.querySelectorAll( 'tr.mlp-row-blank' ), function ( row ) {
			body.removeChild( row );
		} );

		picker.hidden = false;
		refreshOptions();

		addButton.addEventListener( 'click', addLanguage );
		pickerInput.addEventListener( 'keydown', function ( event ) {
			// Enter в поле добавляет язык, а не отправляет всю форму.
			if ( 'Enter' === event.key ) {
				event.preventDefault();
				addLanguage();
			}
		} );
	}
}() );

/**
 * Выпадающий список моделей OpenAI вместо текстового поля.
 *
 * Настоящее поле `openai_model` остаётся в форме и всегда хранит то, что
 * уйдёт на сервер: список лишь подставляет в него значение. Без скрипта
 * (или без ключа, или при ошибке API) работает обычный ввод.
 */
( function () {
	'use strict';

	var wrap = document.querySelector( '.mlp-model-field' );
	var input = document.getElementById( 'mlp-openai-model' );
	var cfg = window.mlpSettings;

	if ( ! wrap || ! input || ! cfg || '1' !== wrap.getAttribute( 'data-enhance' ) ) {
		return;
	}

	var i18n = cfg.i18n || {};
	var CUSTOM = '__custom__';
	var saved = input.value;
	var select = document.createElement( 'select' );
	var refresh = document.createElement( 'button' );
	var status = document.createElement( 'span' );

	select.id = 'mlp-openai-model-select';
	select.className = 'mlp-model-select';
	refresh.type = 'button';
	refresh.className = 'button mlp-model-refresh';
	refresh.textContent = i18n.refresh || 'Refresh';
	status.className = 'mlp-model-status';
	status.setAttribute( 'role', 'status' );

	/**
	 * Показывает либо список, либо текстовое поле.
	 *
	 * @param {boolean} custom true — ввод вручную.
	 */
	function showInput( custom ) {
		input.hidden = ! custom;
	}

	/**
	 * Заполняет список; сохранённая модель всегда присутствует и выбрана.
	 *
	 * @param {Array<{id: string, created: number}>} models Ответ сервера.
	 */
	function fill( models ) {
		var current = input.value;
		var found = false;

		while ( select.firstChild ) {
			select.removeChild( select.firstChild );
		}

		models.forEach( function ( model ) {
			var option = document.createElement( 'option' );

			option.value = model.id;
			option.textContent = model.id;
			select.appendChild( option );

			if ( model.id === current ) {
				found = true;
			}
		} );

		if ( '' !== current && ! found ) {
			var keep = document.createElement( 'option' );

			keep.value = current;
			keep.textContent = current + ' ' + ( i18n.saved || '(saved)' );
			select.insertBefore( keep, select.firstChild );
		}

		var other = document.createElement( 'option' );

		other.value = CUSTOM;
		other.textContent = i18n.custom || 'Other…';
		select.appendChild( other );

		if ( '' === current ) {
			var empty = document.createElement( 'option' );

			empty.value = '';
			empty.textContent = '—';
			select.insertBefore( empty, select.firstChild );
		}

		select.value = current;
		showInput( false );
	}

	/**
	 * Запрашивает список у сервера.
	 *
	 * @param {boolean} force true — в обход кеша.
	 */
	function load( force ) {
		var url = cfg.modelsUrl + ( force ? ( -1 === cfg.modelsUrl.indexOf( '?' ) ? '?' : '&' ) + 'refresh=1' : '' );

		status.textContent = i18n.loading || '…';
		refresh.disabled = true;

		window.fetch( url, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce }
		} ).then( function ( response ) {
			return response.json().then( function ( data ) {
				return { ok: response.ok, data: data };
			} );
		} ).then( function ( result ) {
			refresh.disabled = false;

			if ( ! result.ok || ! Array.isArray( result.data ) ) {
				var message = result.data && result.data.message ? String( result.data.message ) : ( i18n.error || 'Error' );

				status.textContent = message;
				select.hidden = true;
				showInput( true );

				return;
			}

			status.textContent = '';
			select.hidden = false;
			fill( result.data );
		} ).catch( function () {
			refresh.disabled = false;
			status.textContent = i18n.error || 'Error';
			select.hidden = true;
			showInput( true );
		} );
	}

	select.addEventListener( 'change', function () {
		if ( CUSTOM === select.value ) {
			showInput( true );
			input.focus();

			return;
		}

		input.value = select.value;
		showInput( false );
	} );

	refresh.addEventListener( 'click', function () {
		input.value = '' !== input.value ? input.value : saved;
		load( true );
	} );

	wrap.insertBefore( select, input );
	wrap.appendChild( refresh );
	wrap.appendChild( status );
	showInput( false );
	fill( saved ? [ { id: saved, created: 0 } ] : [] );
	load( false );
}() );

/*
 * «Непереведённые записи»: кнопка «Включить все полностью переведённые на …»
 * и предупреждение «язык будет пустым». Тексты ставятся через textContent.
 */
( function () {
	'use strict';

	var cfg = window.mlpSettings;
	var box = document.querySelector( '.mlp-vis-counts' );

	if ( ! cfg || ! box ) {
		return;
	}

	var manual = document.querySelector( 'input[name="untranslated_posts_mode"][value="manual"]' );

	/**
	 * Показывает предупреждение для языков без единой включённой записи,
	 * пока выбран ручной режим.
	 */
	function syncWarnings() {
		var isManual = !! ( manual && manual.checked );

		Array.prototype.forEach.call( box.querySelectorAll( '.mlp-vis-lang' ), function ( row ) {
			var locale = row.getAttribute( 'data-locale' );
			var empty = 0 === ( parseInt( row.getAttribute( 'data-count' ), 10 ) || 0 );

			Array.prototype.forEach.call( box.querySelectorAll( '.mlp-vis-warning' ), function ( warning ) {
				if ( warning.getAttribute( 'data-locale' ) === locale ) {
					warning.hidden = ! ( isManual && empty );
				}
			} );
		} );
	}

	Array.prototype.forEach.call(
		document.querySelectorAll( 'input[name="untranslated_posts_mode"]' ),
		function ( radio ) {
			radio.addEventListener( 'change', syncWarnings );
		}
	);

	box.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest ? event.target.closest( '.mlp-enable-translated' ) : null;

		if ( ! button || button.disabled ) {
			return;
		}

		var row = button.closest( '.mlp-vis-lang' );
		var message = row ? row.querySelector( '.mlp-vis-msg' ) : null;
		var counter = row ? row.querySelector( '.mlp-vis-count' ) : null;

		/**
		 * Пишет сообщение рядом с кнопкой.
		 *
		 * @param {string}  text Текст.
		 * @param {boolean} isError Это ошибка.
		 */
		function say( text, isError ) {
			if ( message ) {
				message.textContent = text;
				message.style.color = isError ? '#b32d2e' : '';
			}
		}

		button.disabled = true;
		say( cfg.i18n.enabling, false );

		fetch( cfg.enableUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce
			},
			body: JSON.stringify( { locale: button.getAttribute( 'data-locale' ) } )
		} )
			.then( function ( response ) {
				return response.json().catch( function () {
					return null;
				} ).then( function ( data ) {
					return { ok: response.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				if ( ! result.ok || ! result.data || 'number' !== typeof result.data.visibleCount ) {
					say( result.data && result.data.message ? result.data.message : cfg.i18n.enableFailed, true );

					return;
				}

				if ( counter ) {
					counter.textContent = String( result.data.visibleCount );
				}

				if ( row ) {
					row.setAttribute( 'data-count', String( result.data.visibleCount ) );
				}

				say( cfg.i18n.enabledResult.replace( '%d', String( result.data.enabled ) ), false );
				syncWarnings();
			} )
			.catch( function () {
				say( cfg.i18n.enableFailed, true );
			} )
			.then( function () {
				button.disabled = false;
			} );
	} );

	syncWarnings();
}() );
