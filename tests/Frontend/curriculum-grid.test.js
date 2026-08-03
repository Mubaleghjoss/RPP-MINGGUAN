import assert from 'node:assert/strict';
import test from 'node:test';

import { normalizeGridPatches, readCellValue } from '../../resources/js/curriculum-grid.js';

test('desktop input and mobile textarea produce plain cell values', () => {
    assert.equal(readCellValue({ tagName: 'INPUT', value: '1' }), '1');
    assert.equal(readCellValue({ tagName: 'TEXTAREA', value: '1\r\npertemuan' }), '1\npertemuan');
});

test('syllabus allocation and sessions become a serializable Livewire payload', () => {
    const pending = {
        33: {
            domain: 'syllabus',
            id: 33,
            version: 0,
            changes: {
                allocation_text: 'Tentatif (Sabtu/Minggu)',
                recommended_sessions: '1',
            },
        },
    };

    const patches = normalizeGridPatches(pending);

    assert.deepEqual(patches, [{
        domain: 'syllabus',
        id: 33,
        version: 0,
        changes: {
            allocation_text: 'Tentatif (Sabtu/Minggu)',
            recommended_sessions: '1',
        },
    }]);
    assert.doesNotThrow(() => JSON.stringify(patches));
});

test('reactive or circular cell values are rejected with the field name', () => {
    const reactiveValue = { pending: {} };
    reactiveValue.pending[33] = reactiveValue;

    assert.throws(() => normalizeGridPatches({
        33: {
            domain: 'syllabus',
            id: 33,
            version: 0,
            changes: { recommended_sessions: reactiveValue },
        },
    }), /recommended_sessions/);
});

test('fill-down changes remain plain across multiple syllabus rows', () => {
    const patches = normalizeGridPatches({
        33: { domain: 'syllabus', id: 33, version: 0, changes: { recommended_sessions: '1' } },
        34: { domain: 'syllabus', id: 34, version: 2, changes: { recommended_sessions: '1' } },
    });

    assert.equal(patches.length, 2);
    assert.deepEqual(patches.map((patch) => patch.changes.recommended_sessions), ['1', '1']);
    assert.doesNotThrow(() => JSON.stringify(patches));
});
