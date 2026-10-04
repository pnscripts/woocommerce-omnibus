/**
 * PN Omnibus settings: unlock the rule fields when "Custom rules" is selected.
 */
( function () {
	'use strict';
	var preset = document.getElementById( 'pnscripts-omnibus-preset' );
	if ( ! preset ) {
		return;
	}
	preset.addEventListener( 'change', function () {
		var custom = 'custom' === preset.value;
		var period = document.getElementById( 'pnscripts-omnibus-period' );
		if ( period ) {
			period.readOnly = ! custom;
		}
		document
			.querySelectorAll( 'input[name="pnscripts_omnibus_settings[new_product_mode]"], input[name="pnscripts_omnibus_settings[perishable_exemption]"]' )
			.forEach( function ( input ) {
				input.disabled = ! custom;
			} );
	} );
} )();
