const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;
const { createElement } = window.wp.element;
const { decodeEntities } = window.wp.htmlEntities;
const { RawHTML } = window.wp.element;

/**
 * Get settings from the payment method
 */
const settings = getSetting( 'breeze_payment_gateway_data', {} );

/**
 * Label element — icon + title rendered as a pre-created React element.
 */
const Label = createElement(
    'span',
    { style: { display: 'flex', alignItems: 'center' } },
    settings.icon && createElement( 'img', {
        src: settings.icon,
        alt: '',
        style: { height: '24px', width: 'auto', marginRight: '8px' },
    } ),
    decodeEntities( settings.title || 'Breeze Payment' )
);

/**
 * Content component - renders the payment method description
 */
const Content = () => {
    return createElement(
        'div',
        { className: 'wc-block-components-payment-method-content' },
        settings.description && createElement(
            RawHTML,
            null,
            decodeEntities( settings.description )
        ),
        settings.testMode && createElement(
            'p',
            { 
                className: 'breeze-test-mode-notice', 
                style: { 
                    marginTop: '10px',
                    padding: '10px',
                    backgroundColor: '#fff3cd',
                    border: '1px solid #ffc107',
                    borderRadius: '4px',
                    color: '#856404',
                    fontSize: '14px'
                } 
            },
            'TEST MODE ENABLED. No real payments will be processed.'
        )
    );
};

/**
 * Breeze payment method config object.
 */
const BreezePaymentMethod = {
    name: 'breeze_payment_gateway',
    label: Label,
    content: createElement( Content ),
    edit: createElement( Content ),
    canMakePayment: () => true,
    ariaLabel: decodeEntities( settings.title || 'Breeze Payment Gateway' ),
    supports: {
        features: settings.supports || [],
    },
};

/**
 * Register the payment method
 */
registerPaymentMethod( BreezePaymentMethod );
