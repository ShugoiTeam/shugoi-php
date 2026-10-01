import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import assert from 'node:assert/strict';

const source = readFileSync(new URL('../src/Laravel/LivewireNavigation.php', import.meta.url), 'utf8');
const script = source.match(/<<<'JS'\n([\s\S]*?)\nJS;/)[1];

function navigate(detail, alreadyPrevented = false) {
    const calls = [];
    let listener;
    vm.runInNewContext(script, {
        URL,
        document: { addEventListener(name, callback) { assert.equal(name, 'livewire:navigate'); listener = callback; } },
        location: {
            href: 'https://example.test/login', origin: 'https://example.test',
            assign: url => calls.push(['assign', url]),
            replace: url => calls.push(['replace', url]),
        },
    });
    const event = { detail, defaultPrevented: alreadyPrevented, preventDefault() { this.defaultPrevented = true; } };
    listener(event);
    return { calls, prevented: event.defaultPrevented };
}

test('Livewire links request a new protected document', () => {
    assert.deepEqual(navigate({ url: '/password/request?next=%2Faccount', history: false }), {
        calls: [['assign', 'https://example.test/password/request?next=%2Faccount']], prevented: true,
    });
});
test('history navigation reloads without adding a duplicate history entry', () => {
    assert.deepEqual(navigate({ url: new URL('https://example.test/register'), history: true, cached: true }), {
        calls: [['replace', 'https://example.test/register']], prevented: true,
    });
});
test('external, malformed and previously cancelled navigation is left alone', () => {
    for (const detail of [undefined, {}, { url: 'https://outside.test/' }, { url: 'javascript:alert(1)' }, { url: 'http://[' }]) {
        assert.deepEqual(navigate(detail), { calls: [], prevented: false });
    }
    assert.deepEqual(navigate({ url: '/register' }, true), { calls: [], prevented: true });
});
