import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { createHmac } from "node:crypto";
import test from "node:test";
import vm from "node:vm";

// All credentials, sockets, timers and HTTP responses are synthetic. This file
// never opens a network connection or invokes the production guard service.
const source = readFileSync(new URL("../resources/transport.js", import.meta.url), "utf8");
const ORIGIN = "https://php-site.test";
const SITE = "sg_sk_live_test_transport";
const MID = "a".repeat(64);
const SECRET = "offline-transport-fixture-only";
const NOW = 1_790_800_000_000;
const tokenBase = `${SITE}:${NOW}:0123456789abcdef`;
const TOKEN = `${tokenBase}:${createHmac("sha256", SECRET).update(tokenBase).digest("hex")}`;
const grantTime = Math.floor(NOW / 1000).toString(36);
const GRANT = `${grantTime}:${createHmac("sha256", SECRET).update(`render-grant:${SITE}:${MID}:${TOKEN}:${grantTime}`).digest("hex")}`;
const requestUrl = (path = "wlc") => `https://shugoi.com/api/v1/${path}?key=${SITE}&mid=${MID}&raw=offline%2Ffixture&token=${encodeURIComponent(TOKEN)}&drift=0`;
const renderMessage = (overrides = {}) => JSON.stringify({ cmd: "render", token: TOKEN, mid: MID, grant: GRANT, ...overrides });

function environment() {
  let clock = 0;
  let nextTimer = 0;
  const timers = new Map();
  const sockets = [];
  const fetches = [];
  const listeners = new Map();

  function setTimer(callback, delay = 0) {
    const id = ++nextTimer;
    timers.set(id, { at: clock + delay, callback });
    return id;
  }

  function tick(milliseconds) {
    const target = clock + milliseconds;
    for (;;) {
      const next = [...timers].filter(([, timer]) => timer.at <= target)
        .sort((a, b) => a[1].at - b[1].at || a[0] - b[0])[0];
      if (!next) break;
      clock = next[1].at;
      timers.delete(next[0]);
      next[1].callback();
    }
    clock = target;
  }

  class FakeSocket {
    constructor(url) {
      assert.equal(url, "wss://shugoi.com/api/v1/ws-wlc");
      this.url = url;
      this.sent = [];
      this.listeners = new Map();
      this.closed = false;
      this.throwOnSend = false;
      sockets.push(this);
    }

    addEventListener(name, handler) {
      const handlers = this.listeners.get(name) ?? [];
      handlers.push(handler);
      this.listeners.set(name, handlers);
    }

    emit(name, event = {}) {
      for (const handler of this.listeners.get(name) ?? []) handler(event);
      this[`on${name}`]?.(event);
    }

    send(value) {
      if (this.throwOnSend || this.closed) throw new Error("Synthetic socket failure");
      this.sent.push(value);
    }

    close() {
      if (this.closed) return;
      this.closed = true;
      this.emit("close");
    }
  }

  const window = {
    WebSocket: FakeSocket,
    __sg_siteKey: SITE,
    __sg_baseUrl: "https://shugoi.com/api/v1",
    __sg_renderUrl: "/__shugoi/render",
    __sg_transportMessages: {body: "Retry verification.", title: "Unavailable"},
    __sg_token: TOKEN,
    __sg_detectMid: MID,
    fetch(url, options) {
      assert.equal(new URL(url).origin, ORIGIN, "Transport must never fetch a third-party render URL");
      return new Promise((resolve, reject) => {
        const call = { url, options, resolve, reject };
        fetches.push(call);
        options.signal.addEventListener("abort", () => reject(new DOMException("Synthetic abort", "AbortError")), { once: true });
      });
    },
    addEventListener(name, handler) {
      listeners.set(name, handler);
    },
  };
  const location = { href: `${ORIGIN}/cloud/dashboard?view=plugins`, origin: ORIGIN };
  window.location = location;
  const context = vm.createContext({ window, location, URL, URLSearchParams, AbortController, setTimeout: setTimer, clearTimeout: id => timers.delete(id) });
  vm.runInContext(source, context, { filename: "shugoi-transport.v1.js" });

  return { window, sockets, fetches, timers, tick, pagehide: () => listeners.get("pagehide")?.(), reinstall: () => vm.runInContext(source, context) };
}

const flush = async () => {
  // Drain all promise stages without advancing the synthetic timer clock.
  for (let i = 0; i < 8; i++) await Promise.resolve();
};

