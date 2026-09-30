'use strict';

// Local-only integration test; requires Node and the ws module (for example Debian node-ws).
const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const { createHash, createHmac } = require('node:crypto');
const net = require('node:net');
const path = require('node:path');
const WebSocket = require('ws');

const origin = 'https://gateway-test.invalid';
const siteKey = 'gateway-integration-test';
const secret = 'synthetic-gateway-integration-secret';
const userAgent = 'ShugoiGatewayIntegrationTest';

function solve(challenge) {
    for (let n = 0; ; n++) {
        const solution = n.toString(16);
        const hash = createHash('sha256').update(`${challenge.salt}:${solution}`).digest();
        let bits = 0;
        for (const byte of hash) {
            if (!byte) { bits += 8; continue; }
            bits += Math.clz32(byte) - 24;
            break;
        }
        if (bits >= challenge.difficulty) return `${challenge.ts}:${challenge.nonce}:${solution}`;
    }
}

async function open(port, headers = {}) {
    const ws = new WebSocket(`ws://127.0.0.1:${port}/__sg_challenge/ws`, { headers: { Origin: origin, 'User-Agent': userAgent, ...headers } });
    await new Promise((resolve, reject) => {
        ws.once('open', resolve);
        ws.once('error', reject);
    });
    return ws;
}

function exchange(ws, payload) {
    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error('WebSocket response timeout')), 5000);
        ws.once('message', data => { clearTimeout(timer); resolve(JSON.parse(data.toString())); });
        ws.once('error', error => { clearTimeout(timer); reject(error); });
        ws.send(JSON.stringify(payload));
    });
}

(async () => {
    const socket = net.createServer();
    await new Promise(resolve => socket.listen(0, '127.0.0.1', resolve));
    const port = socket.address().port;
    await new Promise(resolve => socket.close(resolve));
    const gateway = spawn('php', ['bin/shugoi-websocket.php'], {
        cwd: path.resolve(__dirname, '..'),
        env: { ...process.env, SHUGOI_SITE_KEY: siteKey, SHUGOI_SIGNING_SECRET: secret, SHUGOI_ORIGIN: origin, SHUGOI_WS_PORT: String(port), SHUGOI_POW_DIFFICULTY: '4', SHUGOI_TRUST_PROXY: '0' },
        stdio: ['ignore', 'pipe', 'pipe'],
    });
    let errors = '';
    gateway.stderr.on('data', data => { errors += data; });
    try {
        await new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error(`Gateway startup timeout: ${errors}`)), 8000);
            gateway.stdout.on('data', data => { if (data.toString().includes('listening')) { clearTimeout(timer); resolve(); } });
            gateway.once('exit', code => { clearTimeout(timer); reject(new Error(`Gateway exited ${code}: ${errors}`)); });
        });

        const ws = await open(port, { 'X-Real-IP': '198.51.100.10' });
        const challenge = await exchange(ws, { cmd: 'pow-start', siteKey });
        assert.equal(challenge.type, 'pow-challenge');
        const result = await exchange(ws, { cmd: 'pow-proof', proof: solve(challenge) });
        assert.equal(result.type, 'pow-ok');
        const [version, timestamp, nonce, binding, signature] = result.receipt.split('.');
        assert.equal(version, 'v1');
        assert.match(nonce, /^[a-f0-9]{32}$/);
        assert.equal(binding, createHash('sha256').update(`127.0.0.1\n${userAgent}`).digest('hex'));
        assert.equal(signature, createHmac('sha256', secret).update(`pow-ws-receipt:${siteKey}:${timestamp}:${nonce}:${binding}`).digest('hex'));

        const wrongSite = await open(port);
        assert.equal((await exchange(wrongSite, { cmd: 'pow-start', siteKey: 'another-site' })).error, 'invalid_start');

        const crossConnection = await open(port);
        await exchange(crossConnection, { cmd: 'pow-start', siteKey });
        assert.equal((await exchange(crossConnection, { cmd: 'pow-proof', proof: solve(challenge) })).error, 'invalid_proof');

        await assert.rejects(open(port, { Origin: 'https://untrusted.invalid' }), /403/);
        const oversized = await open(port);
        await new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error('Oversized frame was not closed')), 3000);
            oversized.once('close', code => { clearTimeout(timer); try { assert.equal(code, 1009); resolve(); } catch (error) { reject(error); } });
            oversized.send('x'.repeat(5000));
        });
        console.log('Gateway integration passed: live challenge/proof, signed receipt binding, untrusted IP header, origin rejection, cross-connection rejection, 4 KiB frame limit.');
    } finally {
        gateway.kill('SIGTERM');
        await new Promise(resolve => { if (gateway.exitCode !== null) resolve(); else gateway.once('exit', resolve); });
    }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
