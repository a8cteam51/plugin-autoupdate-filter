( function( $ ) {
	'use strict';

	$( document ).ready( function() {
		const $logContent = $( '#log-content' );
		const $logSelect = $( '#log-file-select' );

		// Handle log file selection
		$logSelect.on( 'change', function() {
			const date = $(this).val();
			if ( ! date ) {
				$logContent.slideUp();
				return;
			}

			$.ajax({
				url: pluginAutoupdateFilter.ajaxUrl,
				data: {
					action: 'get_log_content',
					date: date,
					nonce: pluginAutoupdateFilter.nonce
				},
				success: function( response ) {
					if ( response.success ) {
						$logContent.find('pre').text( response.data );
						$logContent.slideDown();
					} else {
						// Show the actual error message
						alert( 'Error loading log file: ' + response.data );
					}
				},
				error: function(jqXHR, textStatus, errorThrown) {
					alert( 'AJAX error: ' + textStatus + ' - ' + errorThrown );
				}
			});
		});

		// Update visibility when logging is disabled
		$( 'input[name="plugin_autoupdate_filter_enable_logging"]' ).on( 'change', function() {
			if ( ! this.checked ) {
				$logContent.slideUp();
				$logSelect.val('');
			}
		});
	});

})( jQuery );