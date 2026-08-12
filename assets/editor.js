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
	var aiButton = document.getElementById( 'mlp-editor-translate' );
	var linksBox = document.getElementById( 'mlp-editor-links' );
	var linksList = linksBox ? linksBox.querySelector( '.wp-mlp-editor__links-list' ) : null;
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
				renderLinks();
				say( '' );
				targetField.focus();
			} )
			.catch( function () {
				say( settings.i18n.failed, 'error' );
			} );
	}

	/**
	 * Находит все значения href в разметке, по порядку.
	 *
	 * Регулярным выражением, а не разбором в DOM: разобранный и собранный
	 * заново HTML возвращается не байт-в-байт — меняются кавычки атрибутов,
	 * самозакрывающиеся теги, порядок. Перевод блока хранится как есть, и
	 * молча переписывать его ради показа одного поля нельзя.
	 *
	 * @param {string} html Разметка перевода.
	 * @return {Array} Совпадения: значение и границы внутри строки.
	 */
	function findLinks( html ) {
		var pattern = /href\s*=\s*("([^"]*)"|'([^']*)')/gi;
		var found = [];
		var match;

		while ( ( match = pattern.exec( html ) ) !== null ) {
			var quoted = match[ 1 ];
			var value = undefined !== match[ 2 ] ? match[ 2 ] : match[ 3 ];

			found.push( {
				value: value,
				// Границы самого значения, без кавычек: заменять будем только его.
				start: match.index + match[ 0 ].length - quoted.length + 1,
				end: match.index + match[ 0 ].length - 1
			} );
		}

		return found;
	}

	/**
	 * Превращает HTML-сущности в обычные символы — для показа в поле.
	 *
	 * @param {string} value Значение атрибута как оно записано в разметке.
	 * @return {string}
	 */
	function decodeAttribute( value ) {
		var area = document.createElement( 'textarea' );

		area.innerHTML = value;

		return area.value;
	}

	/**
	 * Обратно: экранирует то, что нельзя оставить в значении атрибута.
	 *
	 * @param {string} value Значение из поля.
	 * @return {string}
	 */
	function encodeAttribute( value ) {
		return value
			.replace( /&/g, '&amp;' )
			.replace( /"/g, '&quot;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	/**
	 * Перерисовывает поля ссылок по текущему содержимому перевода.
	 *
	 * Поля — только вид на разметку, своего состояния у них нет: правка
	 * тут же уходит обратно в тот же HTML, и второго места, где хранится
	 * адрес, не появляется.
	 */
	function renderLinks() {
		if ( ! linksBox ) {
			return;
		}

		var isBlock = current && 'html_block' === current.kind;
		var links = isBlock ? findLinks( targetField.value ) : [];

		/*
		 * Адрес ссылки языковой модели не показываем: она переводит то, что
		 * видит, — `/o-nas/` станет `/about-us/`, параметры перемешаются, и
		 * ссылка молча поведёт в никуда. Адреса правит человек.
		 */
		if ( aiButton ) {
			aiButton.hidden = !! ( current && 'attribute' === current.kind && 'href' === current.attribute );
		}

		linksList.textContent = '';
		linksBox.hidden = 0 === links.length;

		links.forEach( function ( link, index ) {
			var field = document.createElement( 'input' );

			field.type = 'text';
			field.className = 'widefat code';
			field.value = decodeAttribute( link.value );

			field.addEventListener( 'change', function () {
				/*
				 * Границы берутся заново: пока поле правили, разметку могли
				 * поменять в соседнем поле или прямо в textarea, и старые
				 * позиции указывали бы уже не туда.
				 */
				var fresh = findLinks( targetField.value )[ index ];

				if ( ! fresh ) {
					return;
				}

				targetField.value = targetField.value.slice( 0, fresh.start )
					+ encodeAttribute( field.value )
					+ targetField.value.slice( fresh.end );

				renderLinks();
			} );

			var label = document.createElement( 'label' );

			label.className = 'wp-mlp-editor__link';
			label.appendChild( document.createTextNode( settings.i18n.linkLabel.replace( '%d', index + 1 ) ) );
			label.appendChild( field );

			linksList.appendChild( label );
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
				// Сервер чистит разметку блока — ссылки могли измениться.
				renderLinks();
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

	// -----------------------------------------------------------------
	// «Перевести весь материал с ИИ»: заголовок, анонс и весь текст записи
	// одной операцией — вместо клика по каждому блоку в предпросмотре.
	// -----------------------------------------------------------------

	var postId        = parseInt( settings.postId, 10 ) || 0;
	var bulkPanel     = document.getElementById( 'mlp-editor-bulk-panel' );
	var bulkStartBtn  = document.getElementById( 'mlp-editor-bulk-start' );
	var bulkSaveBtn   = document.getElementById( 'mlp-editor-bulk-save' );
	var bulkStatusBox = bulkPanel ? bulkPanel.querySelector( '.wp-mlp-editor__bulk-progress' ) : null;
	var bulkWarnBox   = bulkPanel ? bulkPanel.querySelector( '.wp-mlp-editor__bulk-warning' ) : null;
	var bulkListBox   = bulkPanel ? bulkPanel.querySelector( '.wp-mlp-editor__bulk-list' ) : null;
	var bulkCommitBox = bulkPanel ? bulkPanel.querySelector( '.wp-mlp-editor__bulk-commit' ) : null;

	// Текущее состояние панели ревью: то, что реально уйдёт в /commit.
	var bulkSegments = [];
	var bulkBusy     = false;

	/**
	 * Короткое сообщение в статусной строке панели массового перевода —
	 * отдельно от say(), которая говорит про одиночный выбранный сегмент.
	 *
	 * @param {string} text  Текст.
	 * @param {string} state Модификатор класса (error/ok) или пусто.
	 */
	function bulkSay( text, state ) {
		if ( ! bulkStatusBox ) {
			return;
		}

		bulkStatusBox.hidden = ! text;
		bulkStatusBox.textContent = text || '';
		bulkStatusBox.className = 'wp-mlp-editor__bulk-progress' + ( state ? ' is-' + state : '' );
	}

	/**
	 * @param {string} field PostSegment::FIELD_*.
	 * @return {string}
	 */
	function bulkFieldLabel( field ) {
		if ( 'title' === field ) {
			return settings.i18n.bulkFieldTitle;
		}

		return 'excerpt' === field ? settings.i18n.bulkFieldExcerpt : settings.i18n.bulkFieldContent;
	}

	/**
	 * Перерисовывает список сегментов на проверку из bulkSegments.
	 */
	function bulkRenderList() {
		if ( ! bulkListBox ) {
			return;
		}

		bulkListBox.innerHTML = '';

		bulkSegments.forEach( function ( row, index ) {
			var li = document.createElement( 'li' );
			li.className = 'wp-mlp-editor__bulk-row';

			var meta = document.createElement( 'p' );
			meta.className = 'wp-mlp-editor__bulk-row-meta';
			meta.textContent = bulkFieldLabel( row.field ) + ( row.changed ? ' — ' + settings.i18n.bulkChanged : '' );
			li.appendChild( meta );

			var source = document.createElement( 'p' );
			source.className = 'wp-mlp-editor__bulk-source';
			source.textContent = row.source_text;
			li.appendChild( source );

			var textarea = document.createElement( 'textarea' );
			textarea.className = 'wp-mlp-editor__bulk-target';
			textarea.rows = 2;
			textarea.value = row.translated_text || '';
			textarea.addEventListener( 'input', function () {
				bulkSegments[ index ].translated_text = textarea.value;
			} );
			li.appendChild( textarea );

			var select = document.createElement( 'select' );
			select.className = 'wp-mlp-editor__bulk-status';

			Object.keys( settings.statuses ).forEach( function ( value ) {
				var option = document.createElement( 'option' );
				option.value = value;
				option.textContent = settings.statuses[ value ];
				option.selected = value === row.status;
				select.appendChild( option );
			} );

			select.addEventListener( 'change', function () {
				bulkSegments[ index ].status = select.value;
			} );
			li.appendChild( select );

			bulkListBox.appendChild( li );
		} );

		bulkListBox.hidden = ! bulkSegments.length;

		if ( bulkCommitBox ) {
			bulkCommitBox.hidden = ! bulkSegments.length;
		}
	}

	/**
	 * Показывает перевод сразу в предпросмотре, пока панель ещё не сохранена —
	 * тот же postMessage, что и для одиночного сегмента.
	 *
	 * @param {Object} row Строка bulkSegments.
	 */
	function bulkApplyPreview( row ) {
		if ( ! row.id ) {
			return;
		}

		toPreview( {
			type: 'apply',
			id: row.id,
			kind: row.kind,
			attribute: row.attribute,
			text: row.translated_text
		} );
	}

	/**
	 * Переводит чанки последовательно — по одному запросу на чанк, чтобы не
	 * упереться в таймаут на большой записи, и с прогрессом на каждом шаге.
	 *
	 * @param {Array}    chunks Список чанков — каждый список hex-хешей.
	 * @param {Function} onDone Вызывается после последнего чанка.
	 */
	function bulkRunChunks( chunks, onDone ) {
		var index         = 0;
		var unresolvedTotal = 0;

		/**
		 * Останавливает всё на ошибке одного чанка. Сегменты, накопленные
		 * из УЖЕ успешных чанков, намеренно отбрасываются вместе с ними:
		 * список проверки и кнопка «Сохранить всё» так и остаются скрытыми
		 * (обе показывает только bulkRenderList(), а её здесь не вызвать),
		 * значит сохранить частично переведённый материал через обычное
		 * взаимодействие с панелью нельзя — ни один chunk не сорвёт commit
		 * молча, только явным «Начать» заново.
		 *
		 * @param {string} message Текст ошибки.
		 */
		function abort( message ) {
			bulkSegments = [];
			bulkSay( message, 'error' );
			bulkBusy = false;
			bulkStartBtn.disabled = false;
		}

		function next() {
			if ( index >= chunks.length ) {
				if ( unresolvedTotal > 0 ) {
					bulkWarnBox.hidden = false;
					bulkWarnBox.textContent = settings.i18n.bulkRejected.replace( '{count}', String( unresolvedTotal ) );
				}

				onDone();

				return;
			}

			bulkSay( settings.i18n.bulkProgress.replace( '{current}', String( index + 1 ) ).replace( '{total}', String( chunks.length ) ) );

			fetch( settings.postRoot + encodeURIComponent( postId ) + '/chunk', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': settings.nonce },
				body: JSON.stringify( { locale: locale, hashes: chunks[ index ] } )
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
					// «Отклонено» (шорткод разошёлся) и «пропущено» (модель
					// вообще не прислала перевод для хеша) — обе группы
					// остаются со старым/пустым значением и попадают в один
					// и тот же счётчик предупреждения: разница для читателя
					// панели не важна, важно, что ЭТИ строки нужно доперевести
					// вручную.
					unresolvedTotal += ( data.rejected || [] ).length + ( data.missing || [] ).length;

					bulkSegments.forEach( function ( row ) {
						if ( Object.prototype.hasOwnProperty.call( data.translations || {}, row.uniq_hash ) ) {
							row.translated_text = data.translations[ row.uniq_hash ];
							row.status = 'machine';
							bulkApplyPreview( row );
						}
					} );

					index++;
					next();
				} )
				.catch( function ( error ) {
					abort( error.message || settings.i18n.bulkFailed );
				} );
		}

		next();
	}

	/**
	 * Запускает массовый перевод: шаг 1 (summary), затем по чанку за раз.
	 */
	function bulkStart() {
		if ( bulkBusy || ! postId || ! bulkPanel ) {
			return;
		}

		var modeInput = bulkPanel.querySelector( 'input[name="mlp-bulk-mode"]:checked' );
		var mode      = modeInput ? modeInput.value : settings.modeEmpty;

		bulkBusy = true;
		bulkStartBtn.disabled = true;
		bulkWarnBox.hidden = true;
		bulkSegments = [];
		bulkRenderList();
		bulkSay( settings.i18n.bulkPreparing );

		var url = settings.postRoot + encodeURIComponent( postId ) + '/summary'
			+ '?locale=' + encodeURIComponent( locale ) + '&mode=' + encodeURIComponent( mode );

		fetch( url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': settings.nonce } } )
			.then( function ( response ) {
				if ( ! response.ok ) {
					return response.json().then( function ( data ) {
						throw new Error( data.message || 'HTTP ' + response.status );
					} );
				}

				return response.json();
			} )
			.then( function ( data ) {
				bulkSegments = data.segments || [];

				var finish = function () {
					bulkSay( '' );
					bulkRenderList();
					bulkBusy = false;
					bulkStartBtn.disabled = false;
				};

				if ( ! data.chunks || ! data.chunks.length ) {
					if ( ! data.to_translate ) {
						bulkSay( settings.i18n.bulkNothing );
					}

					bulkRenderList();
					bulkBusy = false;
					bulkStartBtn.disabled = false;

					return;
				}

				bulkRunChunks( data.chunks, finish );
			} )
			.catch( function ( error ) {
				bulkSay( error.message || settings.i18n.bulkFailed, 'error' );
				bulkBusy = false;
				bulkStartBtn.disabled = false;
			} );
	}

	/**
	 * Сохраняет весь список одной атомарной операцией (commit).
	 */
	function bulkSaveAll() {
		if ( bulkBusy || ! bulkSegments.length ) {
			return;
		}

		bulkBusy = true;
		bulkSaveBtn.disabled = true;
		bulkSay( settings.i18n.bulkSaving );

		var payload = bulkSegments.map( function ( row ) {
			return {
				uniq_hash: row.uniq_hash,
				translated_text: row.translated_text || '',
				status: row.status
			};
		} );

		fetch( settings.postRoot + encodeURIComponent( postId ) + '/commit', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': settings.nonce },
			body: JSON.stringify( { locale: locale, segments: payload } )
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					return response.json().then( function ( data ) {
						throw new Error( data.message || settings.i18n.bulkCommitFailed );
					} );
				}

				return response.json();
			} )
			.then( function () {
				bulkSay( settings.i18n.bulkSaved, 'ok' );
				bulkBusy = false;
				bulkSaveBtn.disabled = false;

				// Гарантированно верный итог, а не точечные патчи DOM: как и
				// после makeBlock(), проще и надёжнее перезагрузить превью.
				toPreview( { type: 'reload' } );
			} )
			.catch( function ( error ) {
				// Сегменты в панели НЕ сбрасываются: commit атомарен на сервере,
				// а здесь неудача не должна стереть то, что человек уже проверил.
				bulkSay( error.message || settings.i18n.bulkCommitFailed, 'error' );
				bulkBusy = false;
				bulkSaveBtn.disabled = false;
			} );
	}

	if ( bulkPanel ) {
		onClick( 'mlp-editor-bulk-open', function () {
			bulkPanel.hidden = ! bulkPanel.hidden;
		} );
		onClick( 'mlp-editor-bulk-cancel', function () {
			bulkPanel.hidden = true;
		} );
		onClick( 'mlp-editor-bulk-close', function () {
			bulkPanel.hidden = true;
		} );
		onClick( 'mlp-editor-bulk-start', bulkStart );

		/*
		 * Сворачивание панели. Иначе на обычном ноутбуке превью остаётся
		 * уже порога мобильной вёрстки темы, и её выезжающее меню
		 * накрывает всё превью целиком.
		 */
		/*
		 * Правку прямо в разметке поля ссылок тоже должны видеть: иначе
		 * после ручного изменения href поле показывало бы старый адрес и
		 * при следующей правке вернуло бы его обратно.
		 */
		targetField.addEventListener( 'input', renderLinks );

		onClick( 'mlp-editor-toggle-panel', function () {
			var button = document.getElementById( 'mlp-editor-toggle-panel' );
			var collapsed = root.classList.toggle( 'is-panel-collapsed' );

			button.setAttribute( 'aria-expanded', collapsed ? 'false' : 'true' );
			button.textContent = collapsed
				? settings.i18n.expandPanel
				: settings.i18n.collapsePanel;
		} );
		onClick( 'mlp-editor-bulk-save', bulkSaveAll );
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

		/*
		 * Строка интерфейса: поля перевода не показываем вовсе — её нельзя
		 * перевести здесь, она живёт во вкладке «Интерфейс». Молча
		 * игнорировать клик нельзя: пользователь решил бы, что редактор
		 * сломан, и жал бы снова.
		 */
		if ( 'gettext' === event.data.type ) {
			current = null;
			form.hidden = true;

			if ( linksBox ) {
				linksBox.hidden = true;
			}

			hint.hidden = false;
			hint.textContent = settings.i18n.gettextNotice.replace( '%s', event.data.domain );

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
