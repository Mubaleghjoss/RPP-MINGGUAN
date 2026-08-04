const allowedTypes = new Set(['success', 'error', 'warning', 'info']);

const stringList = (value) => (Array.isArray(value) ? value : [])
    .map((item) => String(item ?? '').trim())
    .filter(Boolean);

export function normalizeNotification(notification = {}) {
    const type = allowedTypes.has(notification.type) ? notification.type : 'info';

    return {
        id: String(notification.id || `${Date.now()}-${Math.random().toString(36).slice(2)}`),
        type,
        title: String(notification.title || (type === 'error' ? 'Tindakan gagal' : 'Informasi')),
        message: String(notification.message || ''),
        details: stringList(notification.details),
        suggestions: stringList(notification.suggestions),
        reference: notification.reference ? String(notification.reference) : '',
        focusField: notification.focus_field ? String(notification.focus_field) : '',
        scope: notification.scope ? String(notification.scope) : '',
        replaceScope: Boolean(notification.replace_scope),
        createdAt: notification.created_at
            ? String(notification.created_at)
            : new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(new Date()),
        copied: false,
    };
}

export function notificationText(notification) {
    const item = normalizeNotification(notification);
    const lines = [item.title, item.message];
    if (item.details.length) lines.push('Penyebab:', ...item.details.map((detail) => `- ${detail}`));
    if (item.suggestions.length) lines.push('Saran:', ...item.suggestions.map((suggestion) => `- ${suggestion}`));
    if (item.reference) lines.push(`Kode referensi: ${item.reference}`);
    lines.push(`Waktu: ${item.createdAt}`);

    return lines.filter(Boolean).join('\n');
}

export function persistentNotifications(initial = []) {
    return {
        items: (Array.isArray(initial) ? initial : []).map(normalizeNotification).reverse(),

        push(notification) {
            const item = normalizeNotification(notification);
            if (item.scope && item.replaceScope) {
                this.items = this.items.filter((existing) => existing.scope !== item.scope);
            }
            this.items.unshift(item);
        },

        dismiss(id) {
            this.items = this.items.filter((item) => item.id !== id);
        },

        focusProblem(item) {
            if (!item.focusField || typeof window === 'undefined') return;
            window.focusValidationField?.(item.focusField);
        },

        async copyDetails(item) {
            const content = notificationText(item);
            try {
                await navigator.clipboard.writeText(content);
                item.copied = true;
                window.setTimeout(() => { item.copied = false; }, 2000);
            } catch (error) {
                console.error('Detail notifikasi tidak dapat disalin.', error);
            }
        },
    };
}