function response(body, { status = 200, type = "application/json; charset=utf-8", invalidJson = false } = {}) {
  return {
    ok: status >= 200 && status < 300,
    status,
    headers: new Headers({ "content-type": type }),
    json: async () => {
      if (invalidJson) throw new SyntaxError("Synthetic invalid JSON");
      return body;
    },
  };
}

function render(env, message = renderMessage()) {
  const success = [];
  const errors = [];
  env.window.__sg_wsMux.send(message, value => success.push(value), (...args) => errors.push(args));
  return { success, errors };
}

test("WLC sends the official {q} envelope and passes signed decision text unchanged", () => {
  for (const decision of [
    '{"allowed":true,"sig":"opaque-signed-value"}',
    '  {"allowed":false,"blocked":true,"reason":"denied","sig":"opaque-refusal"}\n',
  ]) {
    const env = environment();
    const calls = [];
    const url = requestUrl();
    env.window.__sg_wlcRequest(url, (...args) => calls.push(args));
    const ws = env.sockets[0];
    ws.emit("open");
    assert.deepEqual(JSON.parse(ws.sent[0]), { q: new URL(url).search });
    ws.emit("message", { data: decision });
    assert.deepEqual(calls, [[null, decision]]);
    assert.equal(ws.closed, true);
    assert.equal(env.timers.size, 0);
    ws.emit("close");
    ws.emit("error");
    ws.emit("message", { data: "late" });
    env.tick(16000);
    assert.equal(calls.length, 1);
  }
});

test("WLC transport does not abort a response slower than 1500ms (independent of guard timeout)", () => {
  const env = environment();
  const calls = [];
  env.window.__sg_wlcRequest(requestUrl(), (...args) => calls.push(args));
  const ws = env.sockets[0];
  ws.emit("open");
  env.tick(6500);
  assert.equal(calls.length, 0);
  assert.equal(ws.closed, false);
  ws.emit("message", { data: '{"allowed":true}' });
  assert.deepEqual(calls, [[null, '{"allowed":true}']]);
});

test("WLC timeout, socket close, error, send failure and malformed response settle once", () => {
  for (const kind of ["timeout", "close", "error", "send", "binary", "oversize"]) {
    const env = environment();
    const calls = [];
    env.window.__sg_wlcRequest(requestUrl(), (...args) => calls.push(args));
    const ws = env.sockets[0];
    if (kind === "send") ws.throwOnSend = true;
    ws.emit("open");
    if (kind === "timeout") env.tick(8000);
    else if (kind === "close") ws.emit("close");
    else if (kind === "error") ws.emit("error");
    else if (kind === "binary") ws.emit("message", { data: new Uint8Array([1]) });
    else if (kind === "oversize") ws.emit("message", { data: "a".repeat(16385) });
    assert.equal(calls.length, 1, kind);
    assert.match(calls[0][0], /^wlc_transport_/);
    assert.equal(calls[0][1], undefined);
    ws.emit("close");
    ws.emit("error");
    env.tick(20000);
    assert.equal(calls.length, 1, kind);
    assert.equal(ws.closed, true, kind);
  }
});

test("WLC and stream reject foreign origins, credentials, wrong endpoint or site key", () => {
  for (const stream of [false, true]) {
    const path = stream ? "whitelist-stream" : "wlc";
    for (const url of [
      `https://evil.test/api/v1/${path}?key=${SITE}`,
      `https://shugoi.com.evil.test/api/v1/${path}?key=${SITE}`,
      `http://shugoi.com/api/v1/${path}?key=${SITE}`,
      `https://user:password@shugoi.com/api/v1/${path}?key=${SITE}`,
      `https://shugoi.com/api/v1/other?key=${SITE}`,
      `https://shugoi.com/api/v1/${path}?key=wrong`,
      `https://shugoi.com/api/v1/${path}`,
      `https://shugoi.com/api/v1/${path}?key=${SITE}&huge=${"a".repeat(24577)}`,
    ]) {
      const env = environment();
      let errors = 0;
      if (stream) env.window.__sg_wlcStream(url, () => assert.fail("Unexpected stream data"), () => errors++);
      else env.window.__sg_wlcRequest(url, error => { assert.equal(error, "wlc_transport_invalid"); errors++; });
      assert.equal(errors, 1);
      assert.equal(env.sockets.length, 0);
      assert.equal(env.fetches.length, 0);
      assert.equal(env.timers.size, 0);
    }
  }
});

