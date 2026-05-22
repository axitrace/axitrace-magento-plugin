/**
 * RequireJS-friendly SDK loader for Luma.
 *
 * The pixel.phtml template loads the SDK via a plain <script defer> tag so it
 * benefits from native loading semantics. This module exposes a tiny helper
 * for any Magento Luma component that wants to wait for the SDK to be ready
 * before pushing custom events.
 *
 * Usage:
 *   require(['AxiTrace_Tracking/js/axitrace-sdk-loader'], function (axitrace) {
 *       axitrace.whenReady(function (sdk) { sdk.track('custom', { ... }); });
 *   });
 */
define([], function () {
    'use strict';

    return {
        whenReady: function (callback) {
            if (typeof callback !== 'function') {
                return;
            }
            if (window.axitrace) {
                callback(window.axitrace);
                return;
            }
            window.addEventListener('axitrace:ready', function () {
                callback(window.axitrace);
            }, { once: true });
        }
    };
});
