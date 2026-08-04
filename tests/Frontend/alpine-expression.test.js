import assert from 'node:assert/strict';
import test from 'node:test';

test('matrix item expression compiles with Laravel Js escaping and source punctuation', () => {
    const expression = String.raw`openMatrixItem(JSON.parse('{\u0022id\u0022:50705,\u0022stable_code\u0022:\u0022PAUD \\\\ TILAWATI \/ \\u0022khusus\\u0022\u0022,\u0022content\u0022:\u0022Halaman 23 \\u0027penguatan\\u0027\\nDoa: \\u0631\\u064E\\u0628\\u0650\\u0651\u0022}'))`;
    let received = null;
    const execute = new Function('openMatrixItem', expression);

    assert.doesNotThrow(() => execute((item) => { received = item; }));
    assert.equal(received.id, 50705);
    assert.equal(received.stable_code, 'PAUD \\ TILAWATI / "khusus"');
    assert.match(received.content, /penguatan/);
    assert.match(received.content, /Doa:/);
});
