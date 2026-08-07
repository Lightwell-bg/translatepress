/**
 * Скрипт внутри iframe визуального редактора.
 *
 * Работает только в предпросмотре: подсвечивает переводимые узлы, сообщает
 * родительскому окну о выборе и подставляет сохранённый перевод на месте,
 * без перезагрузки страницы.
 *
 * Ничего не сохраняет сам — все запросы к REST идут из админки, где есть nonce.
 */
( function () {
	'use strict';

	var settings = window.wpMlpPreview;

	if ( ! settings ) {
		return;
	}

	var ID_ATTR = 'data-mlp-source-id';
	var KIND_ATTR = 'data-mlp-kind';
	var ATTR_PREFIX = 'data-mlp-source-id-';
	var selected = null;

	/**
	 * Собирает описание переводимых строк элемента.
	 *
	 * У одного элемента их может быть несколько: сам текст плюс alt и title.
	 *
	 * @param {Element} element Элемент под курсором.
	 * @return {Array} Список строк.
	 */
	function stringsOf( element ) {
		var items = [];

		if ( element.hasAttribute( ID_ATTR ) ) {
			items.push( {
				id: parseInt( element.getAttribute( ID_ATTR ), 10 ),
				kind: element.getAttribute( KIND_ATTR ) || 'text',
				attribute: null
			} );
		}

		Array.prototype.forEach.call( element.attributes, function ( attribute ) {
			if ( 0 !== attribute.name.indexOf( ATTR_PREFIX ) ) {
				return;
			}

			items.push( {
				id: parseInt( attribute.value, 10 ),
				kind: 'attribute',
				attribute: attribute.name.slice( ATTR_PREFIX.length )
			} );
		} );

		return items;
	}

	/**
	 * Возвращает разметку элемента без служебных маркеров редактора.
	 *
	 * Чистим через DOM, а не регулярками: обёртки могут быть вложенными, и
	 * разбирать это строковыми заменами ненадёжно. На сервере разметка всё
	 * равно проходит через wp_kses — это защита, а не эта функция.
	 *
	 * @param {Element} element Выбранный элемент.
	 * @return {string} Готовая разметка.
	 */
	function cleanHtml( element ) {
		var clone = element.cloneNode( true );

		Array.prototype.forEach.call( clone.querySelectorAll( 'span.mlp-marker' ), function ( marker ) {
			while ( marker.firstChild ) {
				marker.parentNode.insertBefore( marker.firstChild, marker );
			}

			marker.parentNode.removeChild( marker );
		} );

		Array.prototype.forEach.call( clone.querySelectorAll( '*' ), function ( node ) {
			Array.prototype.slice.call( node.attributes ).forEach( function ( attribute ) {
				if ( 0 === attribute.name.indexOf( 'data-mlp-' ) ) {
					node.removeAttribute( attribute.name );
				}
			} );

			node.classList.remove( 'mlp-hover', 'mlp-selected', 'mlp-marker' );

			if ( node.hasAttribute( 'class' ) && '' === node.getAttribute( 'class' ) ) {
				node.removeAttribute( 'class' );
			}
		} );

		return clone.innerHTML.replace( /\s+/g, ' ' ).trim();
	}

	/**
	 * Ближайший элемент с переводимой строкой.
	 *
	 * @param {EventTarget} target Цель события.
	 * @return {Element|null}
	 */
	function translatableFrom( target ) {
		var node = target instanceof Element ? target : null;

		while ( node && node !== document.documentElement ) {
			if ( stringsOf( node ).length ) {
				return node;
			}

			node = node.parentElement;
		}

		return null;
	}

	/**
	 * Отмечает выбранный элемент.
	 *
	 * @param {Element|null} element Элемент или null для снятия выделения.
	 */
	function select( element ) {
		if ( selected ) {
			selected.classList.remove( 'mlp-selected' );
		}

		selected = element;

		if ( selected ) {
			selected.classList.add( 'mlp-selected' );
		}
	}

	/**
	 * Сообщает админке о событии.
	 *
	 * @param {string} type Тип сообщения.
	 * @param {Object} data Полезная нагрузка.
	 */
	function notify( type, data ) {
		window.parent.postMessage(
			Object.assign( { source: 'wp-mlp-preview', type: type }, data || {} ),
			window.location.origin
		);
	}

	document.addEventListener(
		'mouseover',
		function ( event ) {
			var element = translatableFrom( event.target );

			if ( element ) {
				element.classList.add( 'mlp-hover' );
			}
		},
		true
	);

	document.addEventListener(
		'mouseout',
		function ( event ) {
			var element = translatableFrom( event.target );

			if ( element ) {
				element.classList.remove( 'mlp-hover' );
			}
		},
		true
	);

	document.addEventListener(
		'click',
		function ( event ) {
			var element = translatableFrom( event.target );

			// Переход по ссылке внутри предпросмотра сохраняет режим редактора.
			var link = event.target instanceof Element ? event.target.closest( 'a[href]' ) : null;

			if ( element ) {
				event.preventDefault();
				event.stopPropagation();
				select( element );

				// Кандидатом в блок может быть родитель кликнутого узла:
				// маркер стоит на куске текста, а объединять нужно абзац.
				var candidate = element.hasAttribute( 'data-mlp-block' )
					? element
					: element.closest( '[data-mlp-block]' );

				notify( 'select', {
					strings: stringsOf( element ),
					blockHtml: candidate ? cleanHtml( candidate ) : null,
					blockCandidate: null !== candidate
				} );

				return;
			}

			if ( link ) {
				event.preventDefault();
				notify( 'navigate', { url: link.href } );
			}
		},
		true
	);

	// Формы в предпросмотре не отправляем: редактор — не витрина сайта.
	document.addEventListener(
		'submit',
		function ( event ) {
			event.preventDefault();
		},
		true
	);

	/**
	 * Обновляет узел после сохранения перевода в админке.
	 *
	 * @param {Object} data Сообщение из родительского окна.
	 */
	function applyTranslation( data ) {
		var element;

		if ( 'attribute' === data.kind ) {
			element = document.querySelector( '[' + ATTR_PREFIX + data.attribute + '="' + data.id + '"]' );

			if ( element ) {
				element.setAttribute( data.attribute, data.text );
			}

			return;
		}

		element = document.querySelector( '[' + ID_ATTR + '="' + data.id + '"]' );

		if ( ! element ) {
			return;
		}

		if ( 'html_block' === data.kind ) {
			element.innerHTML = data.text;

			return;
		}

		element.textContent = data.text;
	}

	window.addEventListener( 'message', function ( event ) {
		if ( event.origin !== window.location.origin || ! event.data ) {
			return;
		}

		if ( 'wp-mlp-editor' !== event.data.source ) {
			return;
		}

		if ( 'apply' === event.data.type ) {
			applyTranslation( event.data );
		}

		if ( 'reload' === event.data.type ) {
			window.location.reload();
		}
	} );

	notify( 'ready', { url: window.location.href, title: document.title } );
}() );
