import test from 'node:test';
import assert from 'node:assert/strict';
import { normalizeNotification, notificationText, persistentNotifications } from '../../resources/js/persistent-notifications.js';

test('notification center stacks newest messages and keeps them until dismissed', () => {
    const center = persistentNotifications();
    center.push({ id: 'first', type: 'success', title: 'Pertama', message: 'Tersimpan' });
    center.push({ id: 'second', type: 'error', title: 'Kedua', message: 'Gagal' });

    assert.deepEqual(center.items.map((item) => item.id), ['second', 'first']);
    assert.equal(center.items.length, 2);

    center.dismiss('second');
    assert.deepEqual(center.items.map((item) => item.id), ['first']);
});

test('notification payload is normalized to plain serializable values', () => {
    const item = normalizeNotification({
        id: 10,
        type: 'error',
        title: 'Kalender gagal',
        message: 'Tidak ada perubahan.',
        details: ['Minggu bentrok'],
        suggestions: ['Susun ulang'],
        reference: 'ERR-123',
        focus_field: 'calendarStartsOn',
        created_at: '10:00:00',
    });

    assert.doesNotThrow(() => JSON.stringify(item));
    assert.equal(item.id, '10');
    assert.equal(item.focusField, 'calendarStartsOn');
    assert.match(notificationText(item), /Minggu bentrok/);
    assert.match(notificationText(item), /ERR-123/);
});

test('a successful calendar operation replaces only stale notifications from the same scope', () => {
    const center = persistentNotifications();
    center.push({ id: 'planner', type: 'warning', title: 'Planner', message: 'Periksa materi', scope: 'planner' });
    center.push({ id: 'calendar-error', type: 'error', title: 'Kalender gagal', message: 'Konfirmasi belum sinkron', scope: 'calendar-event-save', replace_scope: true });
    center.push({ id: 'calendar-success', type: 'success', title: 'Kalender berhasil', message: 'Event aktif', scope: 'calendar-event-save', replace_scope: true });

    assert.deepEqual(center.items.map((item) => item.id), ['calendar-success', 'planner']);
    assert.equal(center.items[0].scope, 'calendar-event-save');
    assert.equal(center.items[0].replaceScope, true);
});
