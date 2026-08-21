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

	$( function () {
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
						'<div class="flowsmtp-modal-body">' + d.message + '</div>';

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
