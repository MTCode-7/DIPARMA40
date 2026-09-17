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
    inflight: null,
    appId: '',
    locationId: ''
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
      return 'Square LIVE card form failed to hydrate. Use the same Production Application ID (sq0idp-) and Location ID that work on localhost in the production .env / gateway credentials. Domain allowlisting is not required for the card form.';
    }
    return msg;
  }

  function waitForSquare(timeoutMs) {
    return new Promise(function (resolve) {
      if (global.Square && typeof global.Square.payments === 'function') {
        resolve(true);
        return;
      }
      var start = Date.now();
      var t = setInterval(function () {
        if (global.Square && typeof global.Square.payments === 'function') {
          clearInterval(t);
          resolve(true);
        } else if (Date.now() - start > timeoutMs) {
          clearInterval(t);
          resolve(false);
        }
      }, 40);
    });
  }

  function waitVisible(el) {
    return new Promise(function (resolve) {
      var tries = 0;
      function tick() {
        var wrap = null;
        try {
          wrap = el.closest ? el.closest('#squarePosWrap, #squareWrap') : null;
        } catch (e) {}
        var hidden = !!(wrap && wrap.style && wrap.style.display === 'none');
        var w = 0;
        try { w = el.offsetWidth || (el.getBoundingClientRect && el.getBoundingClientRect().width) || 0; } catch (e2) {}
        if (!hidden && w > 8) {
          resolve(true);
          return;
        }
        tries += 1;
        if (tries > 30) {
          resolve(!hidden);
          return;
        }
        requestAnimationFrame(tick);
      }
      tick();
    });
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

  async function getPayments(appId, locationId) {
    if (state.payments && state.appId === appId && state.locationId === locationId) {
      return state.payments;
    }
    var payments = global.Square.payments(appId, locationId);
    if (payments && typeof payments.then === 'function') {
      payments = await payments;
    }
    if (payments && typeof payments.setLocale === 'function') {
      try {
        await payments.setLocale('en-US');
      } catch (e) {}
    }
    state.payments = payments;
    state.appId = appId;
    state.locationId = locationId;
    return payments;
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
    if (!(await waitForSquare(8000))) {
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
    el.setAttribute('dir', 'ltr');
    if (el.closest) {
      var wrap = el.closest('#squarePosWrap, #squareWrap');
      if (wrap) wrap.setAttribute('dir', 'ltr');
    }
    await waitVisible(el);

    await destroyCard();
    el.innerHTML = '';

    try {
      var payments = await getPayments(appId, locationId);
      state.card = await payments.card();
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
      state.inflight = null;
      return ok;
    }, function () {
      state.inflight = null;
      return false;
    });
    return state.inflight;
  }

  async function verifyBuyer(sourceId, amount, currency, intent, billing) {
    if (!state.payments || typeof state.payments.verifyBuyer !== 'function') {
      return { success: true, token: '' };
    }
    var amt = Number(amount);
    if (!isFinite(amt) || amt <= 0) {
      return { success: true, token: '' };
    }
    var name = String((billing && billing.name) || 'Card Holder').replace(/\s+/g, ' ').trim() || 'Card Holder';
    var parts = name.split(' ');
    var given = parts[0] || 'Card';
    var family = parts.length > 1 ? parts.slice(1).join(' ') : 'Holder';
    var details = {
      amount: amt.toFixed(2),
      currencyCode: String(currency || 'USD').toUpperCase(),
      intent: intent === 'STORE' ? 'STORE' : 'CHARGE',
      billingContact: {
        givenName: given,
        familyName: family
      }
    };
    if (billing && billing.email && /@/.test(String(billing.email))) {
      details.billingContact.email = String(billing.email).trim();
    }
    try {
      var result = await state.payments.verifyBuyer(sourceId, details);
      return { success: true, token: (result && result.token) ? String(result.token) : '' };
    } catch (e) {
      var msg = (e && e.message) ? e.message : String(e);
      if (/missing or invalid|not enabled|not required|unsupported|unable to verify/i.test(msg)) {
        return { success: true, token: '' };
      }
      return { success: false, token: '', message: msg };
    }
  }

  async function tokenize() {
    if (!state.card) {
      return { success: false, message: state.error || 'Square card form not ready' };
    }
    try {
      var result = await state.card.tokenize();
      if (result.status === 'OK' && result.token) {
        if (String(result.token).trim().toLowerCase() === 'cnon:card-nonce-ok') {
          return { success: false, message: 'Square simulation nonce is rejected. Use a real Web Payments SDK token.' };
        }
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
    lastError: lastError,
    verifyBuyer: verifyBuyer
  };
})(window);
