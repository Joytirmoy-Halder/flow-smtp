/* global FlowSMTP, jQuery */
( function ( $ ) {
	'use strict';

	function notice( $el, type, message ) {
		$el.removeClass( 'is-success is-error' )
			.addClass( 'is-' + type )
			.text( message )
			.prop( 'hidden', false );
	}

	function ajax( action, data ) {
		return $.post( FlowSMTP.ajaxUrl, $.extend( { action: action, nonce: FlowSMTP.nonce }, data ) );
	}

	function escAttr( s ) {
		return String( s )
			.replace( /&/g, '&amp;' )
			.replace( /"/g, '&quot;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	$( function () {
		/* Provider preset autofill */
		$( '#flowsmtp-provider' ).on( 'change', function () {
			var preset = FlowSMTP.presets && FlowSMTP.presets[ this.value ],
				$note = $( '#flowsmtp-provider-note' );

			if ( ! preset ) {
				return;
			}

			if ( preset.host ) {
				$( '#flowsmtp-host' ).val( preset.host );
			}
			if ( preset.port ) {
				$( '#flowsmtp-port' ).val( preset.port );
			}
			if ( preset.encryption ) {
				$( '#flowsmtp-encryption' ).val( preset.encryption );
			}
			if ( preset.username ) {
				$( '#flowsmtp-username' ).val( preset.username );
			}

			if ( preset.note || preset.docs ) {
				$note.empty();
				if ( preset.note ) {
					$note.append( document.createTextNode( preset.note + ' ' ) );
				}
				if ( preset.docs ) {
					$note.append(
						$( '<a>' )
							.attr( { href: preset.docs, target: '_blank', rel: 'noopener noreferrer' } )
							.text( FlowSMTP.i18n.docs + ' \u2197' )
					);
				}
				$note.prop( 'hidden', false );
			} else {
				$note.prop( 'hidden', true ).empty();
			}
		} );

		/* Send test email */
		$( '#flowsmtp-send-test' ).on( 'click', function () {
			var $btn = $( this ),
				$result = $( '#flowsmtp-test-result' ),
				original = $btn.text();

			$btn.prop( 'disabled', true ).text( FlowSMTP.i18n.sending );
			$result.prop( 'hidden', true );

			ajax( 'flowsmtp_send_test', {
				to: $( '#flowsmtp-test-to' ).val(),
				html: $( '#flowsmtp-test-html' ).val() === '1' ? 1 : ''
			} )
				.done( function ( res ) {
					notice( $result, res.success ? 'success' : 'error', res.data.message );
				} )
				.fail( function () {
					notice( $result, 'error', 'Request failed. Please try again.' );
				} )
				.always( function () {
					$btn.prop( 'disabled', false ).text( original );
				} );
		} );

		/* Deliverability: domain health check */
		$( '#flowsmtp-run-check' ).on( 'click', function () {
			var $btn = $( this ),
				$out = $( '#flowsmtp-check-results' ),
				original = $btn.text(),
				badge = { pass: 'is-sent', warn: 'is-pending', fail: 'is-failed' };

			$btn.prop( 'disabled', true ).text( FlowSMTP.i18n.checking );

			ajax( 'flowsmtp_check_domain', {
				domain: $( '#flowsmtp-check-domain' ).val(),
				selector: $( '#flowsmtp-check-selector' ).val()
			} )
				.done( function ( res ) {
					if ( ! res.success ) {
						$out.html( '<div class="flowsmtp-notice is-error">' + escAttr( res.data.message ) + '</div>' ).prop( 'hidden', false );
						return;
					}

					var html = '';
					$.each( res.data.checks, function ( i, c ) {
						html +=
							'<li>' +
							'<span class="flowsmtp-badge ' + ( badge[ c.status ] || 'is-pending' ) + '">' + escAttr( c.status.toUpperCase() ) + '</span>' +
							'<strong>' + escAttr( c.label ) + '</strong>' +
							'<span class="flowsmtp-check-detail">' + escAttr( c.detail ) + '</span>' +
							'</li>';
					} );

					$out.html( '<ul class="flowsmtp-checklist">' + html + '</ul>' ).prop( 'hidden', false );
				} )
				.fail( function () {
					$out.html( '<div class="flowsmtp-notice is-error">Request failed. Please try again.</div>' ).prop( 'hidden', false );
				} )
				.always( function () {
					$btn.prop( 'disabled', false ).text( original );
				} );
		} );

		/* Resend */
		$( document ).on( 'click', '.flowsmtp-resend', function () {
			var $btn = $( this ),
				original = $btn.text();

			$btn.prop( 'disabled', true ).text( FlowSMTP.i18n.resending );

			ajax( 'flowsmtp_resend', { id: $btn.data( 'id' ) } )
				.done( function ( res ) {
					window.alert( res.data.message );
					if ( res.success ) {
						window.location.reload();
					}
				} )
				.always( function () {
					$btn.prop( 'disabled', false ).text( original );
				} );
		} );

		/* View log detail */
		$( document ).on( 'click', '.flowsmtp-view', function () {
			var id = $( this ).data( 'id' );

			ajax( 'flowsmtp_view_log', { id: id } ).done( function ( res ) {
				if ( ! res.success ) {
					window.alert( res.data.message );
					return;
				}

				var d = res.data,
					html =
						'<h3>' + $( '<span>' ).text( d.subject ).html() + '</h3>' +
						'<dl>' +
						'<dt>To</dt><dd>' + $( '<span>' ).text( d.to ).html() + '</dd>' +
						'<dt>Status</dt><dd>' + $( '<span>' ).text( d.status ).html() + '</dd>' +
						'<dt>Date</dt><dd>' + $( '<span>' ).text( d.date ).html() + '</dd>' +
						'<dt>Retries</dt><dd>' + d.retries + '</dd>' +
						( d.error ? '<dt>Error</dt><dd>' + $( '<span>' ).text( d.error ).html() + '</dd>' : '' ) +
						( d.headers ? '<dt>Headers</dt><dd>' + $( '<span>' ).text( d.headers ).html() + '</dd>' : '' ) +
						'</dl>' +
						/* Body is rendered inside a sandboxed iframe: no scripts, no forms, no navigation, no referrer. */
						'<iframe class="flowsmtp-modal-body" sandbox referrerpolicy="no-referrer" srcdoc="' + escAttr( d.message ) + '"></iframe>';

				$( '#flowsmtp-modal .flowsmtp-modal-content' ).html( html );
				$( '#flowsmtp-modal' ).prop( 'hidden', false );
			} );
		} );

		/* Modal close */
		$( document ).on( 'click', '.flowsmtp-modal-close, .flowsmtp-modal-backdrop', function () {
			$( '#flowsmtp-modal' ).prop( 'hidden', true );
		} );

		/* Select all */
		$( '#flowsmtp-check-all' ).on( 'change', function () {
			$( '.flowsmtp-check' ).prop( 'checked', this.checked );
		} );

		/* Bulk delete */
		$( '#flowsmtp-delete-selected' ).on( 'click', function () {
			var ids = $( '.flowsmtp-check:checked' )
				.map( function () {
					return this.value;
				} )
				.get();

			if ( ! ids.length ) {
				return;
			}

			if ( ! window.confirm( FlowSMTP.i18n.confirmDelete ) ) {
				return;
			}

			ajax( 'flowsmtp_delete_logs', { ids: ids } ).done( function () {
				window.location.reload();
			} );
		} );
	} );
} )( jQuery );
