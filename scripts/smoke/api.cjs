/* Local smoke fixture only. This is not the Shugoi detection/security guard. */
const { createServer } = require('node:http');
const { createHmac, timingSafeEqual } = require('node:crypto');
const { WebSocketServer } = require(process.env.SHUGOI_SMOKE_WS_MODULE || 'ws');

const SITE = 'sg_sk_test_php_smoke';
const SECRET = 'synthetic-local-php-smoke-secret-not-production';
const MID = 'a'.repeat(64);
const counts = { requests: 0, websocketConnections: 0, wlc: 0, allowed: 0, denied: 0, stream: 0 };
const mac = value => createHmac('sha256', SECRET).update(value).digest('hex');
const flags = { enableHeadlessCheck: false, enableRateLimit: false, enableContentReplacementCheck: false };

const detect = `(function(){
  var scenario = location.pathname.indexOf('/denied') >= 0 ? 'denied' : location.pathname.indexOf('/slow') >= 0 ? 'slow' : 'allowed';
  var mid = ${JSON.stringify(MID)};
  function fail(message) {
    window.__sg_blocked = true;
    window.__sg_guardsReady = true;
    window.__sg_showBlock(message, 'Fixture locale : accès refusé');
  }
  setTimeout(function(){
    var q = new URLSearchParams({ key: window.__sg_siteKey, mid: mid, raw: scenario, token: window.__sg_token, drift: '0' });
    window.__sg_wlcRequest(window.__sg_baseUrl + '/wlc?' + q.toString(), function(error, text){
      if (error) return fail('Échec du transport local : ' + error);
      var decision;
      try { decision = JSON.parse(text); } catch (_) { return fail('Réponse locale invalide'); }
      if (!decision.allowed || !decision.sig || !decision.grant) return fail('Le scénario de refus ne libère aucun HTML protégé.');
      window.__sg_detectMid = mid;
      window.__sg_grant = decision.grant;
      window.__sg_renderStarted = true;
      window.__sg_guardsReady = true;
      window.__sg_wsMux.send(JSON.stringify({ cmd: 'render', token: window.__sg_token, mid: mid, grant: decision.grant }), function(html){
        window.__sg_renderDone = true;
        window.__sg_renderStarted = false;
        document.open('text/html'); document.write(html); document.close();
      }, function(){ window.__sg_renderStarted = false; });
    });
  }, 0);
})();`;

function json(response, value, status = 200) {
  response.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store', 'Access-Control-Allow-Origin': 'http://127.0.0.1:4195' });
  response.end(JSON.stringify(value));
}

const server = createServer((request, response) => {
  counts.requests++;
  const url = new URL(request.url, 'http://127.0.0.1:4196');
  if (url.pathname === '/__smoke/status') return json(response, counts);
  if (url.pathname === '/api/v1/whitelist') return json(response, { whitelistedMachines: [], detectionFlags: flags, skipPaths: [], supportEmail: 'fixture@example.test' });
  if (url.pathname === '/api/v1/validate-key') return json(response, { valid: true, mode: 'test' });
  if (url.pathname === '/api/v1/clock-drift') return json(response, { t: Date.now() });
  if (url.pathname === '/api/v1/guard-detect' || url.pathname === '/api/v1/guard') {
    response.writeHead(200, { 'Content-Type': 'application/javascript', 'Cache-Control': 'no-store' });
    return response.end(url.pathname.endsWith('guard-detect') ? detect : '/* Local smoke guard: detection fixture owns the handshake. */');
  }
  if (url.pathname === '/api/v1/event') return json(response, { ok: true });
  return json(response, { error: 'fixture_endpoint_not_found' }, 404);
});

const ws = new WebSocketServer({ server, path: '/api/v1/ws-wlc', maxPayload: 32768 });
ws.on('connection', socket => {
  counts.websocketConnections++;
  socket.on('message', bytes => {
    let payload;
    try { payload = JSON.parse(bytes.toString()); } catch (_) { return socket.close(1008, 'invalid_json'); }
    if (payload.cmd === 'clock-ping') return socket.send(JSON.stringify({ cmd: 'clock-pong', tSend: payload.tSend, tRecv: Date.now(), tOut: Date.now() }));
    if (typeof payload.s === 'string') { counts.stream++; return socket.send(JSON.stringify({ allowed: true, detectionFlags: flags })); }
    if (typeof payload.q !== 'string' || Object.keys(payload).length !== 1) return socket.close(1008, 'expected_q');
    counts.wlc++;
    const query = new URLSearchParams(payload.q);
    const token = query.get('token') || '';
    const parts = token.split(':');
    const tokenMac = parts.length === 4 ? mac(parts.slice(0, 3).join(':')) : '';
    const valid = query.get('key') === SITE && query.get('mid') === MID && parts[0] === SITE
      && /^[a-f0-9]{64}$/.test(parts[3] || '') && tokenMac.length === parts[3].length
      && timingSafeEqual(Buffer.from(tokenMac), Buffer.from(parts[3]));
    const allowed = valid && query.get('raw') !== 'denied';
    if (allowed) counts.allowed++; else counts.denied++;
    const timestamp = Math.floor(Date.now() / 1000).toString(36);
    const grant = allowed ? timestamp + ':' + mac('render-grant:' + [SITE, MID, token, timestamp].join(':')) : '';
    const decision = { allowed, reason: allowed ? 'fixture_allowed' : 'fixture_denied', grant, sig: mac('fixture:' + token + ':' + allowed) };
    setTimeout(() => { if (socket.readyState === 1) socket.send(JSON.stringify(decision)); }, query.get('raw') === 'slow' ? 1900 : 0);
  });
});
server.listen(4196, '127.0.0.1', () => console.log('Synthetic Shugoi API/WS: http://127.0.0.1:4196'));
process.on('SIGTERM', () => { for (const client of ws.clients) client.terminate(); server.close(); });
