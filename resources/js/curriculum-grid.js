export function readCellValue(cell) {
    return String(cell?.value ?? '').replace(/\r\n/g, '\n');
}

export function normalizeGridPatches(pending) {
    return Object.values(pending).map((patch) => {
        const domain = String(patch?.domain ?? '');
        const id = Number(patch?.id);
        const version = Number(patch?.version);

        if (!domain || !Number.isInteger(id) || id < 1 || !Number.isInteger(version) || version < 0) {
            throw new TypeError('Identitas draf tidak valid. Batalkan draf lalu muat ulang halaman.');
        }

        const changes = {};
        for (const [field, value] of Object.entries(patch?.changes ?? {})) {
            if (!['string', 'number', 'boolean'].includes(typeof value) && value !== null) {
                throw new TypeError(`Draf pada kolom ${field} tidak valid. Batalkan draf lalu isi kembali sel tersebut.`);
            }

            changes[String(field)] = String(value ?? '');
        }

        if (Object.keys(changes).length === 0) {
            throw new TypeError('Draf tidak memiliki perubahan yang dapat disimpan.');
        }

        return { domain, id, version, changes };
    });
}
