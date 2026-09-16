/**
 * DIPARMA — Square Web Payments SDK helper
 * Docs: https://developer.squareup.com/docs/web-payments/overview
 * Sandbox: https://sandbox.web.squarecdn.com/v1/square.js
 * Live:    https://web.squarecdn.com/v1/square.js
 */
(function (global) {
  'use strict';

  var state = {
    payments: null,
    card: null,
    ready: false,
    error: ''
  };

  async function init(appId, locationId, containerSelector) {
    state.ready = false;
    state.error = '';
    if (!appId || !locationId) {
      state.error = 'Missing Square application_id or location_id';
      return false;
    }
    if (!global.Square || typeof global.Square.payments !== 'function') {
      state.error = 'Square Web Payments SDK failed to load';
      return false;
    }
    try {
      state.payments = global.Square.payments(appId, locationId);
      state.card = await state.payments.card();
      await state.card.attach(containerSelector || '#square-card-container');
      state.ready = true;
      return true;
    } catch (e) {
      state.error = (e && e.message) ? e.message : String(e);
      state.ready = false;
      return false;
    }
  }

  async function tokenize() {
    if (!state.card) {
      return { success: false, message: state.error || 'Square card form not ready' };
    }
    try {
      var result = await state.card.tokenize();
      if (result.status === 'OK' && result.token) {
        return { success: true, token: result.token, details: result.details || {} };
      }
      var msg = (result.errors && result.errors[0] && result.errors[0].message)
        ? result.errors[0].message
        : (result.status || 'tokenize_failed');
      return { success: false, message: msg, raw: result };
    } catch (e) {
      return { success: false, message: (e && e.message) ? e.message : String(e) };
    }
  }

  function isReady() {
    return !!state.ready;
  }

  function lastError() {
    return state.error || '';
  }

  global.DiparmaSquareSdk = {
    init: init,
    tokenize: tokenize,
    isReady: isReady,
    lastError: lastError
  };
})(window);
