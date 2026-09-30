'use strict';

const assert = require('node:assert/strict');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const vm = require('node:vm');

const fixtures = JSON.parse(execFileSync('php', [path.join(__dirname, 'obfuscator-fixtures.php')], { encoding: 'utf8', maxBuffer: 4 * 1024 * 1024 }));
for (const [index, pair] of fixtures.pairs.entries()) {
    const original = { window: {} };
    const generated = { window: {} };
    new vm.Script(pair.source, { filename: `original-${index}.js` }).runInNewContext(original);
    new vm.Script(pair.generated, { filename: `obfuscated-${index}.js` }).runInNewContext(generated);
    assert.equal(JSON.stringify(generated.window.result), JSON.stringify(original.window.result));
}
new vm.Script(fixtures.bootstrap, { filename: 'actual-generated-bootstrap.js' });
assert.match(fixtures.bootstrap, /var _D=function/);
console.log('Obfuscator regression passed: regex literals, comment boundaries, nested templates, escaped URLs, Unicode and surrogate code units; actual generated bootstrap compiles.');
