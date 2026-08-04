# Sistem RPP Global Mingguan

Aplikasi Laravel 12 untuk menjaga alur **GGB → Silabus → RPP global mingguan** pada 17 jenjang. Aplikasi berjalan lokal, menggunakan Livewire, Tailwind CSS, MariaDB/MySQL, dan PhpSpreadsheet.

## Fitur utama

- Dashboard kelengkapan 17 jenjang, 34 RPP semester, dan audit 34 dokumen sumber.
- Master GGB, silabus, relasi, kalender akademik, dan RPP mingguan.
- Editor tabel bergaya Excel: pencarian, filter, 100 baris per halaman, sticky header/ID, navigasi keyboard, paste TSV, Isi ke bawah, dan simpan massal.
- Riwayat revisi per batch dan per baris, optimistic locking, serta pemulihan yang tetap berjejak.
- Planner deterministik hanya pada minggu efektif dan mempertahankan materi terkunci.
- Preview interaktif per jenjang–semester dengan target progres, edit manual berjejak, dan penyusunan otomatis.
- Matriks RPP seperti lembar kerja sekolah: baris mingguan, header GGB bertingkat, fokus karakter bulanan, dan preset kolom yang dapat diatur Admin.
- Ekspor terpilih menghasilkan tiga sheet: `Ringkasan`, `RPP Semester 1/2`, dan `Materi {Kelas}`. Kamus materi memuat dua semester serta terhubung ke sel RPP melalui hyperlink internal.
- Setiap sel minggu efektif menyediakan pemilih materi GGB/Silabus. Materi yang sudah terpasang dapat dipilih kembali sebagai penguatan manual terkunci.
- Cakupan GGB dihitung untuk satu tahun ajaran. Daftar rinci pada `/ekspor?detail=ggb` mendukung konfirmasi semester/kolom, bulk **Lengkapi GGB 1 Tahun**, dan validasi tahunan 100%.
- Kalender berbasis rentang tanggal mendukung Libur, Hari Raya, Evaluasi, dan Ujian untuk semua atau jenjang terpilih. Keterangan dan dampaknya sama pada planner, preview, serta Excel.

## Data sumber dan file lokal

Repositori sengaja **tidak menyimpan PDF atau XLSX**. Data seed terstruktur tersedia pada `database/data/curriculum.json`, sedangkan aturan preset matriks berada di `database/data/rpp_matrix_presets.json`. Clone baru tidak membutuhkan PDF/Excel untuk migrasi dan seed.

Jika ingin membuka PDF dari halaman kurikulum, tempatkan aset lokal mengikuti struktur path yang tercatat di JSON:

- `1. GGB/` untuk 17 PDF GGB.
- `2. SILABUS/` untuk 17 PDF silabus.
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
- RPP Mingguan: semester, minggu efektif, aspek, isi, rentang progres, posisi, dan kunci.

Kode stabil, source key, dokumen, checksum, dan halaman sumber tidak dapat diedit. Sel berubah menjadi draf lokal sampai Admin memberi alasan dan memilih **Simpan semua**. Konflik versi membatalkan seluruh batch, sehingga perubahan dari tab lain tidak ditimpa diam-diam.

Pintasan grid desktop: tombol panah, Enter/F2, Tab, Escape, `Ctrl+S`/`Cmd+S`, copy/paste TSV dari Excel, pilihan banyak baris, dan **Isi ke bawah**. Formula, merged-cell, dan drag-fill belum didukung. Di ponsel, setiap baris tersedia sebagai editor kartu ringkas.

## Ekspor

Buka `/ekspor`, pilih jenjang dan Semester 1/2, lalu gunakan:

- **Susun Otomatis** untuk membagi materi per kolom ke minggu efektif.
- **Atur Kolom** untuk mengubah aspek, subaspek, label, urutan, lebar, serta pemetaan Silabus.
- **Atur Target** untuk membagi progres halaman/ayat secara berurutan.
- **Atur Waktu** untuk menentukan tanggal awal-akhir semester serta rentang libur/evaluasi/ujian.
- Klik sebuah materi untuk mengedit minggu, kolom, isi, progres, dan kunci melalui drawer.

Preview desktop memakai matriks bertingkat; ponsel memakai kartu per minggu. Jumlah minggu mengikuti rentang tanggal semester yang disimpan Admin, lalu dibagi menjadi dua blok triwulan. Rentang kegiatan yang menyentuh sebagian pekan membuat seluruh pekan non-efektif dan keterangannya ikut masuk Excel. Dari terminal:

```powershell
php artisan rpp:export PAUD 1 "E:\xampp\htdocs\rpp-ppg\output\RPP_2026-2027_PAUD_Semester_1.xlsx"
```

Target awal Tilawati PAUD adalah halaman 1–22 pada Semester 1 dan 23–44 pada Semester 2. Admin dapat mengubah target halaman, ayat, surat, bab, atau label khusus melalui preview. Rentang halaman selalu ditulis sebagai teks agar Excel tidak mengubahnya menjadi tanggal.

Angka **Pertemuan** pada Silabus dibaca sebagai intensitas per minggu, bukan durasi minggu. Pola seperti setiap minggu, minggu ke-1/3, atau minggu ke-2/4 dapat diubah melalui editor Silabus. Materi tentatif tetap tersedia untuk penjadwalan manual dan tidak diulang otomatis.

Materi GGB general tidak ditebak semesternya. Admin memilih Semester 1 atau 2 dari daftar Cakupan GGB. Materi yang sudah jelas dapat dimasukkan lebih dahulu; materi ambigu tetap berada pada antrean **Perlu Konfirmasi Admin**.

## Pengujian

```powershell
composer validate --strict
php artisan test
npm.cmd run build
```

GitHub Actions menjalankan Composer pada PHP 8.2, build Vite/Node 22, test SQLite, dan pemeriksaan bahwa tidak ada PDF/XLSX yang terlacak.

Dokumentasi audit tambahan tersedia pada `docs/AUDIT_DATA_SUMBER.md` dan `docs/LAPORAN_IMPLEMENTASI.md`.

Panduan operasional lengkap untuk menyiapkan contoh PAUD sampai kalender, GGB, Silabus, Tilawati, dan kedua semester berstatus 100% tersedia pada [`docs/PANDUAN_RPP_PAUD_100_PERSEN.md`](docs/PANDUAN_RPP_PAUD_100_PERSEN.md).
