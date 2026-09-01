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

	function escHtml( s ) {
		return $( '<span>' ).text( String( s ) ).html();
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
					notice( $result, 'error', FlowSMTP.i18n.requestFailed );
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
						$out.html( '<div class="flowsmtp-notice is-error">' + escHtml( res.data.message ) + '</div>' ).prop( 'hidden', false );
						return;
					}

					var html = '';
					$.each( res.data.checks, function ( i, c ) {
						html +=
							'<li>' +
							'<span class="flowsmtp-badge ' + ( badge[ c.status ] || 'is-pending' ) + '">' + escHtml( c.status.toUpperCase() ) + '</span>' +
							'<strong>' + escHtml( c.label ) + '</strong>' +
							'<span class="flowsmtp-check-detail">' + escHtml( c.detail ) + '</span>' +
							'</li>';
					} );

					$out.html( '<ul class="flowsmtp-checklist">' + html + '</ul>' ).prop( 'hidden', false );
				} )
				.fail( function () {
					$out.html( '<div class="flowsmtp-notice is-error">' + escHtml( FlowSMTP.i18n.requestFailed ) + '</div>' ).prop( 'hidden', false );
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
					attachments = '';

				if ( d.attachments && d.attachments.length ) {
					attachments = '<ul class="flowsmtp-attachments">';
					$.each( d.attachments, function ( i, a ) {
						attachments +=
							'<li title="' + escAttr( a.path ) + '">' +
							'<span class="dashicons dashicons-paperclip"></span>' +
							'<span class="flowsmtp-attachment-name">' + escHtml( a.name ) + '</span>' +
							( a.size ? '<span class="flowsmtp-attachment-size">' + escHtml( a.size ) + '</span>' : '' ) +
							( a.exists ? '' : '<span class="flowsmtp-badge is-failed">' + escHtml( FlowSMTP.i18n.missingFile ) + '</span>' ) +
							'</li>';
					} );
					attachments += '</ul>';
				}

				var html =
					'<h3>' + escHtml( d.subject ) + '</h3>' +
					'<dl>' +
					'<dt>To</dt><dd>' + escHtml( d.to ) + '</dd>' +
					'<dt>Status</dt><dd>' + escHtml( d.status ) + '</dd>' +
					'<dt>Date</dt><dd>' + escHtml( d.date ) + '</dd>' +
					'<dt>Format</dt><dd>' + escHtml( d.format ) + '</dd>' +
					'<dt>Retries</dt><dd>' + d.retries + '</dd>' +
					( d.error ? '<dt>Error</dt><dd>' + escHtml( d.error ) + '</dd>' : '' ) +
					( d.headers ? '<dt>Headers</dt><dd>' + escHtml( d.headers ) + '</dd>' : '' ) +
					( attachments ? '<dt>Attachments</dt><dd>' + attachments + '</dd>' : '' ) +
					'</dl>' +
					/* Preview switcher: rendered body vs. raw source. */
					'<div class="flowsmtp-preview-tabs">' +
					'<button type="button" class="flowsmtp-preview-tab is-active" data-view="rendered">' + escHtml( FlowSMTP.i18n.rendered ) + '</button>' +
					'<button type="button" class="flowsmtp-preview-tab" data-view="source">' + escHtml( FlowSMTP.i18n.source ) + '</button>' +
					'</div>' +
					/* Body is rendered inside a sandboxed iframe: no scripts, no forms, no navigation, no referrer. */
					'<iframe class="flowsmtp-modal-body flowsmtp-preview-pane" data-view="rendered" sandbox referrerpolicy="no-referrer" srcdoc="' + escAttr( d.message ) + '"></iframe>' +
					'<pre class="flowsmtp-source flowsmtp-preview-pane" data-view="source" hidden>' + escHtml( d.raw ) + '</pre>' +
					/* Send a copy of this email elsewhere for a real-client check. */
					'<div class="flowsmtp-preview-send">' +
					'<label for="flowsmtp-preview-to">' + escHtml( FlowSMTP.i18n.previewIntro ) + '</label>' +
					'<div class="flowsmtp-preview-send-row">' +
					'<input type="email" id="flowsmtp-preview-to" value="' + escAttr( FlowSMTP.currentUser || '' ) + '" />' +
					'<button type="button" class="flowsmtp-btn flowsmtp-send-preview" data-id="' + escAttr( id ) + '">' + escHtml( FlowSMTP.i18n.sendPreview ) + '</button>' +
					'</div>' +
					'<div class="flowsmtp-notice flowsmtp-preview-result" hidden></div>' +
					'</div>';

				$( '#flowsmtp-modal .flowsmtp-modal-content' ).html( html );
				$( '#flowsmtp-modal' ).prop( 'hidden', false );
			} );
		} );

		/* Preview tabs: rendered <-> source */
		$( document ).on( 'click', '.flowsmtp-preview-tab', function () {
			var view = $( this ).data( 'view' );

			$( '.flowsmtp-preview-tab' ).removeClass( 'is-active' );
			$( this ).addClass( 'is-active' );

			$( '.flowsmtp-preview-pane' ).each( function () {
				$( this ).prop( 'hidden', $( this ).data( 'view' ) !== view );
			} );
		} );

		/* Send a copy of a logged email to any address */
		$( document ).on( 'click', '.flowsmtp-send-preview', function () {
			var $btn = $( this ),
				$result = $( '.flowsmtp-preview-result' ),
				original = $btn.text();

			$btn.prop( 'disabled', true ).text( FlowSMTP.i18n.sending );
			$result.prop( 'hidden', true );

			ajax( 'flowsmtp_preview_send', {
				id: $btn.data( 'id' ),
				to: $( '#flowsmtp-preview-to' ).val()
			} )
				.done( function ( res ) {
					notice( $result, res.success ? 'success' : 'error', res.data.message );
				} )
				.fail( function () {
					notice( $result, 'error', FlowSMTP.i18n.requestFailed );
				} )
				.always( function () {
					$btn.prop( 'disabled', false ).text( original );
				} );
		} );

		/* Modal close */
		$( document ).on( 'click', '.flowsmtp-modal-close, .flowsmtp-modal-backdrop', function () {
			$( '#flowsmtp-modal' ).prop( 'hidden', true );
		} );

		/* Close the modal with Escape */
		$( document ).on( 'keyup', function ( e ) {
			if ( 27 === e.keyCode ) {
				$( '#flowsmtp-modal' ).prop( 'hidden', true );
			}
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
