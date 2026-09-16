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
    error: '',
    inflight: null
  };

  function isSandboxAppId(appId) {
    return /^sandbox-/i.test(String(appId || ''));
  }

  function scriptLooksSandbox() {
    try {
      var nodes = document.getElementsByTagName('script');
      for (var i = 0; i < nodes.length; i++) {
        var src = String(nodes[i].src || '');
        if (src.indexOf('sandbox.web.squarecdn.com') !== -1) return true;
        if (src.indexOf('web.squarecdn.com') !== -1) return false;
      }
    } catch (e) {}
    return null;
  }

  function mapInitError(raw, appId) {
    var msg = String(raw || 'Square card init failed');
    var sandboxApp = isSandboxAppId(appId);
    var scriptSandbox = scriptLooksSandbox();
    if (scriptSandbox === true && !sandboxApp) {
      return 'Square LIVE application ID was loaded with the sandbox SDK. Use production square.js (web.squarecdn.com).';
    }
    if (scriptSandbox === false && sandboxApp) {
      return 'Square sandbox application ID was loaded with the LIVE SDK. Use sandbox.web.squarecdn.com.';
    }
    if (/unexpected error occurred while initializing/i.test(msg)) {
      return msg + ' — Application ID, Location ID, and SDK environment must match; add this domain under Square Dashboard → Web Payments SDK.';
    }
    return msg;
  }

  async function destroyCard() {
    if (state.card && typeof state.card.destroy === 'function') {
      try {
        await state.card.destroy();
      } catch (e) {}
    }
    state.card = null;
    state.ready = false;
  }

  async function initInternal(appId, locationId, containerSelector) {
    state.ready = false;
    state.error = '';
    if (!appId || !locationId) {
      state.error = 'Missing Square application_id or location_id';
      return false;
    }
    if (!/^(sandbox-)?sq0id[bp]-/i.test(appId)) {
      state.error = 'SQUARE_APPLICATION_ID is not a Web Payments application ID (expected sq0idp- / sq0idb- / sandbox-sq0idb-)';
      return false;
    }
    if (!global.Square || typeof global.Square.payments !== 'function') {
      state.error = 'Square Web Payments SDK failed to load';
      return false;
    }

    var el = null;
    try {
      el = document.querySelector(containerSelector || '#square-card-container');
    } catch (e) {}
    if (!el) {
      state.error = 'Square card container is missing';
      return false;
    }
    var hidden = false;
    try {
      var wrap = el.closest ? el.closest('#squarePosWrap, #squareWrap') : null;
      hidden = !!(wrap && wrap.style && wrap.style.display === 'none');
    } catch (e) {}
    if (hidden) {
      state.error = 'Square card form is hidden';
      return false;
    }

    await destroyCard();
    el.innerHTML = '';

    try {
      var payments = global.Square.payments(appId, locationId);
      if (payments && typeof payments.then === 'function') {
        payments = await payments;
      }
      state.payments = payments;
      state.card = await state.payments.card();
      await state.card.attach(containerSelector || '#square-card-container');
      state.ready = true;
      state.error = '';
      return true;
    } catch (e) {
      await destroyCard();
      state.error = mapInitError((e && e.message) ? e.message : String(e), appId);
      state.ready = false;
      return false;
    }
  }

  function init(appId, locationId, containerSelector) {
    if (state.inflight) {
      return state.inflight;
    }
    state.inflight = initInternal(appId, locationId, containerSelector).then(function (ok) {
      if (!ok) {
        state.inflight = null;
      }
      return ok;
    }, function () {
      state.inflight = null;
      return false;
    });
    return state.inflight;
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
