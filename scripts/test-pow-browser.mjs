import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createHash, webcrypto } from 'node:crypto';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../resources/pow.js', import.meta.url), 'utf8');
const receipt = `v1.1790800000.${'a'.repeat(32)}.${'b'.repeat(64)}.${'c'.repeat(64)}`;
const challenge = { type: 'pow-challenge', ts: 1790800000, nonce: 'a'.repeat(16), salt: 'b'.repeat(64), difficulty: 2 };

function browser(endpoint = '/__sg_challenge/ws') {
  const sent = [], navigations = [], timers = new Map(), listeners = new Map(), status = { textContent: '' };
  let socket, counter = 0;
  class Socket {
    constructor(url) { this.url = url; this.closed = false; socket = this; }
    send(data) { sent.push(JSON.parse(data)); }
    close() { this.closed = true; this.onclose?.(); }
  }
  const location = { href: 'https://site.test/catalogue?tag=a&tag=b#section', protocol: 'https:', replace: url => navigations.push(url) };
  vm.runInNewContext(source, {
    window: { __sg_powOptions: { siteKey: 'fixture', url: endpoint } }, WebSocket: Socket, location,
    document: { getElementById: () => status }, URL, TextEncoder, crypto: webcrypto,
    setTimeout: callback => { timers.set(++counter, callback); return counter; }, clearTimeout: id => timers.delete(id),
    addEventListener: (name, callback) => listeners.set(name, callback),
  });
  return { sent, navigations, status, timers, listeners, get socket() { return socket; } };
}

test('challenge and solution travel only over WebSocket, followed by a signed receipt navigation', async () => {
  const env = browser();
  assert.equal(env.socket.url, 'wss://site.test/__sg_challenge/ws');
  env.socket.onopen();
  assert.deepEqual(env.sent, [{ cmd: 'pow-start', siteKey: 'fixture' }]);
  await env.socket.onmessage({ data: JSON.stringify(challenge) });
  assert.equal(env.sent[1].cmd, 'pow-proof');
  assert.match(env.sent[1].proof, /^1790800000:aaaaaaaaaaaaaaaa:[0-9a-f]+$/);
  const solution = env.sent[1].proof.split(':')[2];
  assert.ok(createHash('sha256').update(`${challenge.salt}:${solution}`).digest()[0] < 64);
  await env.socket.onmessage({ data: JSON.stringify({ type: 'pow-ok', receipt }) });
  const destination = new URL(env.navigations[0]);
  assert.equal(destination.origin, 'https://site.test');
  assert.equal(destination.pathname, '/catalogue');
  assert.deepEqual(destination.searchParams.getAll('tag'), ['a', 'b']);
  assert.equal(destination.hash, '#section');
  assert.equal(destination.searchParams.get('sg_receipt'), receipt);
  assert.equal(destination.searchParams.has('sg_proof'), false);
  assert.equal(env.socket.closed, true);
  assert.equal(env.timers.size, 0);
});

test('a receipt before proof, malformed frames and excessive difficulty fail closed', async () => {
  for (const message of [
    JSON.stringify({ type: 'pow-ok', receipt }), '{broken', 'x'.repeat(4097),
    JSON.stringify({ ...challenge, difficulty: 23 }), JSON.stringify({ ...challenge, nonce: 'wrong' }),
    JSON.stringify({ ...challenge, salt: 'wrong' }), JSON.stringify({ ...challenge, ts: '1790800000' }),
  ]) {
    const env = browser();
    env.socket.onopen();
    await env.socket.onmessage({ data: message });
    assert.equal(env.navigations.length, 0);
    assert.equal(env.socket.closed, true);
    assert.match(env.status.textContent, /unavailable/);
  }
});

test('socket errors, early closure and timeouts never navigate or retry silently', () => {
  for (const kind of ['error', 'close', 'timeout']) {
    const env = browser();
    env.socket.onopen();
    if (kind === 'timeout') [...env.timers.values()][0]();
    else env.socket[`on${kind}`]();
    assert.equal(env.navigations.length, 0);
    assert.equal(env.sent.length, 1);
    assert.match(env.status.textContent, /unavailable/);
    assert.equal(env.timers.size, 0);
  }
});

test('insecure or credential-bearing endpoints are rejected on HTTPS', () => {
  for (const endpoint of ['ws://site.test/path', 'wss://name:secret@site.test/path', 'javascript:alert(1)']) {
    const env = browser(endpoint);
    assert.equal(env.socket, undefined);
    assert.equal(env.navigations.length, 0);
    assert.match(env.status.textContent, /unavailable/);
  }
});

test('page exit closes the socket and cancels the pending timeout', () => {
  const env = browser();
  env.socket.onopen();
  env.listeners.get('pagehide')();
  assert.equal(env.socket.closed, true);
  assert.equal(env.timers.size, 0);
  assert.equal(env.navigations.length, 0);
});
