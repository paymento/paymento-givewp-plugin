(function () {
    'use strict';
    let notice = '';
    window.givewp.gateways.register({
        id: 'paymento',
        initialize: function () { notice = this.settings.notice; },
        Fields: function () { return window.wp.element.createElement('p', {className: 'paymento-gateway-notice'}, notice); }
    });
}());
