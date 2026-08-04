# Panduan Menyelesaikan RPP PAUD sampai 100%

Panduan ini digunakan Admin untuk menyiapkan RPP PAUD Tahun Ajaran 2026/2027 dari kalender, GGB, Silabus, target Tilawati, sampai ekspor Excel. Sistem menganggap RPP PAUD **lengkap 100%** hanya jika seluruh lima langkah pada panel panduan berstatus **Selesai**.

## Hasil akhir yang harus terlihat

- Kalender Semester 1 dan Semester 2 mempunyai rentang yang valid serta minimal satu minggu efektif.
- Seluruh 130 butir rinci GGB mempunyai semester dan kolom RPP.
- Cakupan GGB tahunan menunjukkan `130/130` dan berstatus tervalidasi.
- Cakupan Silabus Semester 1 dan Semester 2 masing-masing `100%`.
- Tilawati Semester 1 mencapai halaman `1–22`.
- Tilawati Semester 2 mencapai halaman `23–44`.
- RPP Semester 1 dan Semester 2 berstatus **Tervalidasi**.
- Panel **RPP PAUD sampai lengkap 100%** menunjukkan `5/5 langkah selesai`.

## 1. Menjalankan aplikasi lokal

1. Jalankan **Apache** dan **MySQL** dari XAMPP.
2. Buka PowerShell pada folder aplikasi:

   ```powershell
   Set-Location E:\xampp\htdocs\rpp-ppg
   php artisan serve
   ```

3. Buka `http://127.0.0.1:8000`.
4. Masuk menggunakan akun Admin yang dikonfigurasi melalui `ADMIN_EMAIL` dan `ADMIN_PASSWORD` pada `.env`.

> Jangan menjalankan `php artisan migrate:fresh --seed` pada database yang sudah berisi koreksi Admin karena perintah tersebut menghapus data lama. Perintah itu hanya untuk instalasi baru.

## 2. Membuka panduan PAUD

1. Pilih menu **Ekspor Excel**.
2. Pada pilihan **Jenjang**, pilih **PAUD**.
3. Pilih **Semester 1** terlebih dahulu.
4. Temukan panel **RPP PAUD sampai lengkap 100%**.

Panel ini menampilkan lima langkah, status **Selesai** atau **Perlu tindakan**, penyebab yang masih menghambat, dan tombol menuju bagian perbaikannya.

## 3. Memeriksa kalender akademik

1. Pada langkah **Periksa kalender akademik**, pilih **Atur Waktu**.
2. Pastikan tanggal mulai dan akhir Semester 1 serta Semester 2 sudah benar dan tidak tumpang tindih.
3. Pilih **Simpan rentang semester** jika tanggal diubah.
4. Masukkan rentang Libur, Hari Raya, Evaluasi, atau Ujian berikut keterangannya.
5. Jika ada materi yang akan bergeser, periksa ringkasan dampak lalu centang persetujuan pergeseran.
6. Simpan acara kalender.

Kalender dianggap siap ketika kedua semester memiliki rentang valid dan masing-masing mempunyai sedikitnya satu minggu efektif. Kalender tetap valid jika belum ada acara libur, selama hal tersebut memang sesuai keputusan Admin.

## 4. Membagi GGB PAUD ke dua semester

1. Pada langkah **Konfirmasi semester dan kolom GGB**, pilih **Buka Daftar GGB**.
2. Periksa ringkasan **Bagi seimbang Semester 1 dan Semester 2**.
3. Sistem membagi materi general secara berurutan di setiap kolom dengan target awal 65 materi Semester 1 dan 65 materi Semester 2.
4. Untuk data awal, sistem menyarankan **Hafalan do’a-do’a harian** masuk ke kolom **Do'a-do'a Harian**. Periksa saran tersebut lalu centang persetujuan Admin.
5. Isi **Alasan tindakan (wajib)**, misalnya:

   ```text
   Penyusunan awal RPP PAUD 2026/2027
   ```

6. Pilih **Bagi Seimbang Sekarang**.
7. Pastikan panel menyatakan seluruh materi sudah mempunyai semester dan kolom RPP.

Pembagian ini tidak mengubah keputusan semester yang sebelumnya sudah dikonfirmasi manual. Jika ada materi tanpa saran kolom, sistem tidak akan menebak; petakan materi itu melalui **Atur Kolom** terlebih dahulu.

## 5. Memasukkan dan memvalidasi seluruh GGB

1. Tetap pada daftar GGB.
2. Isi kembali **Alasan tindakan**, misalnya `Lengkapi GGB PAUD satu tahun`.
3. Pilih **Lengkapi GGB 1 Tahun**.
4. Tunggu sampai pesan berhasil muncul.
5. Pastikan kartu **Cakupan GGB 1 Tahun** menunjukkan `100%` dan `130/130 butir rinci`.
6. Pilih **Validasi GGB 1 Tahun**.
7. Pastikan status berubah menjadi **Tervalidasi tahunan**.

