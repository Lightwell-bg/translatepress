/**
 * Раздел «LingoCanvas Cloud» на странице настроек.
 *
 * Делает три вещи: показывает состояние аккаунта (GET cloud/status), опрашивает
 * оплату раз в 5 секунд до 10 минут (POST cloud/claim) и просит подтверждение
 * перед отключением сайта. Токен сайта сюда не попадает никогда: сервер отдаёт
 * только готовые подписи. Весь текст выводится через textContent.
 */
( function () {
	'use strict';

	var config = window.lingocanvasCloud;
	var root = document.getElementById( 'lingocanvas-cloud' );

	if ( ! config || ! root ) {
		return;
	}

	var POLL_INTERVAL_MS = 5000;
	var POLL_MAX_MS = 10 * 60 * 1000;

	/**
	 * Запрос к REST API плагина с nonce текущего пользователя.
	 *
	 * @param {string} url    Адрес маршрута.
	 * @param {string} method GET или POST.
	 * @return {Promise<Object>} Разобранный JSON; при ошибке HTTP — объект с полем message.
	 */
	function request( url, method ) {
		return window.fetch( url, {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce, Accept: 'application/json' }
		} ).then( function ( response ) {
			return response.json().catch( function () {
				return {};
			} );
		} );
	}

	// --- Подтверждение отключения -------------------------------------------------

	Array.prototype.forEach.call( document.querySelectorAll( '[data-lingocanvas-confirm]' ), function ( button ) {
		button.addEventListener( 'click', function ( event ) {
			if ( ! window.confirm( button.getAttribute( 'data-lingocanvas-confirm' ) ) ) {
				event.preventDefault();
			}
		} );
	} );

	// --- Состояние аккаунта -------------------------------------------------------

	var statusBox = root.querySelector( '[data-lingocanvas-cloud-status]' );

	// Без согласия владельца скрипт не обращается к Cloud: ни статус, ни опрос оплаты.
	if ( config.connected && config.consent && statusBox ) {
		var message = statusBox.querySelector( '.lingocanvas-cloud-status-msg' );
		var list = statusBox.querySelector( '.lingocanvas-cloud-fields' );
		var packsNote = statusBox.querySelector( '[data-lingocanvas-field="packs_note"]' );
		var packButton = root.querySelector( '[data-lingocanvas-cloud-pack]' );
		var packNote = root.querySelector( '[data-lingocanvas-cloud-pack-note]' );
		var resubscribe = root.querySelector( '[data-lingocanvas-cloud-resubscribe]' );
		var refresh = root.querySelector( '[data-lingocanvas-cloud-refresh]' );

		var setField = function ( name, value ) {
			var node = statusBox.querySelector( 'span[data-lingocanvas-field="' + name + '"]' );

			if ( node ) {
				node.textContent = value || '';
			}
		};

		var showStatus = function ( data ) {
			[ 'plan', 'subscription', 'renewal', 'monthly_left', 'packs_left', 'sites' ].forEach( function ( name ) {
				setField( name, data[ name ] );
			} );

			// Строка про даты нужна только если у подписки есть период.
			var renewal = statusBox.querySelector( 'span[data-lingocanvas-field="renewal"]' );

			if ( renewal && renewal.parentNode ) {
				renewal.parentNode.hidden = ! data.renewal;
			}

			if ( packsNote ) {
				packsNote.textContent = data.packs_note || '';
				packsNote.hidden = ! data.packs_note;
			}

			if ( packButton ) {
				packButton.disabled = ! data.live;
			}

			if ( packNote ) {
				packNote.textContent = data.live ? '' : config.i18n.noPack;
				packNote.hidden = !! data.live;
			}

			if ( resubscribe ) {
				resubscribe.hidden = !! data.live;
			}

			message.hidden = true;
			list.hidden = false;
		};

		var load = function ( force ) {
			message.hidden = false;
			message.textContent = config.i18n.loading;

			var url = config.statusUrl + ( force ? ( config.statusUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + 'refresh=1' : '' );

			request( url, 'GET' ).then( function ( data ) {
				if ( data && data.plan !== undefined ) {
					showStatus( data );

					return;
				}

				message.textContent = ( data && data.message ) || config.i18n.failed;
			} ).catch( function () {
				message.textContent = config.i18n.failed;
			} );
		};

		if ( refresh ) {
			refresh.addEventListener( 'click', function () {
				load( true );
			} );
		}

		load( false );
	}

	// --- Опрос оплаты -------------------------------------------------------------

	if ( config.pending && config.consent ) {
		var pollMessage = root.querySelector( '.lingocanvas-cloud-poll-msg' );
		var started = Date.now();
		var limit = Math.min( POLL_MAX_MS, ( config.expiresIn || 0 ) * 1000 );

		var say = function ( text ) {
			if ( pollMessage ) {
				pollMessage.textContent = text;
			}
		};

		var poll = function () {
			if ( Date.now() - started >= limit ) {
				say( config.i18n.timeout );

				return;
			}

			say( config.i18n.checking );

			request( config.claimUrl, 'POST' ).then( function ( data ) {
				var status = data && data.status;

				if ( 'connected' === status ) {
					say( config.i18n.connected );
					window.location.reload();

					return;
				}

				if ( 'pending' === status ) {
					window.setTimeout( poll, POLL_INTERVAL_MS );

					return;
				}

				say( ( data && data.message ) || config.i18n.failed );
			} ).catch( function () {
				window.setTimeout( poll, POLL_INTERVAL_MS );
			} );
		};

		window.setTimeout( poll, POLL_INTERVAL_MS );
	}
}() );
