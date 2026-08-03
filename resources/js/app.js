import './bootstrap';

window.spreadsheetGrid = (wire, domain) => ({
    wire,
    domain,
    pending: {},
    selected: {},
    activeCell: null,
    reason: '',
    saving: false,
    clientMessage: '',

    init() {
        this.prepareCells();
        this.$root.addEventListener('input', (event) => this.capture(event.target));
        this.$root.addEventListener('change', (event) => this.capture(event.target));
        this.$root.addEventListener('focusin', (event) => {
            if (event.target.matches('[data-grid-cell]')) this.activeCell = event.target;
        });
        this.$root.addEventListener('keydown', (event) => this.navigate(event));
        this.$root.addEventListener('paste', (event) => this.paste(event));
        this.$root.addEventListener('grid-saved', () => this.prepareCells());
        this.beforeUnload = (event) => {
            if (this.dirtyCount === 0) return;
            event.preventDefault();
            event.returnValue = '';
        };
        this.navigateGuard = (event) => {
            if (this.dirtyCount > 0 && !window.confirm('Ada perubahan yang belum disimpan. Tinggalkan halaman dan buang draf?')) event.preventDefault();
        };
        window.addEventListener('beforeunload', this.beforeUnload);
        document.addEventListener('livewire:navigate', this.navigateGuard);
    },

    destroy() {
        window.removeEventListener('beforeunload', this.beforeUnload);
        document.removeEventListener('livewire:navigate', this.navigateGuard);
    },

    get dirtyCount() {
        return Object.values(this.pending).reduce((total, patch) => total + Object.keys(patch.changes).length, 0);
    },

    prepareCells() {
        this.$nextTick(() => {
            this.$root.querySelectorAll('[data-grid-cell]').forEach((cell) => {
                if (cell.dataset.original === undefined) cell.dataset.original = this.valueOf(cell);
                if (!cell.getAttribute('aria-label')) cell.setAttribute('aria-label', `${cell.dataset.field}, baris ${cell.dataset.id}`);
            });
        });
    },

    valueOf(cell) {
        return String(cell.value ?? '').replace(/\r\n/g, '\n');
    },

    capture(cell) {
        if (!cell?.matches?.('[data-grid-cell]')) return;
        const id = String(cell.dataset.id);
        const field = cell.dataset.field;
        const original = String(cell.dataset.original ?? '');
        const value = this.valueOf(cell);
        const next = { ...this.pending };
        const activeDomain = this.$root.dataset.gridDomain || this.domain;
        const patch = next[id] ? { ...next[id], changes: { ...next[id].changes } } : {
            domain: activeDomain,
            id: Number(id),
            version: Number(cell.dataset.version),
            changes: {},
        };

        if (value === original) delete patch.changes[field];
        else patch.changes[field] = value;

        if (Object.keys(patch.changes).length === 0) delete next[id];
        else next[id] = patch;
        this.pending = next;
        this.markMirrors(id, field, value, value !== original);
        this.clientMessage = '';
    },

    markMirrors(id, field, value, dirty) {
        this.$root.querySelectorAll(`[data-grid-cell][data-id="${CSS.escape(String(id))}"][data-field="${CSS.escape(field)}"]`).forEach((cell) => {
            if (cell !== this.activeCell && this.valueOf(cell) !== value) cell.value = value;
            cell.classList.toggle('grid-cell-dirty', dirty);
            cell.dataset.dirty = dirty ? 'true' : 'false';
            cell.title = dirty ? 'Berubah — belum disimpan' : '';
        });
    },

    toggleRow(id, checked) {
        const next = { ...this.selected };
        if (checked) next[String(id)] = true;
        else delete next[String(id)];
        this.selected = next;
    },

    fillDown() {
        if (!this.activeCell) {
            this.clientMessage = 'Pilih sel sumber terlebih dahulu.';
            return;
        }
        const ids = Object.keys(this.selected);
        if (ids.length === 0) {
            this.clientMessage = 'Pilih satu atau beberapa baris tujuan.';
            return;
        }
        const field = this.activeCell.dataset.field;
        const value = this.valueOf(this.activeCell);
        ids.forEach((id) => {
            const target = this.$root.querySelector(`[data-grid-cell][data-id="${CSS.escape(id)}"][data-field="${CSS.escape(field)}"]`);
            if (target) {
                target.value = value;
                this.capture(target);
            }
        });
    },

    clearDraft() {
        Object.entries(this.pending).forEach(([id, patch]) => {
            Object.keys(patch.changes).forEach((field) => {
                this.$root.querySelectorAll(`[data-grid-cell][data-id="${CSS.escape(id)}"][data-field="${CSS.escape(field)}"]`).forEach((cell) => {
                    cell.value = cell.dataset.original ?? '';
                    cell.classList.remove('grid-cell-dirty');
                    cell.dataset.dirty = 'false';
                    cell.title = '';
                });
            });
        });
        this.pending = {};
        this.clientMessage = '';
    },

    async save() {
        if (this.dirtyCount === 0 || this.saving) return;
        if (this.reason.trim().length < 5) {
            this.clientMessage = 'Alasan revisi minimal 5 karakter.';
            return;
        }
        this.saving = true;
        this.clientMessage = '';
        const patches = Object.values(this.pending);
        try {
            const result = await this.wire.savePatches(patches, this.reason);
            if (!result?.ok) {
                this.clientMessage = result?.message ?? 'Perubahan gagal disimpan.';
                return;
            }
            this.pending = {};
            this.reason = '';
            this.$nextTick(() => {
                this.$root.querySelectorAll('[data-grid-cell]').forEach((cell) => {
                    cell.dataset.original = this.valueOf(cell);
                    cell.classList.remove('grid-cell-dirty');
                    cell.dataset.dirty = 'false';
                    cell.title = '';
                });
            });
        } catch (error) {
            this.clientMessage = 'Koneksi terputus atau data berubah. Muat ulang lalu coba kembali.';
        } finally {
            this.saving = false;
        }
    },

    handleShortcut(event) {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
            event.preventDefault();
            this.save();
        }
    },

    visibleCells() {
        return [...this.$root.querySelectorAll('[data-grid-table] [data-grid-cell]')].filter((cell) => cell.offsetParent !== null);
    },

    navigate(event) {
        const cell = event.target;
        if (!cell.matches?.('[data-grid-table] [data-grid-cell]')) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            cell.value = cell.dataset.original ?? '';
            this.capture(cell);
            cell.blur();
            return;
        }
        if (event.key === 'F2' || (event.key === 'Enter' && !event.shiftKey && cell.tagName !== 'TEXTAREA')) {
            event.preventDefault();
            cell.select?.();
            return;
        }
        if (!['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Tab'].includes(event.key)) return;
        if (['ArrowLeft', 'ArrowRight'].includes(event.key) && !event.altKey && cell.selectionStart !== cell.selectionEnd) return;

        const rows = [...this.$root.querySelectorAll('[data-grid-table] tbody tr[data-grid-row]')];
        const row = cell.closest('tr');
        const rowIndex = rows.indexOf(row);
        const rowCells = [...row.querySelectorAll('[data-grid-cell]')];
        const columnIndex = rowCells.indexOf(cell);
        let nextRow = rowIndex;
        let nextColumn = columnIndex;
        if (event.key === 'ArrowUp') nextRow--;
        if (event.key === 'ArrowDown' || event.key === 'Enter') nextRow++;
        if (event.key === 'ArrowLeft' || (event.key === 'Tab' && event.shiftKey)) nextColumn--;
        if (event.key === 'ArrowRight' || (event.key === 'Tab' && !event.shiftKey)) nextColumn++;
        if (nextColumn < 0) { nextRow--; nextColumn = (rows[nextRow]?.querySelectorAll('[data-grid-cell]').length ?? 1) - 1; }
        if (nextRow >= 0 && nextRow < rows.length) {
            const candidates = [...rows[nextRow].querySelectorAll('[data-grid-cell]')];
            const next = candidates[Math.min(nextColumn, candidates.length - 1)];
            if (next) { event.preventDefault(); next.focus(); next.select?.(); }
        }
    },

    paste(event) {
        const start = event.target;
        if (!start.matches?.('[data-grid-table] [data-grid-cell]')) return;
        const text = event.clipboardData?.getData('text/plain');
        if (!text || (!text.includes('\t') && !text.includes('\n'))) return;
        event.preventDefault();
        const pastedRows = text.replace(/\r/g, '').replace(/\n$/, '').split('\n').map((row) => row.split('\t'));
        const tableRows = [...this.$root.querySelectorAll('[data-grid-table] tbody tr[data-grid-row]')];
        const startRow = tableRows.indexOf(start.closest('tr'));
        const startColumn = [...start.closest('tr').querySelectorAll('[data-grid-cell]')].indexOf(start);
        pastedRows.forEach((values, rowOffset) => {
            const targetRow = tableRows[startRow + rowOffset];
            if (!targetRow) return;
            const targetCells = [...targetRow.querySelectorAll('[data-grid-cell]')];
            values.forEach((value, columnOffset) => {
                const target = targetCells[startColumn + columnOffset];
                if (!target) return;
                target.value = value;
                this.capture(target);
            });
        });
    },
});
