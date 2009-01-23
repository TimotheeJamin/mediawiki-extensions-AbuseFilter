function doExprSubmit()
{
	var expr = document.getElementById('wpTestExpr').value;
	injectSpinner( document.getElementById( 'mw-abusefilter-submitexpr' ), 'abusefilter-expr' );
	sajax_do_call( 'AbuseFilter::ajaxEvaluateExpression', [expr], processExprResult );
}
function processExprResult( request ) {
	var response = request.responseText;

	removeSpinner( 'abusefilter-expr' );

	var el = document.getElementById( 'mw-abusefilter-expr-result' );
	changeText( el, response );
}
function doReautoSubmit()
{
	var name = document.getElementById('reautoconfirm-user').value;
	injectSpinner( document.getElementById( 'mw-abusefilter-reautoconfirmsubmit' ), 'abusefilter-reautoconfirm' );
	sajax_do_call( 'AbuseFilter::ajaxReAutoconfirm', [name], processReautoconfirm );
}
function processReautoconfirm( request ) {
	var response = request.responseText;

	if (strlen(response)) {
		jsMsg( response );
	}

	removeSpinner( 'abusefilter-reautoconfirm' );
}
