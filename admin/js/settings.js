(function () {
	// ── Settings tabs ─────────────────────────────────────────────────────
	var tabButtons = document.querySelectorAll( '.co-settings-tab' );
	var tabPanels  = document.querySelectorAll( '.co-settings-panel' );
	var STORAGE_KEY = 'co_settings_active_tab';

	function activateTab( tabId ) {
		var found = false;
		for ( var i = 0; i < tabButtons.length; i++ ) {
			var isMatch = tabButtons[ i ].getAttribute( 'data-tab' ) === tabId;
			tabButtons[ i ].classList.toggle( 'active', isMatch );
			if ( isMatch ) { found = true; }
		}
		for ( var j = 0; j < tabPanels.length; j++ ) {
			tabPanels[ j ].classList.toggle( 'active', tabPanels[ j ].getAttribute( 'data-panel' ) === tabId );
		}
		return found;
	}

	if ( tabButtons.length && tabPanels.length ) {
		for ( var k = 0; k < tabButtons.length; k++ ) {
			tabButtons[ k ].addEventListener( 'click', function () {
				var tabId = this.getAttribute( 'data-tab' );
				activateTab( tabId );
				try {
					window.localStorage.setItem( STORAGE_KEY, tabId );
				} catch ( e ) { /* localStorage unavailable — inactive tab just won't persist across reloads */ }
			} );
		}

		var restored = null;
		try {
			restored = window.localStorage.getItem( STORAGE_KEY );
		} catch ( e ) { /* localStorage unavailable */ }

		if ( ! restored || ! activateTab( restored ) ) {
			activateTab( tabButtons[ 0 ].getAttribute( 'data-tab' ) );
		}
	}

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

	// ── Payment Provider toggle ──────────────────────────────────────────────
	var providerSelect  = document.getElementById( 'co-payment-provider' );
	var stripeFieldEls  = document.querySelectorAll( '.co-gateway-fields--stripe' );
	var paypalFieldEls  = document.querySelectorAll( '.co-gateway-fields--paypal' );

	if ( providerSelect && ( stripeFieldEls.length || paypalFieldEls.length ) ) {
		providerSelect.addEventListener( 'change', function () {
			var showPaypal = 'paypal' === providerSelect.value;
			for ( var i = 0; i < stripeFieldEls.length; i++ ) {
				stripeFieldEls[ i ].style.display = showPaypal ? 'none' : '';
			}
			for ( var j = 0; j < paypalFieldEls.length; j++ ) {
				paypalFieldEls[ j ].style.display = showPaypal ? '' : 'none';
			}
		} );
	}

	// ── Lead Capture CAPTCHA provider toggle ─────────────────────────────────
	var captchaSelect     = document.getElementById( 'co-lead-captcha-provider' );
	var turnstileFieldsEl = document.getElementById( 'co-lead-turnstile-fields' );

	if ( captchaSelect && turnstileFieldsEl ) {
		captchaSelect.addEventListener( 'change', function () {
			turnstileFieldsEl.style.display = 'turnstile' === captchaSelect.value ? '' : 'none';
		} );
	}

	// ── Webhook URL copy buttons ─────────────────────────────────────────────
	// navigator.clipboard is only available in secure contexts (HTTPS/localhost),
	// so fall back to a hidden-textarea + execCommand('copy') on plain HTTP dev sites.
	var copyButtons = document.querySelectorAll( '.co-copy-btn[data-copy-target]' );

	for ( var k = 0; k < copyButtons.length; k++ ) {
		( function ( btn ) {
			var targetInput = document.getElementById( btn.getAttribute( 'data-copy-target' ) );
			if ( ! targetInput ) {
				return;
			}

			btn.addEventListener( 'click', function () {
				var originalLabel = btn.textContent;
				var showCopied = function () {
					btn.textContent = 'Copied!';
					setTimeout( function () {
						btn.textContent = originalLabel;
					}, 2000 );
				};
				var fallbackCopy = function () {
					var scratch = document.createElement( 'textarea' );
					scratch.value = targetInput.value;
					scratch.style.position = 'fixed';
					scratch.style.opacity = '0';
					document.body.appendChild( scratch );
					scratch.focus();
					scratch.select();
					try {
						document.execCommand( 'copy' );
						showCopied();
					} catch ( err ) {
						// Copy failed silently — nothing more we can do without user gesture context.
					}
					document.body.removeChild( scratch );
				};

				if ( window.navigator.clipboard && window.isSecureContext ) {
					window.navigator.clipboard.writeText( targetInput.value ).then( showCopied, fallbackCopy );
				} else {
					fallbackCopy();
				}
			} );
		}( copyButtons[ k ] ) );
	}
}());
