# Laporan Implementasi Sistem RPP

## Ruang lingkup selesai

- 17 jenjang dan 34 dokumen sumber diproses.
- 5.806 baris bermakna GGB disimpan dengan urutan, hierarki, dokumen, dan halaman.
- 1.224 butir silabus disimpan dengan kategori, penjabaran, alokasi, referensi, penilaian, urutan, dokumen, dan halaman.
- Empat jenjang Pra Nikah tersedia pada database, Dashboard, Overview, planner, dan ekspor.
- Workbook asli `3. RPP 26_27 daerah TangKot.xlsx` tetap dipertahankan.

## Perbaikan Overview

Overview lama memakai kode seperti `a1`, `b4`, dan `c17-20` tanpa legenda, tanpa halaman sumber, dan tanpa status penurunan ke silabus/RPP. Overview baru memakai nama lengkap serta ID terbaca, misalnya `8-SMP / FAQIH / 001`, dan menambahkan status relasi, alokasi, minggu RPP, duplikasi, serta sumber/halaman.

Workbook hasil memiliki satu sheet Overview dan tepat 17 sheet RPP: PAUD, kelas 1–6 SD, kelas 7–9 SMP, kelas 10–12 SMA, serta Pra Nikah 1–4. Nilai materi/rentang ditulis eksplisit sebagai teks agar tidak berubah menjadi tanggal Excel.

## Aturan penyusunan

- Hanya minggu bertipe Minggu Efektif yang menerima materi.
- Materi berstatus Duplikat tidak digandakan ke RPP.
- Materi tanpa dasar alokasi diberi status Perlu Alokasi dan tidak dijadwalkan otomatis.
- Penyusunan ulang mempertahankan penempatan yang dikunci Admin.
- Audit awal GGB–silabus menggunakan pencocokan teks deterministik. Status Sebagian dan Perlu Verifikasi sengaja tidak dianggap final dan harus ditinjau Admin melalui kedua halaman sumber.

## Angka seed saat serah terima

- 17 jenjang
- 34 dokumen
- 5.806 item GGB
- 1.224 item silabus
- 395 baris silabus berulang yang ditandai Duplikat
- 41 item kanonik berstatus Perlu Alokasi
- 788 penempatan otomatis awal

## Pemeriksaan selesai

- Migrasi dan seed MariaDB berhasil.
- Empat pengujian dengan 28.143 assertion lulus.
- Build Vite berhasil dan audit npm melaporkan 0 kerentanan.
- Workbook dibuka ulang secara programatis: 18 sheet total, filter/freeze aktif, dan tidak ada nilai materi RPP yang berubah menjadi objek tanggal.
- Smoke test lokal: `/dashboard` mengarahkan tamu ke login, `/login` dan `/up` merespons HTTP 200.