test("stream uses {s}, delivers successive parsed updates and stops without spurious errors", () => {
  const env = environment();
  const received = [];
  let errors = 0;
  const url = requestUrl("whitelist-stream");
  const stop = env.window.__sg_wlcStream(url, value => received.push(JSON.stringify(value)), () => errors++);
  const ws = env.sockets[0];
  ws.emit("open");
  assert.deepEqual(JSON.parse(ws.sent[0]), { s: new URL(url).search });
  ws.emit("message", { data: '{"allowed":true}' });
  env.tick(9000);
  ws.emit("message", { data: '{"blocked":true,"reason":"revoked"}' });
  assert.deepEqual(received, ['{"allowed":true}', '{"blocked":true,"reason":"revoked"}']);
  stop();
  stop();
  ws.emit("error");
  ws.emit("message", { data: '{"late":true}' });
  assert.equal(errors, 0);
  assert.equal(received.length, 2);
  assert.equal(ws.closed, true);
});

test("stream malformed data and transport failures notify onError exactly once", () => {
  for (const kind of ["json", "binary", "oversize", "error", "close", "send"]) {
    const env = environment();
    let errors = 0;
    env.window.__sg_wlcStream(requestUrl("whitelist-stream"), () => assert.fail("Unexpected data"), () => errors++);
    const ws = env.sockets[0];
    if (kind === "send") ws.throwOnSend = true;
    ws.emit("open");
    if (kind === "json") ws.emit("message", { data: "{invalid" });
    else if (kind === "binary") ws.emit("message", { data: new Uint8Array([1]) });
    else if (kind === "oversize") ws.emit("message", { data: "a".repeat(16385) });
    else if (kind === "error" || kind === "close") ws.emit(kind);
    ws.emit("close");
    ws.emit("error");
    assert.equal(errors, 1, kind);
    assert.equal(ws.closed, true, kind);
  }
});

test("render uses same-origin HTTP with the exact signed token, mid and grant", async () => {
  const env = environment();
  const call = render(env);
  assert.equal(env.sockets.length, 0, "Render never goes to the WLC WebSocket");
  assert.equal(env.fetches.length, 1);
  const { url, options, resolve } = env.fetches[0];
  const target = new URL(url);
  assert.equal(target.origin, ORIGIN);
  assert.equal(target.pathname, "/__shugoi/render");
  assert.deepEqual(Object.fromEntries(target.searchParams), { token: TOKEN, mid: MID, grant: GRANT });
  assert.equal(options.credentials, "same-origin");
  assert.equal(options.cache, "no-store");
  assert.equal(options.redirect, "error");
  assert.equal(options.headers.accept, "application/json");
  assert.equal(options.signal instanceof AbortSignal, true);
  env.tick(6500);
  await flush();
  assert.equal(call.errors.length, 0, "No old 1500ms timeout");
  assert.equal(options.signal.aborted, false);
  const html = '<!doctype html><html lang="fr"><body>Cloud vérifié</body></html>';
  resolve(response({ html }));
  await flush();
  assert.deepEqual(call.success, [html]);
  assert.equal(call.errors.length, 0);
  assert.equal(env.timers.size, 0);
  env.tick(16000);
  assert.equal(call.success.length, 1);
});

test("only a render command with exactly the expected fields is accepted", () => {
  for (const message of [
    "invalid JSON", "null", "[]", '"render"',
    renderMessage({ cmd: "wlc" }),
    renderMessage({ token: "different-token" }),
    renderMessage({ token: 42 }),
    renderMessage({ mid: "bad" }),
    renderMessage({ mid: "A".repeat(64) }),
    renderMessage({ grant: 42 }),
    renderMessage({ grant: "x".repeat(257) }),
    renderMessage({ extra: true }),
    JSON.stringify({ cmd: "render", token: TOKEN, mid: MID }),
  ]) {
    const env = environment();
    const call = render(env, message);
    assert.equal(call.errors.length, 1);
    assert.equal(call.success.length, 0);
    assert.equal(env.fetches.length, 0);
    assert.equal(env.sockets.length, 0);
  }
});

