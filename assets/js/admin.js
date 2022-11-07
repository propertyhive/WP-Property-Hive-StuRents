jQuery( function ( $ ) {

	$( '#toggle_sturents_import_data_div' ).click(function(e)
	{
		e.preventDefault();

		if ( $( '#sturents_import_data_div' ).is(":visible") )
		{
			$( '#sturents_import_data_div' ).hide();
			$(this).text('Show Import Data');
		}
		else
		{
			$( '#sturents_import_data_div' ).show();
			$(this).text('Hide Import Data');
		}
	});
});