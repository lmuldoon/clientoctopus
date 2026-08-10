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
	var btnValue   = document.getElementById( 'co-button-color-value' );
	var btnPicker  = document.getElementById( 'co-button-color-picker' );
	var btnHex     = document.getElementById( 'co-button-color-hex' );
	var btnClear   = document.getElementById( 'co-button-color-clear' );
	if ( btnValue && btnPicker && btnHex && btnClear ) {
		btnPicker.addEventListener( 'input', function () {
			btnValue.value        = btnPicker.value;
			btnHex.value          = btnPicker.value;
			btnClear.style.display = '';
		} );
		btnHex.addEventListener( 'input', function () {
			var val = btnHex.value.trim();
			if ( '' === val ) {
				btnValue.value          = '';
				btnClear.style.display  = 'none';
			} else if ( /^#[0-9A-Fa-f]{6}$/.test( val ) ) {
				btnValue.value          = val;
				btnPicker.value         = val;
				btnClear.style.display  = '';
			}
		} );
		btnHex.addEventListener( 'blur', function () {
			var val = btnHex.value.trim();
			if ( '' !== val && ! /^#[0-9A-Fa-f]{6}$/.test( val ) ) {
				btnHex.value   = btnValue.value;
			}
		} );
		btnClear.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var brandHex = ( document.getElementById( 'co-brand-color-hex' ) || {} ).value || '#6366f1';
			btnValue.value          = '';
			btnHex.value            = '';
			btnPicker.value         = brandHex;
			btnClear.style.display  = 'none';
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

	var bgInput      = document.getElementById( 'co-login-bg-url-input' );
	var bgPreview    = document.getElementById( 'co-login-bg-preview' );
	var bgWrap       = document.getElementById( 'co-login-bg-preview-wrap' );
	var bgSelectBtn  = document.getElementById( 'co-login-bg-select-btn' );
	var bgRemoveBtn  = document.getElementById( 'co-login-bg-remove-btn' );
	if ( bgInput && bgPreview && bgWrap && bgSelectBtn && bgRemoveBtn && window.wp && wp.media ) {
		var bgFrame = null;

		bgSelectBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			if ( ! bgFrame ) {
				bgFrame = wp.media( {
					title:    'Select Login Background Image',
					library:  { type: 'image' },
					multiple: false,
					button:   { text: 'Use this image' },
				} );
				bgFrame.on( 'select', function () {
					var attachment = bgFrame.state().get( 'selection' ).first().toJSON();
					var url = attachment.url;
					bgInput.value           = url;
					bgPreview.src           = url;
					bgWrap.style.display    = 'inline-flex';
					bgRemoveBtn.style.display = '';
					bgSelectBtn.textContent = 'Change Image';
				} );
			}
			bgFrame.open();
		} );

		bgRemoveBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			bgInput.value             = '';
			bgPreview.src             = '';
			bgWrap.style.display      = 'none';
			bgRemoveBtn.style.display = 'none';
			bgSelectBtn.textContent   = 'Select Image';
		} );
	}
}());