test("render refuses HTTP failures, blocked/error decisions, missing HTML and invalid JSON", async () => {
  const responses = [
    response({ html: "must not pass" }, { status: 403 }),
    response({ html: "must not pass" }, { status: 503 }),
    response({ blocked: true, html: "must not pass" }),
    response({ error: "not_found", html: "must not pass" }),
    response(null), response({}), response({ html: "" }), response({ html: 123 }),
    response({ html: "not JSON" }, { type: "text/html" }),
    response(null, { invalidJson: true }),
  ];
  for (const result of responses) {
    const env = environment();
    const call = render(env);
    env.fetches[0].resolve(result);
    await flush();
    assert.equal(call.success.length, 0);
    assert.equal(call.errors.length, 1);
    assert.equal(env.timers.size, 0);
    env.tick(16000);
    await flush();
    assert.equal(call.errors.length, 1);
  }
});

test("render transport rejection and 8000ms timeout notify once and allow a retry", async () => {
  for (const kind of ["network", "timeout"]) {
    const env = environment();
    const call = render(env);
    if (kind === "network") env.fetches[0].reject(new Error("Synthetic network failure"));
    else env.tick(8000);
    await flush();
    assert.equal(call.errors.length, 1, kind);
    assert.equal(call.success.length, 0, kind);
    if (kind === "timeout") assert.equal(env.fetches[0].options.signal.aborted, true);
    env.fetches[0].resolve(response({ html: "late" }));
    env.tick(16000);
    await flush();
    assert.equal(call.errors.length, 1);
    assert.equal(call.success.length, 0);
    const retry = render(env);
    assert.equal(env.fetches.length, 2);
    env.fetches[1].resolve(response({ html: "retry verified" }));
    await flush();
    assert.deepEqual(retry.success, ["retry verified"]);
    assert.equal(retry.errors.length, 0);
  }
});

test("render failures explain how to retry without releasing HTML or replacing an existing block", async () => {
  for (const kind of ["network", "timeout", "403", "503", "refused", "already-blocked"]) {
    const env = environment();
    const events = [];
    const success = [];
    env.window.__sg_showBlock = (message, title) => events.push({ message, title });
    env.window.__sg_wsMux.send(renderMessage(), value => success.push(value), () => events.push("error"));
    if (kind === "network") env.fetches[0].reject(new Error("Synthetic network failure"));
    else if (kind === "timeout") env.tick(8000);
    else if (kind === "403" || kind === "503") env.fetches[0].resolve(response({ html: "must not display" }, { status: Number(kind) }));
    else {
      if (kind === "already-blocked") env.window.__sg_blocked = true;
      env.fetches[0].resolve(response({ blocked: true, html: "must not display" }));
    }
    await flush();
    assert.deepEqual(success, [], kind);
    assert.deepEqual(events, kind === "already-blocked" ? ["error"] : [
      "error",
      {
        message: "Retry verification.",
        title: "Unavailable",
      },
    ], kind);
    env.tick(16000);
    await flush();
    assert.equal(events.length, kind === "already-blocked" ? 1 : 2, "Failure notification is not repeated");
    assert.equal(env.timers.size, 0);
  }
});

test("render respects an existing or newly arrived guard block and forbids concurrent release", async () => {
  const blocked = environment();
  blocked.window.__sg_blocked = true;
  const refused = render(blocked);
  assert.equal(refused.errors.length, 1);
  assert.equal(blocked.fetches.length, 0);

  const env = environment();
  const first = render(env);
  const second = render(env);
  assert.equal(second.errors.length, 1);
  assert.equal(env.fetches.length, 1);
  env.window.__sg_blocked = true;
  env.fetches[0].resolve(response({ html: "must not display" }));
  await flush();
  assert.equal(first.errors.length, 1);
  assert.equal(first.success.length, 0);
});

test("pagehide closes active WLC sockets and installation is idempotent", () => {
  const env = environment();
  const initialRequest = env.window.__sg_wlcRequest;
  const initialMux = env.window.__sg_wsMux;
  env.reinstall();
  assert.equal(env.window.__sg_wlcRequest, initialRequest);
  assert.equal(env.window.__sg_wsMux, initialMux);
  let queryErrors = 0;
  let streamErrors = 0;
  env.window.__sg_wlcRequest(requestUrl(), error => { assert.ok(error); queryErrors++; });
  env.window.__sg_wlcStream(requestUrl("whitelist-stream"), () => {}, () => streamErrors++);
  env.pagehide();
  assert.equal(env.sockets.every(socket => socket.closed), true);
  assert.equal(queryErrors, 1);
  assert.equal(streamErrors, 1);
  assert.equal(env.timers.size, 0);
});
