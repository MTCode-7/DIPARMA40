/**
 * DI PARMA | Ledger WebHID
 * First await after click = requestDevice. Empty filters so the device appears.
 */
(function (global) {
  const LEDGER_VENDORS = [0x2c97, 0x2581];
  const LEDGER_FILTERS = [
    { vendorId: 0x2c97 },
    { vendorId: 0x2581 },
    { vendorId: 0x2c97, usagePage: 0xffa0 },
  ];
  const CDN = {
    transport: 'https://esm.sh/@ledgerhq/hw-transport-webhid@6.30.0',
    trx: 'https://esm.sh/@ledgerhq/hw-app-trx@6.36.6',
  };

  let modulesPromise = null;

  function hidDenied(err) {
    const msg = String(err && err.message ? err.message : err || '');
    return /Access denied|Failed to open|Failed to execute 'open'|already open|exclusive|reading 'open'|Cannot read properties of undefined/i.test(msg);
  }

  function unwrapExport(modOrClass) {
    let cur = modOrClass;
    for (let i = 0; i < 5 && cur; i++) {
      if (typeof cur === 'function') return cur;
      cur = cur.default || cur.TransportWebHID || cur.Trx || null;
    }
    return null;
  }

  function isLedgerDevice(d) {
    return !!(d && LEDGER_VENDORS.indexOf(d.vendorId) !== -1);
  }

  function diagnose() {
    return {
      hid: !!(navigator.hid && navigator.hid.requestDevice),
      secure: !!window.isSecureContext,
      host: location.hostname || '',
      protocol: location.protocol || '',
    };
  }

  function preloadModules() {
    if (!modulesPromise) {
      modulesPromise = Promise.all([
        import(CDN.transport),
        import(CDN.trx),
      ]).then(function (pair) {
        const Transport = unwrapExport(pair[0]);
        const Trx = unwrapExport(pair[1]);
        if (!Transport || !Trx) throw new Error('Ledger modules incomplete');
        return { Transport: Transport, Trx: Trx };
      });
    }
    return modulesPromise;
  }

  async function requestDeviceNow() {
    if (!navigator.hid) {
      throw new Error('WebHID unavailable');
    }
    if (!window.isSecureContext) {
      throw new Error('INSECURE_CONTEXT');
    }

    let picked = [];
    try {
      picked = await navigator.hid.requestDevice({ filters: [] });
    } catch (err) {
      const msg = String(err && err.message ? err.message : err);
      if (/user gesture|SecurityError/i.test(msg)) {
        throw new Error('NO_USER_GESTURE');
      }
      try {
        picked = await navigator.hid.requestDevice({ filters: LEDGER_FILTERS });
      } catch (err2) {
        throw err2;
      }
    }

    let device = Array.isArray(picked) ? picked[0] : picked;
    if (!device && navigator.hid.getDevices) {
      const granted = await navigator.hid.getDevices();
      device = granted.filter(isLedgerDevice)[0] || granted[0] || null;
    }
    if (!device) {
      throw new Error('EMPTY_PICKER');
    }
    return device;
  }

  async function connectFromClick() {
    const device = await requestDeviceNow();
    const mods = await preloadModules();
    if (device.opened) {
      try { await device.close(); } catch (_) {}
    }
    try {
      await device.open();
    } catch (err) {
      if (!hidDenied(err)) throw err;
      throw new Error('Access denied to use Ledger device');
    }
    const transport = new mods.Transport(device);
    const app = new mods.Trx(transport);
    const result = await app.getAddress("44'/195'/0'/0/0", false);
    const address = result && result.address ? result.address : result;
    if (!address || typeof address !== 'string' || address.charAt(0) !== 'T') {
      try { await transport.close(); } catch (_) {}
      throw new Error('Open the Tron app on the device and try again');
    }
    return { transport: transport, address: address, device: device };
  }

  function explainError(err, ar) {
    const msg = String(err && err.message ? err.message : err || '');
    const info = diagnose();
    if (/INSECURE_CONTEXT/i.test(msg) || !info.secure) {
      return ar
        ? 'Chrome يمنع USB إلا على localhost أو https. افتح http://localhost:8080/pos/ أو https://diparmas.com/pos/'
        : 'Chrome blocks USB except on localhost or https. Open http://localhost:8080/pos/ or https://diparmas.com/pos/';
    }
    if (/WebHID unavailable|hid is not/i.test(msg)) {
      return ar
        ? 'استخدم Chrome أو Edge على الكمبيوتر (وليس الجوال).'
        : 'Use Chrome or Edge on desktop (not mobile).';
    }
    if (hidDenied(err) || /Access denied|reading 'open'/i.test(msg)) {
      return ar
        ? 'الجهاز محجوز. أغلق Ledger Live من شريط المهام (Quit)، افتح تطبيق Tron، ثم اتصال.'
        : 'Device is locked. Quit Ledger Live from the tray, open the Tron app, then Connect.';
    }
    if (/EMPTY_PICKER|No Ledger selected/i.test(msg)) {
      return ar
        ? 'القائمة فارغة أو أُلغيت. أغلق Ledger Live، وصّل USB، افتح Tron، اضغط اتصال، واختر الجهاز ثم Allow.'
        : 'List empty or cancelled. Quit Ledger Live, plug USB, open Tron, click Connect, pick the device, Allow.';
    }
    if (/NO_USER_GESTURE|user gesture|SecurityError/i.test(msg)) {
      return ar
        ? 'اضغط زر اتصال مباشرة. لا تستخدم اختصار لوحة المفاتيح.'
        : 'Click the Connect button directly. Do not use a keyboard shortcut.';
    }
    if (/Tron app/i.test(msg)) {
      return ar
        ? 'افتح تطبيق Tron على الجهاز واتركه على الشاشة ثم اضغط اتصال.'
        : 'Open the Tron app on the device and leave it on screen, then click Connect.';
    }
    return msg;
  }

  function bindConnectButton(button, handler) {
    if (!button || button.getAttribute('data-ledger-bound') === '1') return;
    button.setAttribute('data-ledger-bound', '1');
    button.addEventListener('click', function (ev) {
      ev.preventDefault();
      handler(ev);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { preloadModules().catch(function () {}); });
  } else {
    preloadModules().catch(function () {});
  }

  global.DiparmaLedgerHid = {
    hidDenied,
    diagnose,
    preloadModules,
    requestDeviceNow,
    connectFromClick,
    bindConnectButton,
    unwrapExport,
    resolveTransportClass: unwrapExport,
    explainError,
  };
})(window);
