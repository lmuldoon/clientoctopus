(function () {
	var picker   = document.getElementById( 'co-brand-color-picker' );
	var hexInput = document.getElementById( 'co-brand-color-hex' );
	if ( picker && hexInput ) {
		picker.addEventListener( 'input', function () { hexInput.value = picker.value; } );
		hexInput.addEventListener( 'input', function () {
			if ( /^#[0-9A-Fa-f]{6}$/.test( hexInput.value.trim() ) ) {
				picker.value = hexInput.value.trim();
			}
		} );
		hexInput.addEventListener( 'blur', function () {
			if ( ! /^#[0-9A-Fa-f]{6}$/.test( hexInput.value.trim() ) ) {
				hexInput.value = picker.value;
			}
		} );
	}
	var logoInput   = document.getElementById( 'co-logo-url-input' );
	var logoPreview = document.getElementById( 'co-logo-preview' );
	var logoWrap    = document.getElementById( 'co-logo-preview-wrap' );
	if ( logoInput && logoPreview && logoWrap ) {
		logoInput.addEventListener( 'input', function () {
			var url = logoInput.value.trim();
			if ( url ) {
				logoPreview.src        = url;
				logoWrap.style.display = 'inline-flex';
			} else {
				logoWrap.style.display = 'none';
				logoPreview.src        = '';
			}
		} );
	}
}());
