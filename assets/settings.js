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
					field.name = field.name.replace( /^languages\[\d+\]/, 'languages[' + index + ']' );
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

		/*
		 * Пустые строки-заготовки внизу таблицы (у них нет кнопок порядка)
		 * менять местами с языком нельзя: язык уехал бы за них и при
		 * сохранении оказался последним.
		 */
		if ( ! rows[ to ].querySelector( '.mlp-order-up' ) ) {
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
		var button = event.target.closest( '.mlp-order-up, .mlp-order-down' );

		if ( ! button ) {
			return;
		}

		event.preventDefault();
		move( button.closest( 'tr' ), button.classList.contains( 'mlp-order-up' ) ? -1 : 1 );

		// Фокус остаётся на кнопке, уехавшей вместе со строкой.
		button.focus();
	} );
}() );
