/**
 * Breeze Modal Checkout — WC Blocks integration.
 *
 * Intercepts the Store API checkout response client-side and opens the
 * Breeze-hosted payment URL inside a modal instead of letting Blocks
 * follow the redirect. The PHP side tags the response with
 * `breeze_modal: true` and `breeze_fail_url: <url>` (a token-protected
 * URL into `handle_return()`) so this script knows which redirects to
 * capture and how to surface a user-initiated cancellation.
 */

( function () {
    'use strict';

    var GATEWAY_ID = 'breeze_payment_gateway';
    var MODAL_ID   = 'breeze-modal-overlay';
    var POLL_MS    = 150;
    var SUCCESS_EVENTS = [
        'payin_action_card_payment_success',
        'payin_action_apple_pay_payment_success',
        'payin_action_google_pay_payment_success',
    ];

    var data = window.breezeModalData || {};
    var debug = !! data.debug;

    function log() {
        if ( ! debug ) return;
        try { console.log.apply( console, [ '[Breeze Modal]' ].concat( [].slice.call( arguments ) ) ); }
        catch ( e ) {}
    }

    /* ── State ───────────────────────────────────────────── */
    var modalOpen         = false;
    var pollTimer         = null;
    var iframeEl          = null;
    var overlayEl         = null;
    var paymentConfirmed  = false;
    var currentOrderId    = null;
    var currentSuccessUrl = '';
    var currentFailUrl    = '';

    /* ── Origin + URL helpers ────────────────────────────── */
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

    /* ── fetch() intercept ───────────────────────────────── */
    var CHECKOUT_PATH_RE = /\/wc\/store\/v[0-9]+\/checkout(?:\?|$|\/)/;

    function interceptStoreFetch() {
        var originalFetch = window.fetch;
        if ( typeof originalFetch !== 'function' ) return;

        window.fetch = function ( input, init ) {
            var url = typeof input === 'string' ? input : ( input && input.url ) || '';
            var method = ( init && init.method ) ? init.method.toUpperCase()
                : ( input && input.method ) ? input.method.toUpperCase()
                : 'GET';

            if ( method !== 'POST' || ! CHECKOUT_PATH_RE.test( url ) ) {
                return originalFetch.apply( this, arguments );
            }

            log( 'Intercepting Store API checkout POST', url );

            return originalFetch.apply( this, arguments ).then( function ( response ) {
                return response.clone().json().then( function ( data ) {
                    var pr = data && data.payment_result;
                    if ( ! pr ) return response;

                    var redirectUrl = extractRedirectUrl( pr );
                    var isBreeze    = !! pr.breeze_modal || isBreezePaymentUrl( redirectUrl );

                    if ( ! redirectUrl || ! isBreeze ) {
                        return response;
                    }

                    log( 'Breeze redirect captured', redirectUrl );

                    currentOrderId    = data.order_id || null;
                    currentSuccessUrl = pr.breeze_success_url || '';
                    currentFailUrl    = pr.breeze_fail_url || '';

                    openModal( redirectUrl );

                    // Hand Blocks a neutralised response so it stops processing
                    // without navigating. payment_status: 'pending' is the
                    // canonical "still waiting" signal.
                    var neutralised = JSON.parse( JSON.stringify( data ) );
                    neutralised.payment_result.payment_status = 'pending';
                    neutralised.payment_result.redirect_url   = '';
                    if ( Array.isArray( neutralised.payment_result.payment_details ) ) {
                        neutralised.payment_result.payment_details =
                            neutralised.payment_result.payment_details.filter( function ( d ) {
                                return d && d.key !== 'redirect' && d.key !== 'redirect_url';
                            } );
                    }

                    return new Response( JSON.stringify( neutralised ), {
                        status:     response.status,
                        statusText: response.statusText,
                        headers:    { 'Content-Type': 'application/json' },
                    } );
                } ).catch( function ( e ) {
                    log( 'Could not parse Store API response', e );
                    return response;
                } );
            } );
        };
    }

    function extractRedirectUrl( pr ) {
        if ( ! pr ) return null;
        if ( pr.redirect_url ) return pr.redirect_url;
        if ( Array.isArray( pr.payment_details ) ) {
            for ( var i = 0; i < pr.payment_details.length; i++ ) {
                var d = pr.payment_details[ i ];
                if ( d && ( d.key === 'redirect' || d.key === 'redirect_url' ) ) {
                    return d.value || null;
                }
            }
        }
        return null;
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

        window.addEventListener( 'message', onPostMessage );
    }

    /* ── postMessage handlers (origin-validated) ─────────── */
    function onPostMessage( event ) {
        if ( ! isBreezeOrigin( event.origin ) ) return;
        if ( ! event.data || typeof event.data !== 'object' ) return;

        // Apple Pay cross-domain handshake.
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
            log( 'Responded to request-global-config, applePayEnabled:', !! domain );
            return;
        }

        if ( ! modalOpen ) return;
        if ( event.data.type !== 'on-payment-event' ) return;

        var eventName = event.data.data && event.data.data.eventName;
        if ( eventName ) handleBreezeEvent( eventName );
    }

    function handleBreezeEvent( eventName ) {
        if ( SUCCESS_EVENTS.indexOf( eventName ) !== -1 && ! paymentConfirmed ) {
            paymentConfirmed = true;
            if ( iframeEl ) {
                iframeEl.style.opacity      = '0';
                iframeEl.style.pointerEvents = 'none';
            }
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

        var prev = overlayEl.querySelector( '.breeze-payment-confirmed' );
        if ( prev ) prev.parentNode.removeChild( prev );

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
        setTimeout( function () { confirm.classList.add( 'is-visible' ); }, 16 );

        var closeBtn  = overlayEl.querySelector( '.breeze-modal-close' );
        var cancelBtn = overlayEl.querySelector( '.breeze-modal-cancel' );
        if ( closeBtn )  closeBtn.disabled  = true;
        if ( cancelBtn ) cancelBtn.disabled = true;
    }

    function expandModalFor3DS( expand ) {
        var container = overlayEl && overlayEl.querySelector( '.breeze-modal-container' );
        if ( ! container ) return;
        container.classList.toggle( 'is-3ds', expand );
    }

    function shakeModal() {
        var container = overlayEl && overlayEl.querySelector( '.breeze-modal-container' );
        if ( ! container ) return;
        container.classList.remove( 'is-shaking' );
        void container.offsetWidth;
        container.classList.add( 'is-shaking' );
        container.addEventListener( 'animationend', function onEnd() {
            container.classList.remove( 'is-shaking' );
            container.removeEventListener( 'animationend', onEnd );
        } );
    }

    /* ── Modal open/close ────────────────────────────────── */
    function openModal( paymentUrl ) {
        buildModal();
        paymentConfirmed = false;

        var loading = overlayEl.querySelector( '#breeze-iframe-loading' );
        if ( loading ) loading.style.display = 'flex';
        iframeEl.style.opacity = '0';
        iframeEl.style.pointerEvents = '';

        // Re-enable close buttons in case a previous attempt locked them.
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

    /**
     * When the customer closes the modal before completion, cancel the order
     * via an authenticated AJAX call and reload the checkout page. The cancel
     * endpoint is nonce-bound and ownership-checked server-side.
     */
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

        var body = new URLSearchParams();
        body.append( 'action',   'breeze_cancel_modal_payment' );
        body.append( 'nonce',    data.nonce );
        body.append( 'order_id', String( currentOrderId ) );

        fetch( data.ajaxUrl, {
            method:      'POST',
            credentials: 'same-origin',
            body:        body,
        } ).then( done ).catch( done );
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

    /**
     * The iframe has navigated to a same-origin URL. If it's the token-protected
     * breeze_return endpoint we follow it on the top window — handle_return()
     * does the rest (status update, cart clear, redirect to thank-you / checkout).
     * For any other same-origin URL, we still navigate; that's typically the
     * order-received page or the checkout page after Breeze's own redirect.
     */
    function handleReturnUrl( returnUrl ) {
        log( 'Iframe returned to same-origin URL', returnUrl );
        // Close modal first (without firing the user-cancel cleanup).
        overlayEl.classList.remove( 'is-open' );
        document.body.classList.remove( 'breeze-modal-active' );
        modalOpen = false;
        window.location.href = returnUrl;
    }

    /* ── Utilities ───────────────────────────────────────── */
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
        div.appendChild( document.createTextNode( str ) );
        return div.innerHTML;
    }

    /* ── Boot ────────────────────────────────────────────── */
    document.addEventListener( 'DOMContentLoaded', function () {
        log( 'Blocks intercept initialising' );
        interceptStoreFetch();
    } );

    // Silence the lint warning for the unused id constant.
    void GATEWAY_ID;
} )();
