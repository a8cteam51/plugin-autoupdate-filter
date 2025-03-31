( function( $ ) {
	'use strict';

	$( document ).ready( function() {
		const $logContent = $( '#log-content' );
		const $logSelect = $( '#log-file-select' );
		const $spinner = $( '#log-loading-spinner' );

		// Handle log file selection
		$logSelect.on( 'change', function() {
			const date = $(this).val();
			if ( ! date ) {
				$logContent.slideUp();
				return;
			}

			$spinner.addClass( 'is-active' );

			$.ajax({
				url: pluginAutoupdateFilter.ajaxUrl,
				data: {
					action: 'get_log_content',
					date: date,
					nonce: pluginAutoupdateFilter.nonce
				},
				success: function( response ) {
					$spinner.removeClass( 'is-active' );

					if ( response.success ) {
						$logContent.find( 'pre' ).text( response.data );
						$logContent.slideDown();
					} else {
						alert( 'Error loading log file: ' + response.data );
					}
				},
				error: function( jqXHR, textStatus, errorThrown ) {
					$spinner.removeClass( 'is-active' );
					alert( 'AJAX error: ' + textStatus + ' - ' + errorThrown );
				}
			});
		});

		// Update visibility when logging is disabled
		$( 'input[name="plugin_autoupdate_filter_enable_logging"]' ).on( 'change', function() {
			if ( ! this.checked ) {
				$logContent.slideUp();
				$logSelect.val( '' );
				$spinner.removeClass( 'is-active' );
			}
		});
	});

})( jQuery );