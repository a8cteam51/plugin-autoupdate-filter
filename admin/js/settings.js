( function( $ ) {
	'use strict';

	$( document ).ready( function() {
		const $logsTable = $( '.plugin-autoupdate-filter-logs-table-wrap' );
		const $toggleButton = $( '<button>', {
			text: 'Show Log Files',
			class: 'button button-secondary',
			css: { 'margin-left': '10px' }
		});

		// Initially hide the table
		$logsTable.hide();

		// Add the toggle button after the h2
		$( '.plugin-autoupdate-filter-logs h2' ).append( $toggleButton );

		// Toggle table visibility when button is clicked
		$toggleButton.on( 'click', function( e ) {
			e.preventDefault();
			$logsTable.slideToggle( 'fast' );
			$toggleButton.text( $logsTable.is( ':visible' ) ? 'Hide Log Files' : 'Show Log Files' );
		});

		// Update table visibility when logging is enabled/disabled
		$( 'input[name="plugin_autoupdate_filter_enable_logging"]' ).on( 'change', function() {
			if ( ! this.checked ) {
				$logsTable.slideUp( 'fast' );
				$toggleButton.text( 'Show Log Files' );
			}
		});
	});

})( jQuery );