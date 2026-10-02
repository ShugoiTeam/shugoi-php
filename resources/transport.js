/* Shugoi browser transport: signed WLC decisions over WebSocket,
 * verified page rendering through the hosting application's HTTP endpoint.
 * Protocol aligned with shugoi-npm 15c9cd63. No secret is embedded here. */
(function () {
  "use strict";
  if (window.__sg_phpTransport) return;
  window.__sg_phpTransport = true;

  var Socket = window.WebSocket;
  var nativeFetch = window.fetch.bind(window);
  var api = new URL(window.__sg_baseUrl, location.href);
  if (!/^https?:$/.test(api.protocol) || api.username || api.password) throw new Error("invalid_shugoi_api");
  var apiOrigin = api.origin;
  var apiPath = api.pathname.replace(/\/$/, "");
  var endpoint = (api.protocol === "https:" ? "wss://" : "ws://") + api.host + apiPath + "/ws-wlc";
  var timeoutMs = 8000;
  var activeSockets = new Set();

  function socket() {
    var ws = new Socket(endpoint);
    activeSockets.add(ws);
    ws.addEventListener("close", function () { activeSockets.delete(ws); });
    return ws;
  }

  function close(ws) {
    if (!ws) return;
    try { ws.close(); } catch (_) { /* Already closed. */ }
    activeSockets.delete(ws);
  }

  function query(input, path) {
    var url = new URL(input, location.href);
    if (url.origin !== apiOrigin || url.pathname !== apiPath + "/" + path
      || !url.search || url.search.length > 24576 || url.username || url.password
      || url.searchParams.get("key") !== window.__sg_siteKey) {
      throw new Error("wlc_transport_invalid");
    }
    return url.search;
  }

  window.__sg_wlcRequest = function (input, callback) {
    var ws, timer, clockSentAt, done = false;
    function finish(error, text) {
      if (done) return;
      done = true;
      clearTimeout(timer);
      close(ws);
      callback(error, text);
    }
    try {
      // The official guard also uses this transport for its main-thread clock
      // sample. Rejecting that request leaves a zero drift fallback which is
      // later mistaken for tampering when the worker measures a normal OS
      // clock offset. Clock samples use their own WebSocket message type and
      // cannot authorize a document or replace a signed WLC decision.
      var clockUrl = new URL(input, location.href);
      var isClock = clockUrl.origin === apiOrigin && clockUrl.pathname === apiPath + "/clock-drift"
        && !clockUrl.search && !clockUrl.hash && !clockUrl.username && !clockUrl.password;
      var q = isClock ? null : query(input, "wlc");
      ws = socket();
      timer = setTimeout(function () { finish("wlc_transport_timeout"); }, timeoutMs);
      ws.onopen = function () {
        try {
          clockSentAt = Date.now();
          ws.send(JSON.stringify(isClock ? { cmd: "clock-ping", tSend: clockSentAt } : { q: q }));
        }
        catch (_) { finish("wlc_transport_send"); }
      };
      ws.onmessage = function (event) {
        if (typeof event.data !== "string" || event.data.length > 16384) {
          finish("wlc_transport_invalid_response");
          return;
        }
        if (isClock) {
          try {
            var pong = JSON.parse(event.data);
            if (!pong || pong.cmd !== "clock-pong" || pong.tSend !== clockSentAt
              || !Number.isFinite(pong.tRecv) || !Number.isFinite(pong.tOut)
              || pong.tRecv <= 0 || pong.tOut < pong.tRecv) throw new Error("invalid_clock_sample");
            finish(null, JSON.stringify({ t: pong.tRecv / 2 + pong.tOut / 2 }));
          } catch (_) { finish("wlc_transport_invalid_response"); }
          return;
        }
        // Pass the untouched signed decision to the official guard.
        finish(null, event.data);
      };
      ws.onerror = function () { finish("wlc_transport_error"); };
      ws.onclose = function () { finish("wlc_transport_closed"); };
    } catch (_) { finish("wlc_transport_invalid"); }
  };

  window.__sg_wlcStream = function (input, onData, onError) {
    var ws, stopped = false;
    function stop() { stopped = true; close(ws); }
    function fail() {
      if (stopped) return;
      stop();
      onError();
    }
    try {
      var s = query(input, "whitelist-stream");
      ws = socket();
      ws.onopen = function () {
        if (stopped) return;
        try { ws.send(JSON.stringify({ s: s })); } catch (_) { fail(); }
      };
      ws.onmessage = function (event) {
        if (stopped) return;
        try {
          if (typeof event.data !== "string" || event.data.length > 16384) throw new Error("invalid");
          onData(JSON.parse(event.data));
        } catch (_) { fail(); }
      };
      ws.onerror = fail;
      ws.onclose = fail;
    } catch (_) { fail(); }
    return stop;
  };

  window.__sg_wlcEvent = function (reason) {
    var ws, timer;
    try {
      ws = socket();
      timer = setTimeout(function () { close(ws); }, timeoutMs);
      ws.onopen = function () {
        try {
          ws.send(JSON.stringify({
            cmd: "event", reason: String(reason || "").slice(0, 128),
            machineId: String(window.__sg_detectMid || window.__sg_mid || "").slice(0, 256),
            siteKey: String(window.__sg_siteKey || "").slice(0, 128)
          }));
        } catch (_) { close(ws); }
      };
      ws.onmessage = function () { close(ws); };
      ws.onerror = function () { close(ws); };
      ws.addEventListener("close", function () { clearTimeout(timer); });
    } catch (_) { clearTimeout(timer); close(ws); }
  };

  var renderInFlight = false;
  function renderFailed(callback) {
    callback();
    if (!window.__sg_blocked && typeof window.__sg_showBlock === "function") {
      window.__sg_showBlock(
        window.__sg_transportMessages.body,
        window.__sg_transportMessages.title
      );
    }
  }
  window.__sg_wsMux = {
    send: function (message, onSuccess, onError) {
      if (renderInFlight) { onError(); return; }
      var controller = new AbortController();
      var timer;
      try {
        var data = JSON.parse(message);
        if (!data || Object.keys(data).sort().join(",") !== "cmd,grant,mid,token"
          || data.cmd !== "render" || typeof data.token !== "string"
          || data.token !== window.__sg_token || !/^[a-f0-9]{64}$/.test(data.mid)
          || typeof data.grant !== "string" || data.grant.length > 256
          || window.__sg_blocked) throw new Error("invalid_render_request");
        var url = new URL(window.__sg_renderUrl, location.href);
        if (url.origin !== location.origin || url.username || url.password) throw new Error("foreign_render_origin");
        url.searchParams.set("token", data.token);
        url.searchParams.set("mid", data.mid);
        url.searchParams.set("grant", data.grant);
        renderInFlight = true;
        timer = setTimeout(function () { controller.abort(); }, timeoutMs);
        nativeFetch(url.href, {
          credentials: "same-origin", cache: "no-store", redirect: "error",
          headers: { accept: "application/json" }, signal: controller.signal
        }).then(function (response) {
          if (!response.ok || !String(response.headers.get("content-type")).includes("application/json")) {
            throw new Error("render_unavailable");
          }
          return response.json();
        }).then(function (result) {
          if (window.__sg_blocked || !result || result.blocked || result.error
            || typeof result.html !== "string" || !result.html) throw new Error("render_refused");
          clearTimeout(timer);
          renderInFlight = false;
          onSuccess(result.html);
        }).catch(function () {
          clearTimeout(timer);
          renderInFlight = false;
          renderFailed(onError);
        });
      } catch (_) {
        clearTimeout(timer);
        renderInFlight = false;
        onError();
      }
    }
  };

  window.addEventListener("pagehide", function () {
    activeSockets.forEach(close);
  });
})();
