/**
 * Utility functions for HTMLForm date picker
 */
( function ( mw, $ ) {

	mw.hook( 'htmlform.enhance' ).add( function ( $root ) {
		var inputs, i;

		inputs = $root.find( 'input[type=date]' );
		if ( inputs.length === 0 ) {
			return;
		}

		// Assume that if the browser implements validation for <input type=date>
		// (so it rejects "bogus" as a value) then it supports a date picker too.
		i = document.createElement( 'input' );
		i.setAttribute( 'type', 'date' );
		i.value = 'bogus';
		if ( i.value === 'bogus' ) {
			mw.loader.using( 'jquery.ui.datepicker', function () {
				inputs.each( function () {
					var $i = $( this );
					// Reset the type, Just In Case
					$i.prop( 'type', 'text' );
					$i.datepicker( {
						dateFormat: 'yy-mm-dd',
						constrainInput: true,
						showOn: 'focus',
						changeMonth: true,
						changeYear: true,
						showButtonPanel: true,
						minDate: $i.data( 'min' ),
						maxDate: $i.data( 'max' ),
					} );
				} );
			} );
		}
	} );

}( mediaWiki, jQuery ) );
