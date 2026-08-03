# Sistem RPP Global Mingguan

Aplikasi Laravel 12 untuk menjaga alur **GGB → Silabus → RPP global mingguan** pada 17 jenjang. Aplikasi berjalan lokal, menggunakan Livewire, Tailwind CSS, MariaDB/MySQL, dan PhpSpreadsheet.

## Fitur utama

- Dashboard kelengkapan 17 jenjang dan audit 34 dokumen sumber.
- Master GGB, silabus, relasi, kalender akademik, dan RPP mingguan.
- Editor tabel bergaya Excel: pencarian, filter, 100 baris per halaman, sticky header/ID, navigasi keyboard, paste TSV, Isi ke bawah, dan simpan massal.
- Riwayat revisi per batch dan per baris, optimistic locking, serta pemulihan yang tetap berjejak.
- Planner deterministik hanya pada minggu efektif dan mempertahankan materi terkunci.
- Ekspor fallback menghasilkan `Overview` dan 17 sheet RPP walaupun template Excel lokal tidak tersedia.

## Data sumber dan file lokal

Repositori sengaja **tidak menyimpan PDF atau XLSX**. Data seed terstruktur yang dibutuhkan aplikasi tersedia pada `database/data/curriculum.json`, sehingga clone baru tidak membutuhkan PDF/Excel untuk migrasi dan seed.

Jika ingin membuka PDF dari halaman kurikulum, tempatkan aset lokal mengikuti struktur path yang tercatat di JSON:

- `1. GGB/` untuk 17 PDF GGB.
- `2. SILABUS/` untuk 17 PDF silabus.
- root proyek untuk template `3. RPP 26_27 daerah TangKot.xlsx` (opsional).
- `output/` untuk workbook hasil ekspor lokal.

Saat PDF tidak tersedia, aplikasi menampilkan **PDF hanya tersedia lokal**, bukan tautan rusak. Semua `*.pdf`, `*.xlsx`, hasil build, dependency, `.env`, dan hasil ekspor diabaikan Git.

## Instalasi XAMPP / localhost

Prasyarat: PHP 8.2+, Composer, Node.js 22+, npm, serta MySQL/MariaDB XAMPP.

```powershell
Copy-Item .env.example .env
composer install
php artisan key:generate
npm.cmd install
npm.cmd run build
php artisan migrate:fresh --seed
```

Atur koneksi database pada `.env`, lalu buka `http://localhost/rpp-ppg/public`.

Akun Admin awal mengikuti nilai berikut pada `.env`:

```dotenv
ADMIN_NAME="Administrator RPP"
ADMIN_EMAIL=admin@rpp.local
ADMIN_PASSWORD=ubah-dengan-password-yang-kuat
```

Tidak ada registrasi publik. Ganti password contoh sebelum seed; seed production ditolak jika password bawaan belum diubah.

## Editor tabel

Pilih **Master Kurikulum → Edit Tabel** pada salah satu jenjang. Tab yang tersedia:

- GGB: aspek, subaspek, materi, target, dan urutan.
- Silabus: kategori, materi, penjabaran, alokasi, pertemuan, referensi, penilaian, duplikat, dan urutan.
- Relasi: pasangan GGB–silabus, status, dan catatan.
- RPP Mingguan: minggu efektif, aspek, isi, posisi, dan kunci.

Kode stabil, source key, dokumen, checksum, dan halaman sumber tidak dapat diedit. Sel berubah menjadi draf lokal sampai Admin memberi alasan dan memilih **Simpan semua**. Konflik versi membatalkan seluruh batch, sehingga perubahan dari tab lain tidak ditimpa diam-diam.

Pintasan grid desktop: tombol panah, Enter/F2, Tab, Escape, `Ctrl+S`/`Cmd+S`, copy/paste TSV dari Excel, pilihan banyak baris, dan **Isi ke bawah**. Formula, merged-cell, dan drag-fill belum didukung. Di ponsel, setiap baris tersedia sebagai editor kartu ringkas.

## Ekspor

```powershell
php artisan rpp:export "E:\xampp\htdocs\rpp-ppg\output\RPP_26_27_TangKot_Terverifikasi.xlsx"
```

Jika template XLSX lokal ditemukan, exporter membacanya; jika tidak, exporter membuat workbook baru. File asli tidak pernah ditimpa secara otomatis.

## Pengujian

```powershell
composer validate --strict
php artisan test
npm.cmd run build
```

GitHub Actions menjalankan Composer pada PHP 8.2, build Vite/Node 22, test SQLite, dan pemeriksaan bahwa tidak ada PDF/XLSX yang terlacak.

Dokumentasi audit tambahan tersedia pada `docs/AUDIT_DATA_SUMBER.md` dan `docs/LAPORAN_IMPLEMENTASI.md`.