Materi yang sudah dicakup oleh penempatan Silabus tidak digandakan. Materi tambahan dibuat sebagai `ggb_auto`, tidak dikunci, dan tetap dapat disusun ulang secara aman.

## 6. Memeriksa dan memvalidasi Semester 1

1. Pada langkah **Validasi RPP Semester 1**, pilih **Buka Semester 1**.
2. Pilih **Susun Otomatis** jika ada materi Silabus atau target yang belum ditempatkan.
3. Buka **Atur Target** dan pastikan Tilawati PAUD menggunakan unit `halaman`, nomor awal `1`, dan nomor akhir `22`.
4. Periksa matriks mingguan dan pastikan materi manual atau terkunci tetap benar.
5. Pilih **Validasi Semester 1**.

Jika validasi ditahan, pesan akan menyebutkan penyebabnya, misalnya jumlah Silabus yang belum dijadwalkan atau sisa halaman Tilawati. Perbaiki penyebab tersebut lalu ulangi validasi.

## 7. Memeriksa dan memvalidasi Semester 2

1. Pada langkah **Validasi RPP Semester 2**, pilih **Buka Semester 2**.
2. Pilih **Susun Otomatis** jika diperlukan.
3. Pastikan target Tilawati menggunakan nomor awal `23` dan nomor akhir `44`.
4. Periksa matriks mingguan.
5. Pilih **Validasi Semester 2**.

Setelah kedua semester selesai, target Tilawati tahunan harus menunjukkan `44/44`.

## 8. Memastikan status akhir 100%

Kembali ke panel panduan dan pastikan:

1. **Periksa kalender akademik** — Selesai.
2. **Konfirmasi semester dan kolom GGB** — Selesai.
3. **Lengkapi dan validasi GGB tahunan** — Selesai.
4. **Validasi RPP Semester 1** — Selesai.
5. **Validasi RPP Semester 2** — Selesai.

Indikator keseluruhan kemudian menampilkan **100%**. Angka ini berbeda dari kartu cakupan GGB: indikator keseluruhan memeriksa kalender, GGB, Silabus, Tilawati, dan validasi kedua semester sekaligus.

## 9. Mengunduh Excel

1. Pilih Semester 1 lalu tekan **Unduh Excel semester ini**.
2. Simpan workbook dengan nama seperti `RPP_2026-2027_PAUD_Semester_1.xlsx`.
3. Pilih Semester 2 dan unduh workbook kedua.
4. Periksa sheet **Ringkasan**, **RPP Semester 1/2**, dan **Materi PAUD**.

File Excel hasil ekspor berada di komputer lokal dan tidak dimasukkan ke GitHub.

## Pemecahan masalah

| Pesan atau kondisi | Penyebab | Cara memperbaiki |
|---|---|---|
| Alasan tindakan wajib diisi | Tombol bulk ditekan tanpa alasan | Isi alasan minimal 5 karakter, misalnya `Penyusunan awal RPP PAUD`. |
| Persetujuan saran kolom belum dicentang | Ada materi dengan saran kolom yang belum disetujui | Periksa judul dan kolom saran, lalu centang persetujuan Admin. |
| Materi belum mempunyai saran kolom | Sistem tidak dapat menentukan kolom secara aman | Buka **Atur Kolom**, pilih kolom RPP, simpan revisi, lalu ulangi pembagian. |
| Validasi GGB ditahan | Cakupan tahunan belum 130/130 | Jalankan **Lengkapi GGB 1 Tahun**, lalu periksa daftar materi yang masih belum masuk. |
| Minggu efektif tidak cukup | Rentang libur/evaluasi menutup terlalu banyak minggu | Perpanjang rentang semester atau sesuaikan acara kalender. Sistem tidak membuat tanggal fiktif. |
| Cakupan Silabus belum 100% | Masih ada materi Silabus yang belum dijadwalkan | Buka Planner semester terkait, pilih **Belum dijadwalkan**, lalu susun otomatis atau jadwalkan manual. |
| Target Tilawati belum selesai | Sebagian halaman belum mendapat minggu efektif | Pilih **Susun Otomatis**, periksa target dan jangkar manual, lalu validasi kembali. |
| Konflik versi atau data berubah | Data diedit dari tab lain setelah halaman dibuka | Muat ulang halaman, periksa nilai terbaru, lalu simpan kembali sebagai revisi baru. |
| `validation.required` masih terlihat | Aset/cache lama masih digunakan | Jalankan `php artisan optimize:clear`, lalu tekan `Ctrl+Shift+R` pada browser. |
