/**
 * Breeze Modal Checkout — legacy shortcode integration.
 *
 * Intercepts the WC `checkout_place_order_breeze_payment_gateway` event,
 * POSTs the serialised checkout form to a nonce-protected admin-ajax
 * endpoint that creates the order + the Breeze payment page, then opens
 * the returned payment URL inside a modal/lightbox.
 *
 * Localised state on window.breezeModalData:
 *   ajaxUrl, nonce, storeName, currency, checkoutUrl,
 *   siteDomain, breezeOrigin, breezeHost, debug, gatewayData
 */

( function ( $ ) {
    'use strict';

    var GATEWAY_ID = 'breeze_payment_gateway';
    var MODAL_ID   = 'breeze-modal-overlay';
    var POLL_MS    = 800;
    var TIMEOUT_MS = 30000;

    var SUCCESS_EVENTS = {
        payin_action_card_payment_success:      true,
        payin_action_apple_pay_payment_success: true,
        payin_action_google_pay_payment_success: true,
    };

    var data  = window.breezeModalData || {};
    var debug = !! data.debug;

    function log() {
        if ( ! debug ) return;
        try { console.log.apply( console, [ '[Breeze Modal]' ].concat( [].slice.call( arguments ) ) ); }
        catch ( e ) {}
    }

    var modalOpen        = false;
    var pollTimer        = null;
    var iframeEl         = null;
    var overlayEl        = null;
    var currentOrderId    = null;
    var currentSuccessUrl = '';
    var paymentConfirmed  = false;

    function getBreezeDomains() {
        return Array.isArray( data.breezeDomains ) ? data.breezeDomains : [];
    }

    /**
     * True if `host` is exactly one of the configured Breeze base domains,
     * or a subdomain of one (matched on a dot boundary so `evilbreeze.com`
     * is rejected when `breeze.com` is allowed).
     */
    function hostIsAllowed( host ) {
        if ( ! host ) return false;
        var lower = String( host ).toLowerCase();
        var allowed = getBreezeDomains();
        for ( var i = 0; i < allowed.length; i++ ) {
            var base = String( allowed[ i ] ).toLowerCase();
            if ( ! base ) continue;
            if ( lower === base || lower.endsWith( '.' + base ) ) return true;
        }
        return false;
    }

    function isBreezePaymentUrl( url ) {
        if ( ! url ) return false;
        try {
            var parsed = new URL( url, window.location.href );
            return hostIsAllowed( parsed.hostname );
        } catch ( e ) {
            return false;
        }
    }

    function isBreezeOrigin( origin ) {
        if ( ! origin ) return false;
        try {
            var parsed = new URL( origin );
            return hostIsAllowed( parsed.hostname );
        } catch ( e ) {
            return false;
        }
    }

    /* ── Init ────────────────────────────────────────────── */
    function init() {
        if ( ! data || ! data.ajaxUrl ) {
            log( 'breezeModalData missing — modal will not initialise.' );
            return;
        }
        buildModal();
        bindCheckoutHook();
        window.addEventListener( 'message', onPostMessage );
    }

    /* ── Bind WC checkout submission ─────────────────────── */
    function bindCheckoutHook() {
        // WC's own event fires after WC validation passes. Returning false
        // prevents WC's default submit.
        $( document.body ).on( 'checkout_place_order_' + GATEWAY_ID, function () {
            handlePlaceOrder();
            return false;
        } );

        // Fallback for themes that replace wc-checkout.js — capture the
        // raw form submit at the capture phase so we beat other handlers.
        var checkoutForm = document.querySelector( 'form.checkout' );
        if ( ! checkoutForm ) return;

        checkoutForm.addEventListener( 'submit', function ( e ) {
            var selected = document.getElementById( 'payment_method_' + GATEWAY_ID );
            if ( selected && selected.checked && typeof window.wc_checkout_params === 'undefined' ) {
                e.preventDefault();
                e.stopImmediatePropagation();
                handlePlaceOrder();
            }
        }, true );
    }

    /* ── Place order via AJAX ────────────────────────────── */
    function handlePlaceOrder() {
        if ( modalOpen ) return;
        if ( ! validateCheckoutForm() ) return;

        setLoading( true );

        var formData = $( 'form.checkout' ).serialize();

        // WC validates _wp_http_referer against the checkout page URL.
        // jQuery.serialize() captures whatever the last AJAX call set it to,
        // which is usually wc-ajax=update_order_review.
        var checkoutPath = data.checkoutUrl ? new URL( data.checkoutUrl ).pathname : '/checkout/';
        formData = formData.replace( /_wp_http_referer=[^&]*/, '_wp_http_referer=' + encodeURIComponent( checkoutPath ) );

        if ( $( 'input[name="terms"]' ).length && formData.indexOf( 'terms=' ) === -1 ) {
            formData += '&terms=1&terms-field=1';
        }
        if ( formData.indexOf( 'ship_to_different_address' ) === -1 ) {
            formData += '&ship_to_different_address=0';
        }

        $.ajax( {
            url:     data.ajaxUrl,
            type:    'POST',
            timeout: TIMEOUT_MS,
            data: {
                action: 'breeze_create_modal_payment',
                nonce:  data.nonce,
                form:   formData,
            },
            success: function ( response ) {
                setLoading( false );
                if ( ! response || ! response.success ) {
                    var msg = ( response && response.data && response.data.message )
                        ? response.data.message
                        : 'Payment setup failed. Please try again.';
                    showError( msg );
                    return;
                }
                var paymentUrl    = response.data.paymentUrl;
                currentOrderId    = response.data.orderId || null;
                currentSuccessUrl = response.data.successUrl || '';
                if ( ! paymentUrl ) {
                    showError( 'No payment URL returned. Please try again.' );
                    return;
                }
                if ( ! isBreezePaymentUrl( paymentUrl ) ) {
                    log( 'Refusing to load non-Breeze payment URL', paymentUrl );
                    showError( 'Unexpected payment URL received. Please try again.' );
                    return;
                }
                openModal( paymentUrl );
            },
            error: function ( xhr, status ) {
                setLoading( false );
                if ( status === 'timeout' ) {
                    showError( 'Request timed out. Please check your connection and try again.' );
                } else {
                    showError( 'An unexpected error occurred. Please try again.' );
                }
            },
        } );
    }

    function validateCheckoutForm() {
        if ( typeof window.wc_checkout_form !== 'undefined' && window.wc_checkout_form.validate ) {
            return window.wc_checkout_form.validate();
        }
        var valid = true;
        $( 'form.checkout .validate-required' ).each( function () {
            var field = $( this ).find( 'input, select, textarea' ).first();
            if ( field.val() === '' || field.val() === null ) {
                $( this ).addClass( 'woocommerce-invalid woocommerce-invalid-required-field' );
                valid = false;
            }
        } );
        if ( ! valid ) {
            var invalid = $( '.woocommerce-invalid' ).first();
            if ( invalid.length ) {
                $( 'html, body' ).animate( { scrollTop: invalid.offset().top - 100 }, 400 );
            }
        }
        return valid;
    }

    /* ── Modal DOM ───────────────────────────────────────── */
    function buildModal() {
        if ( document.getElementById( MODAL_ID ) ) return;

        var storeName = data.storeName || 'Checkout';

        overlayEl = document.createElement( 'div' );
        overlayEl.id = MODAL_ID;
        overlayEl.setAttribute( 'role', 'dialog' );
        overlayEl.setAttribute( 'aria-modal', 'true' );
        overlayEl.setAttribute( 'aria-label', 'Complete your payment' );
        overlayEl.innerHTML = [
            '<div class="breeze-modal-backdrop"></div>',
            '<div class="breeze-modal-container">',
                '<div class="breeze-modal-header">',
                    '<div class="breeze-modal-header-left">',
                        '<div class="breeze-modal-lock-icon">',
                            '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">',
                                '<path d="M7 11V7a5 5 0 0 1 10 0v4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
                                '<rect x="3" y="11" width="18" height="11" rx="2" stroke="currentColor" stroke-width="2"/>',
                                '<circle cx="12" cy="16" r="1.5" fill="currentColor"/>',
                            '</svg>',
                        '</div>',
                        '<span class="breeze-modal-title">Secure Checkout</span>',
                        '<span class="breeze-modal-store">' + escapeHtml( storeName ) + '</span>',
                    '</div>',
                    '<button class="breeze-modal-close" aria-label="Close payment window" type="button">',
                        '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">',
                            '<path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
                        '</svg>',
                    '</button>',
                '</div>',
                '<div class="breeze-modal-body">',
                    '<div class="breeze-modal-loading" id="breeze-iframe-loading">',
                        '<div class="breeze-spinner"></div>',
                        '<p>Loading secure payment page…</p>',
                    '</div>',
                    '<iframe',
                        ' id="breeze-payment-iframe"',
                        ' class="breeze-modal-iframe"',
                        ' title="Breeze Secure Payment"',
                        ' allow="payment *; camera *; accelerometer *; gyroscope *; microphone *"',
                        ' sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-modals allow-popups-to-escape-sandbox"',
                    '></iframe>',
                '</div>',
                '<div class="breeze-modal-footer">',
                    '<div class="breeze-modal-security-badges">',
                        '<span class="breeze-badge">256-bit SSL</span>',
                        '<span class="breeze-badge">Powered by Breeze</span>',
                    '</div>',
                    '<button class="breeze-modal-cancel" type="button">Cancel &amp; return to checkout</button>',
                '</div>',
            '</div>',
        ].join( '' );

        document.body.appendChild( overlayEl );

        overlayEl.querySelector( '.breeze-modal-close'    ).addEventListener( 'click', function () { closeModal( 'user-close' ); } );
        overlayEl.querySelector( '.breeze-modal-cancel'   ).addEventListener( 'click', function () { closeModal( 'user-cancel' ); } );
        overlayEl.querySelector( '.breeze-modal-backdrop' ).addEventListener( 'click', function () { closeModal( 'backdrop' ); } );

        document.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'Escape' && modalOpen ) closeModal( 'escape' );
        } );

        iframeEl = overlayEl.querySelector( '#breeze-payment-iframe' );

        iframeEl.addEventListener( 'load', function () {
            if ( ! iframeEl.src || iframeEl.src === 'about:blank' ) return;
            var loading = overlayEl.querySelector( '#breeze-iframe-loading' );
            if ( loading ) loading.style.display = 'none';
            iframeEl.style.opacity = '1';
        } );
    }

    /* ── postMessage (origin-validated) ──────────────────── */
    function onPostMessage( event ) {
        if ( ! isBreezeOrigin( event.origin ) ) return;
        if ( ! event.data || typeof event.data !== 'object' ) return;

        if ( event.data.type === 'request-global-config' && event.source ) {
            var domain = data.siteDomain || '';
            event.source.postMessage(
                {
                    type:   'request-global-config',
                    config: {
                        applePayEnabled: !! domain,
                        crossDomainName: domain,
                    },
                },
                event.origin
            );
            return;
        }

        if ( ! modalOpen || event.data.type !== 'on-payment-event' ) return;
        var eventName = event.data.data && event.data.data.eventName;
        if ( ! eventName ) return;

        if ( SUCCESS_EVENTS[ eventName ] && ! paymentConfirmed ) {
            paymentConfirmed = true;
            showModalSuccessState();
            return;
        }
        if ( eventName === 'payin_action_3ds_requested' )      { expandModalFor3DS( true );  return; }
        if ( eventName === 'payin_action_3ds_cancelled' )      { expandModalFor3DS( false ); return; }
        if ( eventName === 'payin_action_card_input_validation_error' ) { shakeModal(); }
    }

    function showModalSuccessState() {
        var body = overlayEl && overlayEl.querySelector( '.breeze-modal-body' );
        if ( ! body ) return;
        if ( body.querySelector( '.breeze-payment-confirmed' ) ) return;

        var confirm = document.createElement( 'div' );
        confirm.className = 'breeze-payment-confirmed';
        confirm.innerHTML = [
            '<div class="breeze-confirm-icon">',
                '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">',
                    '<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>',
                    '<path d="M8 12l3 3 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
                '</svg>',
            '</div>',
            '<p>Payment confirmed!</p>',
            '<p class="breeze-confirm-sub">Completing your order…</p>',
        ].join( '' );
        body.appendChild( confirm );
        requestAnimationFrame( function () { confirm.classList.add( 'is-visible' ); } );

        var closeBtn  = overlayEl.querySelector( '.breeze-modal-close' );
        var cancelBtn = overlayEl.querySelector( '.breeze-modal-cancel' );
        if ( closeBtn )  closeBtn.disabled  = true;
        if ( cancelBtn ) cancelBtn.disabled = true;
    }

    function expandModalFor3DS( expand ) {
        var c = overlayEl && overlayEl.querySelector( '.breeze-modal-container' );
        if ( c ) c.classList.toggle( 'is-3ds', expand );
    }

    function shakeModal() {
        var c = overlayEl && overlayEl.querySelector( '.breeze-modal-container' );
        if ( ! c ) return;
        c.classList.remove( 'is-shaking' );
        void c.offsetWidth;
        c.classList.add( 'is-shaking' );
        c.addEventListener( 'animationend', function onEnd() {
            c.classList.remove( 'is-shaking' );
            c.removeEventListener( 'animationend', onEnd );
        } );
    }

    /* ── Modal open / close ──────────────────────────────── */
    function openModal( paymentUrl ) {
        buildModal();
        paymentConfirmed = false;

        var loading = overlayEl.querySelector( '#breeze-iframe-loading' );
        if ( loading ) loading.style.display = 'flex';
        iframeEl.style.opacity = '0';

        var closeBtn  = overlayEl.querySelector( '.breeze-modal-close' );
        var cancelBtn = overlayEl.querySelector( '.breeze-modal-cancel' );
        if ( closeBtn )  closeBtn.disabled  = false;
        if ( cancelBtn ) cancelBtn.disabled = false;

        var domain = data.siteDomain || '';
        if ( domain ) {
            var sep = paymentUrl.indexOf( '?' ) !== -1 ? '&' : '?';
            iframeEl.src = paymentUrl + sep + 'cross_domain_name=' + encodeURIComponent( domain );
        } else {
            iframeEl.src = paymentUrl;
        }

        overlayEl.classList.add( 'is-open' );
        document.body.classList.add( 'breeze-modal-active' );
        modalOpen = true;
        trapFocus( overlayEl );
        startReturnUrlPolling();
    }

    function closeModal( reason ) {
        if ( ! modalOpen ) return;
        log( 'closeModal', reason );
        stopPolling();
        overlayEl.classList.remove( 'is-open' );
        document.body.classList.remove( 'breeze-modal-active' );
        modalOpen = false;

        var userClose = ( reason === 'user-close' || reason === 'user-cancel' || reason === 'backdrop' || reason === 'escape' );
        if ( userClose ) {
            // Payment already succeeded server-side — follow the token-protected
            // success return URL so handle_return() empties the cart and lands
            // on the thank-you page. This is the same path the iframe redirect
            // would have taken; we just trigger it from the top window because
            // the iframe redirect was blocked (e.g. https → http downgrade on
            // local testing) or the user pressed Escape before it landed.
            if ( paymentConfirmed && currentSuccessUrl ) {
                if ( iframeEl ) iframeEl.src = 'about:blank';
                window.location.href = currentSuccessUrl;
                return;
            }
            cancelOrderAndReturnToCheckout();
            return;
        }
        setTimeout( function () {
            if ( iframeEl ) iframeEl.src = 'about:blank';
        }, 400 );
    }

    function cancelOrderAndReturnToCheckout() {
        var checkoutUrl = data.checkoutUrl || window.location.pathname;
        var done = function () {
            if ( iframeEl ) iframeEl.src = 'about:blank';
            window.location.href = checkoutUrl;
        };
        if ( ! currentOrderId || ! data.ajaxUrl || ! data.nonce ) {
            done();
            return;
        }
        $.ajax( {
            url:     data.ajaxUrl,
            type:    'POST',
            timeout: 8000,
            data: {
                action:   'breeze_cancel_modal_payment',
                nonce:    data.nonce,
                order_id: currentOrderId,
            },
            complete: done,
        } );
    }

    /* ── Iframe URL polling ──────────────────────────────── */
    function startReturnUrlPolling() {
        stopPolling();
        pollTimer = setInterval( function () {
            try {
                var loc = iframeEl.contentWindow.location.href;
                if ( ! loc || loc === 'about:blank' ) return;
                stopPolling();
                handleReturnUrl( loc );
            } catch ( e ) {
                /* still cross-origin — keep polling */
            }
        }, POLL_MS );
    }

    function stopPolling() {
        if ( pollTimer ) { clearInterval( pollTimer ); pollTimer = null; }
    }

    function handleReturnUrl( returnUrl ) {
        log( 'Iframe returned to same-origin URL', returnUrl );
        overlayEl.classList.remove( 'is-open' );
        document.body.classList.remove( 'breeze-modal-active' );
        modalOpen = false;
        window.location.href = returnUrl;
    }

    /* ── UI helpers ──────────────────────────────────────── */
    function setLoading( active ) {
        var btn = $( '#place_order' );
        if ( active ) {
            btn.prop( 'disabled', true ).addClass( 'breeze-btn-loading' );
            $( 'form.checkout' ).addClass( 'processing' );
        } else {
            btn.prop( 'disabled', false ).removeClass( 'breeze-btn-loading' );
            $( 'form.checkout' ).removeClass( 'processing' );
        }
    }

    function showError( message ) {
        if ( typeof window.wc_checkout_form !== 'undefined' && window.wc_checkout_form.submit_error ) {
            window.wc_checkout_form.submit_error(
                '<div class="woocommerce-error">' + escapeHtml( message ) + '</div>'
            );
            return;
        }
        $( '.woocommerce-NoticeGroup-checkout, .breeze-error-notice' ).remove();
        var notice = $(
            '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout breeze-error-notice">' +
                '<ul class="woocommerce-error"><li>' + escapeHtml( message ) + '</li></ul>' +
            '</div>'
        );
        $( 'form.checkout' ).before( notice );
        $( 'html, body' ).animate( { scrollTop: notice.offset().top - 100 }, 400 );
    }

    function trapFocus( container ) {
        var focusable = container.querySelectorAll(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        );
        if ( ! focusable.length ) return;
        var first = focusable[ 0 ];
        var last  = focusable[ focusable.length - 1 ];
        first.focus();
        container.addEventListener( 'keydown', function trap( e ) {
            if ( e.key !== 'Tab' ) return;
            if ( e.shiftKey ) {
                if ( document.activeElement === first ) { e.preventDefault(); last.focus(); }
            } else {
                if ( document.activeElement === last )  { e.preventDefault(); first.focus(); }
            }
            if ( ! modalOpen ) container.removeEventListener( 'keydown', trap );
        } );
    }

    function escapeHtml( str ) {
        var div = document.createElement( 'div' );
        div.appendChild( document.createTextNode( String( str ) ) );
        return div.innerHTML;
    }

    /* ── Boot ────────────────────────────────────────────── */
    $( init );

} )( window.jQuery );
