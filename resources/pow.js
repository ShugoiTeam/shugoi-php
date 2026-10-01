(function () {
  "use strict";
  var options = window.__sg_powOptions;
  var socket, timer, phase = "connecting", stopped = false;
  function fail() {
    if (stopped) return;
    stopped = true;
    clearTimeout(timer);
    if (socket) socket.close();
    document.getElementById("sg-status").textContent = "Browser verification is unavailable. Please reload to try again.";
  }
  try {
    var endpoint = new URL(options.url, location.href);
    if (endpoint.protocol === "https:") endpoint.protocol = "wss:";
    if (endpoint.protocol === "http:") endpoint.protocol = "ws:";
    if (!/^wss?:$/.test(endpoint.protocol) || endpoint.username || endpoint.password
        || (location.protocol === "https:" && endpoint.protocol !== "wss:")) throw new Error("invalid_endpoint");
    socket = new WebSocket(endpoint.href);
    timer = setTimeout(fail, 55000);
    socket.onopen = function () {
      phase = "challenge";
      socket.send(JSON.stringify({cmd: "pow-start", siteKey: options.siteKey}));
    };
    socket.onerror = fail;
    socket.onclose = function () { if (phase !== "done") fail(); };
    socket.onmessage = async function (event) {
      try {
        if (typeof event.data !== "string" || event.data.length > 4096) return fail();
        var message = JSON.parse(event.data);
        if (phase === "challenge" && message.type === "pow-challenge") {
          if (!Number.isInteger(message.ts) || !/^[0-9a-f]{16}$/.test(message.nonce)
              || !/^[0-9a-f]{64}$/.test(message.salt) || !Number.isInteger(message.difficulty)
              || message.difficulty < 1 || message.difficulty > 22) return fail();
          phase = "solving";
          var encoder = new TextEncoder();
          for (var n = 0; n < 16777216 && !stopped; n++) {
            var digest = new Uint8Array(await crypto.subtle.digest("SHA-256", encoder.encode(message.salt + ":" + n.toString(16))));
            var bits = 0;
            for (var i = 0; i < digest.length; i++) {
              if (digest[i] === 0) { bits += 8; continue; }
              bits += Math.clz32(digest[i]) - 24;
              break;
            }
            if (bits >= message.difficulty) {
              if (stopped) return;
              phase = "receipt";
              socket.send(JSON.stringify({cmd: "pow-proof", proof: message.ts + ":" + message.nonce + ":" + n.toString(16)}));
              return;
            }
            if (n % 256 === 255) await new Promise(function (resolve) { setTimeout(resolve, 0); });
          }
          return fail();
        }
        if (phase === "receipt" && message.type === "pow-ok"
            && /^v1\.[0-9]{10}\.[0-9a-f]{32}\.[0-9a-f]{64}\.[0-9a-f]{64}$/.test(message.receipt)) {
          phase = "done"; stopped = true; clearTimeout(timer); socket.close();
          var target = new URL(location.href);
          if (target.pathname === "/__sg_challenge") { target.pathname = "/"; target.search = ""; }
          target.searchParams.delete("sg_proof");
          target.searchParams.set("sg_receipt", message.receipt);
          location.replace(target.href);
          return;
        }
        fail();
      } catch (_) { fail(); }
    };
    addEventListener("pagehide", function () { stopped = true; clearTimeout(timer); socket.close(); }, {once: true});
  } catch (_) { fail(); }
})();
