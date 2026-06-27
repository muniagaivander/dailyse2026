# Daily SE 2026

Daily SE 2026 adalah aplikasi web PHP native untuk memantau progress harian kegiatan SE 2026 sampai level SubSLS. Aplikasi ini dirancang ringan untuk Laragon/Apache + MySQL/MariaDB, tanpa framework besar, dan memakai AdminLTE untuk tampilan.

Dokumen ini menjelaskan cara setup, role user, menu, alur data, template, dashboard publik, dan catatan deploy.

## Ringkasan

Aplikasi menyimpan dua jenis data progress:

- `daily_status`: histori harian per SubSLS.
- `subsls_status`: posisi terbaru per SubSLS untuk dashboard cepat.

Status progress yang dipakai:

```text
Open
Draft
Submit
Reject
Pending
Approved
```

Kolom database terkait:

```text
open_count
draft_count
submitted_by_pencacah
rejected_by_pengawas
pending_count
approved_by_pengawas
```

`target` dihitung dari:

```text
open + draft + submit + reject + pending + approved
```

Istilah utama dashboard:

```text
Progress Pendataan = Submit + Reject + Pending + Approved
```

Selain progress status, aplikasi juga menyimpan status penyelesaian SubSLS:

```text
Belum Selesai
Selesai
```

Status selesai disimpan di tabel `subsls_completion_status`.

## Teknologi

- PHP native.
- MySQL/MariaDB.
- AdminLTE 3.
- Chart.js.
- Chart.js datalabels.
- File `.xlsx` dibuat/dibaca langsung dari PHP.
- Dashboard publik menggunakan file cache JSON.

## Struktur File Penting

```text
bootstrap.php                 Fungsi umum, koneksi DB, helper user, helper status.
config.php                    Konfigurasi dari .env.
layout.php                    Layout utama, sidebar, header, modal password, popup info.
login.php                     Login.
index.php                     Dashboard login.
input.php                     Input harian.
edit.php                      Edit harian.
progress_area.php             Progress by daerah.
progress.php                  Progress by pengawas/pencacah.
status_view.php               Status terupdate.
status_selesai.php            Status selesai SubSLS.
completion_template.php       Template status selesai SubSLS.
daily_template.php            Upload/download template harian admin.
pml_daily_template.php        Download template harian PML/PCL.
assignment.php                Ganti petugas manual.
assignment_template.php       Template ganti petugas.
petugas.php                   Daftar petugas.
rekap_petugas.php             Rekap petugas.
weekly_report.php             Generate weekly report.
snapshot.php                  Isi snapshot tanggal di daily_status.
export_daily.php              Export daily_status per tanggal.
public_dashboard.php          Dashboard publik.
public_dashboard_update.php   Generator cache dashboard publik.
backup_database.php           Download backup SQL.
import.php                    Import master wilayah.
import_cli.php                Import master via CLI.
database.sql                  Struktur database awal.
mobile_update.php             Edit konten popup informasi login.
mobile_update_content.json    Konten popup informasi login.
```

## Setup Cepat

1. Buat database.

```sql
CREATE DATABASE dailyse2026 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

2. Import struktur.

```text
database.sql
```

3. Buat `.env` dari `.env.example`.

Contoh Laragon:

```env
DB_HOST=127.0.0.1
DB_NAME=dailyse2026
DB_USER=root
DB_PASS=
APP_NAME=Daily SE 2026
APP_TIMEZONE=Asia/Makassar
```

4. Buka aplikasi.

```text
http://localhost/dailyse2026
```

5. Login superadmin.

```text
Username: 6400@bps.go.id
```

Password tidak ditulis di README. Gunakan password internal atau reset dari database/admin internal jika diperlukan.

6. Import master wilayah dari menu `Import Master`.

Header CSV master yang dibaca:

```text
kdprov,kdkab,kdkec,kddesa,kdsls,kdsubsls,PENGAWAS,PENCACAH,idsubsls_25_2,nmprov,nmkab,nmkec,nmdesa,nmsls,nmsubsls,idsubls
```

Import master mengisi:

- `master_prov`
- `master_kab`
- `master_kec`
- `master_desa`
- `master_sls`
- `master_subsls`
- user admin kabupaten
- user viewer kabupaten
- user pengawas
- user pencacah
- `subsls_status` awal

## Role User

### Superadmin

Akun provinsi utama.

```text
6400@bps.go.id
```

Akses:

- Dashboard semua kabupaten.
- Input harian untuk pengawas tertentu.
- Edit harian.
- Progress by daerah, pengawas, dan pencacah.
- Status terupdate.
- Rekap petugas.
- Status selesai SubSLS.
- Weekly report.
- Daftar petugas.
- Ganti petugas manual dan template.
- Export data daily.
- Isi snapshot tanggal.
- Dashboard publik.
- Edit popup login.
- Ganti password user.
- Backup database.
- Import master.

### Admin Kabupaten

Akun admin kabupaten memakai kode kabupaten.

```text
6401@bps.go.id
6402@bps.go.id
...
```

Akses:

- Dashboard kabupaten.
- Input harian di kabupatennya.
- Edit harian di kabupatennya.
- Progress by daerah, pengawas, dan pencacah.
- Status terupdate.
- Rekap petugas.
- Status selesai SubSLS.
- Weekly report.
- Daftar petugas.
- Download template ganti petugas untuk dikirim ke admin provinsi.
- Upload harian by template.
- Upload status selesai by template.

### Pengawas/PML

User dibuat dari email pengawas pada master SubSLS.

Akses:

- Dashboard wilayahnya.
- Edit harian.
- Progress by pencacah di bawah pengawas tersebut.
- Status selesai SubSLS.
- Data SubSLS.
- Download template harian hari ini.
- Download template status selesai SubSLS.

Catatan:

- Menu input harian PML/PCL saat ini disembunyikan/ditutup sesuai keputusan operasional terakhir.
- PML tidak upload template langsung. Template diisi lalu dikirim ke Tim SPBE wilayah kerja.

### Pencacah/PCL

User dibuat dari email pencacah pada master SubSLS.

Akses:

- Dashboard wilayahnya.
- Progress by pencacah untuk wilayahnya sendiri.
- Data SubSLS.
- Download template harian hari ini jika menu terkait tersedia.

PCL tidak melakukan upload langsung.

### Viewer Provinsi

```text
viewer6400@bps.go.id
```

Akses read-only:

- Dashboard provinsi.
- Progress by daerah.
- Progress by pengawas.
- Progress by pencacah.
- Status terupdate.
- Daftar petugas.
- Rekap petugas.

### Viewer Kabupaten

```text
viewer6401@bps.go.id
viewer6402@bps.go.id
...
```

Akses read-only untuk kabupaten masing-masing:

- Dashboard kabupaten.
- Progress by daerah.
- Progress by pengawas.
- Progress by pencacah.
- Status terupdate.
- Daftar petugas.
- Rekap petugas.

## Urutan Sidebar

Urutan menu utama mengikuti kebutuhan operasional:

```text
Dashboard
Input Harian
Edit Harian
Progress By Daerah
Progress By Pengawas
Progress By Pencacah
Status Terupdate
Rekap Petugas
Status Selesai SubSLS
Weekly Report
Daftar Petugas
Ganti Petugas
Export Data Daily
Isi Snapshot Tanggal
Dashboard Publik
Edit Pop-up Login
Ganti Password User
Backup Database
Import Master
Data SubSLS
```

Menu yang muncul tergantung role.

## Dashboard Login

Dashboard login memiliki tab:

1. Progress Pendataan.
2. Progress By Status.
3. Progress Selesai SubSLS.
4. Performa Pengawas.
5. Performa Pencacah.

Tab performa hanya untuk superadmin, admin kabupaten, viewer provinsi, dan viewer kabupaten.

### Progress Pendataan

Menggunakan rumus:

```text
(submit + reject + pending + approved) / target * 100
```

Tampilan:

- Card status: Target, Open, Draft, Submit, Reject, Pending, Approved, Progress Pendataan, SubSLS Selesai, Total SubSLS.
- Bar chart Progress Pendataan sesuai filter.
- Label persen tampil di dalam bar.
- Tabel ringkasan sesuai filter.
- Export CSV dan Excel.

Range warna:

- Merah: di bawah 20%.
- Orange: 20% sampai kurang dari 40%.
- Biru: 40% sampai kurang dari 75%.
- Hijau: 75% sampai 100%.

### Progress By Status

Menampilkan stacked bar chart status:

```text
Open, Draft, Submit, Reject, Pending, Approved
```

Setiap bar dihitung sebagai persen terhadap target. Idealnya total stacked bar adalah 100%.

### Progress Selesai SubSLS

Menggunakan rumus:

```text
SubSLS Selesai / Total SubSLS * 100
```

### Performa Pengawas dan Pencacah

Menampilkan:

- Peringkat terbaik.
- Daftar perlu perhatian.
- Kode kabupaten.
- Wilayah kerja.
- Progress Pendataan.
- Progress selesai SubSLS.
- Target.
- Total SubSLS.

Untuk superadmin dan viewer provinsi, tersedia tab `6400` untuk Kalimantan Timur dan tab kabupaten.

Kriteria perlu perhatian mengikuti target tanggal:

- Sebelum/sampai 15 Juli: selesai SubSLS di bawah 40%.
- Sebelum/sampai 30 Juli: selesai SubSLS di bawah 65%.
- Sebelum/sampai 15 Agustus: selesai SubSLS di bawah 85%.

Tabel perlu perhatian dapat diekspor CSV dan Excel.

## Dashboard Publik

Dashboard publik adalah halaman read-only tanpa login.

URL:

```text
/6400
/6401
/6402
/6403
/6404
/6405
/6409
/6411
/6471
/6472
/6474
```

Contoh:

```text
https://dailyse2026.dataetam.com/6400
https://dailyse2026.dataetam.com/6401
```

Jika rewrite belum aktif, akses langsung:

```text
public_dashboard.php?code=6400
public_dashboard.php?code=6401
```

Dashboard publik membaca file:

```text
cache/public_dashboard.json
```

File ini dibuat dari menu superadmin `Dashboard Publik`. Halaman publik tidak query database langsung saat pengunjung membuka halaman, sehingga lebih aman dan ringan.

Isi dashboard publik:

- Header kode wilayah.
- Update terakhir cache.
- Card status.
- Progress Pendataan bar chart.
- Pergerakan harian Progress Pendataan line chart.
- 10 Pengawas Terbaik.
- 10 Pencacah Terbaik.
- Progress By Status.
- Progress Selesai SubSLS.
- Tabel ringkasan.

Rule tampilan:

- `/6400`: agregasi per kabupaten.
- `/6401` dan kabupaten lain: agregasi per kecamatan.

File terkait:

```text
public_dashboard.php
public_dashboard_update.php
.htaccess
```

## Input Harian

Input harian menyimpan data progress untuk tanggal berjalan.

Akses utama:

- Superadmin.
- Admin kabupaten.

PML/PCL saat ini tidak ditampilkan menu input harian sesuai keputusan operasional terakhir.

Alur superadmin/admin kabupaten:

1. Pilih wilayah.
2. Pilih pengawas.
3. Klik `Tampilkan Form Input`.
4. Isi status per SubSLS.
5. Klik `Kirim Data Hari Ini`.
6. Sistem menampilkan konfirmasi modern.
7. Data tersimpan ke `daily_status`, `submit_locks`, dan `subsls_status`.

Jika tanggal hari ini sudah ada input/upload untuk pengawas tersebut, sistem memberi info bahwa tanggal hari ini sudah diupload/diinput.

## Edit Harian

Menu untuk memperbaiki data harian yang sudah pernah masuk.

Akses:

- Superadmin.
- Admin kabupaten.
- Pengawas.

Alur:

1. Pilih wilayah/pengawas.
2. Klik `Tampilkan Tanggal`.
3. Pilih tanggal yang tersedia.
4. Klik `Tampilkan Form Edit`.
5. Edit status.
6. Klik `Edit Data Tanggal Ini`.

Untuk superadmin, filter utama dibuat sederhana:

```text
Kabupaten tertentu -> Pengawas tertentu -> Tampilkan Tanggal
```

Form edit tidak reaktif otomatis. Data form berubah hanya setelah tombol `Tampilkan Form Edit` ditekan.

Menu ini juga menyediakan export data tanggal yang sedang diedit:

- Export XLSX.
- Export CSV.

Nama file export memakai prefix `data_harian`.

## Progress By Daerah

Menampilkan line chart Progress Pendataan per hari berdasarkan wilayah.

Filter:

```text
Bulan -> Kabupaten -> Kecamatan -> Desa
```

Untuk superadmin/viewer provinsi:

- Semua kabupaten: line per kabupaten.
- Kabupaten tertentu + semua kecamatan: line per kecamatan.
- Kecamatan tertentu + semua desa: line per desa.
- Desa tertentu: line per SubSLS.

Untuk admin/viewer kabupaten, filter mulai dari kecamatan.

Chart tidak reaktif otomatis. Chart berubah setelah tombol filter ditekan.

Skala Y otomatis:

- Maksimum 10 jika nilai tertinggi <= 10.
- Maksimum 25 jika nilai tertinggi <= 25.
- Maksimum 50 jika nilai tertinggi <= 50.
- Maksimum 75 jika nilai tertinggi <= 75.
- Maksimum 100 jika nilai tertinggi > 75.

## Progress By Pengawas dan Pencacah

Menu ini menampilkan:

1. Progress by Pendataan.
2. Progress by Status.

Urutan chart:

```text
Progress by Pendataan
Progress by Status
```

Filter progress pengawas:

- Superadmin/viewer provinsi: Kabupaten -> Pengawas -> Kecamatan -> Desa -> SubSLS.
- Admin/viewer kabupaten: Pengawas -> Kecamatan -> Desa -> SubSLS.

Filter progress pencacah:

- Superadmin/viewer provinsi: Kabupaten -> Pencacah -> Kecamatan -> Desa -> SubSLS.
- Admin/viewer kabupaten: Pencacah -> Kecamatan -> Desa -> SubSLS.
- Pengawas: Pencacah, bisa semua pencacah atau pencacah tertentu.
- Pencacah: wilayah sendiri.

Dropdown pengawas/pencacah memakai label nama dan email jika nama tersedia.

## Status Terupdate

Menu untuk melihat posisi terbaru per SubSLS.

Akses:

- Superadmin.
- Admin kabupaten.
- Viewer provinsi.
- Viewer kabupaten.

Mode tampilan:

- Table View.
- Card View.

Jika superadmin/viewer provinsi memilih semua kabupaten dan mode Card View, sistem memberi pesan bahwa data terlalu banyak dan menyarankan Table View.

Table View menampilkan:

- Kode SubSLS 16 digit.
- Kabupaten/kecamatan/desa.
- SLS.
- SubSLS.
- Pengawas.
- Pencacah.
- Target.
- Open.
- Draft.
- Submit.
- Reject.
- Pending.
- Approved.
- Progress.
- Total assignment.
- Updated by.
- Status selesai.

Card View menampilkan ringkasan PML dan PCL:

- Nama petugas.
- Jumlah PCL/SubSLS.
- Status counts.
- Progress Pendataan.
- Total assignment.
- Wilayah kerja desa.

Tersedia:

- Sort Progress Pendataan ascending/descending.
- Search nama PML.
- Search nama PCL.
- Export CSV.
- Export Excel.

Jika search PML, card PCL yang terkait ikut ditampilkan. Jika search PCL, card PML terkait ikut ditampilkan.

## Rekap Petugas

Menu untuk melihat rekap per petugas, bukan per SubSLS.

Akses:

- Superadmin.
- Admin kabupaten.
- Viewer provinsi.
- Viewer kabupaten.

Filter:

```text
PML/PCL -> Kabupaten/Kecamatan/Desa
```

Kolom utama:

- Nama petugas.
- Email petugas.
- Kabupaten.
- Nama PML untuk mode PCL.
- Wilayah Kerja Desa.
- Jumlah SubSLS.
- Target.
- Open.
- Draft.
- Submit.
- Reject.
- Pending.
- Approved.

Tabel dipaginasi 100 row per halaman dan dapat diekspor:

- CSV.
- Excel.

Urutan data superadmin mengikuti kode kabupaten.

## Daftar Petugas

Menu untuk melihat daftar SubSLS dan petugas.

Akses:

- Superadmin.
- Admin kabupaten.
- Viewer provinsi.
- Viewer kabupaten.

Fitur:

- Filter wilayah.
- Tabel 100 row per halaman.
- Info jumlah pengawas dan pencacah unik sesuai filter.
- Kode SubSLS 16 digit.
- SubSLS ditampilkan sebagai kode `00`, `01`, `02`, dan seterusnya.

Admin kabupaten memiliki tombol download template ganti petugas untuk dikirim ke admin provinsi.

## Status Selesai SubSLS

Menu untuk mengubah status selesai SubSLS.

Akses:

- Superadmin.
- Admin kabupaten.
- Pengawas.

Validasi penting:

SubSLS hanya boleh diubah menjadi `Selesai` jika:

```text
Approved = Target
```

Jika belum sama, sistem menolak dengan pesan:

```text
Masih ada pekerjaan belum selesai (Approve kurang/tidak sama dengan nilai target)
```

Template status selesai juga memakai validasi yang sama. Jika ada banyak row salah, sistem mengumpulkan daftar error per row agar user tahu semua baris yang perlu diperbaiki.

## Template

### Upload Harian Template

Untuk superadmin dan admin kabupaten.

Header utama:

```text
tanggal
provinsi
kabupaten
kecamatan
desa
sls
nama_sls
subsls
subsls_id
pengawas_nama
pengawas_email
pencacah_nama
pencacah_email
open
draft
submit
reject
pending
approved
```

Validasi:

- File harus `.xlsx`.
- Tanggal tidak boleh setelah hari upload.
- SubSLS harus ada di master.
- Admin kabupaten hanya boleh upload wilayah kabupatennya.
- Jika row valid sebagian, sistem tetap melengkapi SubSLS lain pada pengawas/tanggal itu memakai kondisi terakhir agar menu Edit Harian lengkap.

### Template Harian PML/PCL

PML/PCL download template hari ini. Mereka tidak upload langsung.

Pesan operasional:

```text
Isikan template dan kirim ke Tim SPBE BPS wilayah kerja.
```

### Ganti Petugas Template

Untuk superadmin.

Template dapat berisi sebagian SubSLS saja. Upload akan:

- Update `master_subsls`.
- Update pengawas/pencacah di `daily_status` agar konsisten.
- Membuat user baru jika email belum ada.
- Update nama user jika nama tersedia.
- Sinkron status aktif/tidak aktif user pengawas/pencacah.

### Status Selesai Template

Untuk superadmin/admin kabupaten upload. Untuk pengawas download-only.

Kolom tambahan:

```text
status_selesai
```

Nilai valid:

```text
Belum Selesai
Selesai
```

## Ganti Petugas

Menu superadmin untuk update petugas satu desa sekaligus.

Alur:

1. Pilih kabupaten.
2. Pilih kecamatan.
3. Pilih desa.
4. Klik filter.
5. Ubah nama/email pengawas dan pencacah pada row SubSLS.
6. Klik `Update Master Petugas`.

Jika petugas sama, row dilewati. Jika beda, sistem update master dan histori terkait.

## Weekly Report

Menu untuk superadmin dan admin kabupaten.

Alur:

1. Buka menu Weekly Report.
2. Sistem menampilkan periode 7 hari terakhir dari tanggal acuan.
3. Klik `Generate Weekly Report`.
4. Sistem membuat file HTML report yang dapat langsung didownload/dibuka.

Isi report:

- Card status sampai tanggal akhir periode.
- Info rumus Progress Pendataan.
- 5 wilayah tertinggi.
- 5 wilayah perlu perhatian.
- Untuk admin kabupaten, juga tabel 5 pengawas dan 5 pencacah tertinggi/perlu perhatian.
- Line chart progress harian.
- Tabel ringkasan.
- Delta dibanding minggu sebelumnya.
- Analisis singkat proyeksi penyelesaian.

## Export Data Daily

Menu superadmin untuk export `daily_status` pada satu tanggal tertentu.

Format:

- CSV.
- XLSX.

Filter wilayah dan petugas tersedia sebelum export.

## Isi Snapshot Tanggal

Menu superadmin untuk melengkapi tanggal yang belum memiliki seluruh SubSLS di `daily_status`.

Tujuan:

Mencegah line chart turun-naik karena pada tanggal tertentu hanya sebagian SubSLS yang masuk.

Alur:

1. Pilih tanggal yang sudah ada di `daily_status`.
2. Klik filter.
3. Sistem menampilkan ringkasan row yang sudah ada dan yang belum ada.
4. Klik `Isi Daily Snapshot`.
5. SubSLS yang kosong di tanggal tersebut diisi memakai data terbaru sebelum tanggal itu.

Row snapshot diberi `updated_by` dengan prefix:

```text
system_snapshot:
```

## Backup Database

Menu superadmin untuk download backup SQL database.

Backup ini berguna untuk data produksi, sedangkan GitHub menyimpan kode aplikasi.

## Edit Pop-up Login

Superadmin dapat mengubah isi popup informasi aplikasi yang muncul saat user login.

Konten disimpan di:

```text
mobile_update_content.json
```

Jika server menolak simpan, pastikan file/folder aplikasi writable oleh web server.

## Ganti Password

Semua user dapat mengganti password sendiri dari ikon gear kanan atas.

Superadmin memiliki menu `Ganti Password User` untuk reset password:

- Admin.
- Pengawas.
- Pencacah.

Tab pengawas dan pencacah memiliki search bar.

## Data Model Singkat

### Master Wilayah

Struktur:

```text
master_prov -> master_kab -> master_kec -> master_desa -> master_sls -> master_subsls
```

`master_subsls` adalah pusat relasi SubSLS ke petugas:

```text
pengawas_email
pencacah_email
```

### Users

Role:

```text
superadmin
admin_kab
pengawas
pencacah
viewer_prov
viewer_kab
```

Kolom `active` menandai apakah pengawas/pencacah masih punya SubSLS di master terbaru.

### Daily Status

Kunci unik:

```text
tanggal + subsls_id
```

Menyimpan histori harian.

### SubSLS Status

`subsls_status` menyimpan posisi terbaru. Dashboard login membaca tabel ini agar cepat.

### Submit Locks

`submit_locks` menandai pengawas sudah submit pada tanggal tertentu.

### Public Dashboard Cache

`cache/public_dashboard.json` menyimpan snapshot publik agar halaman publik tidak query database langsung.

## Deploy

### Laragon

Letakkan folder di:

```text
D:\laragon\www\dailyse2026
```

Buka:

```text
http://localhost/dailyse2026
```

### Server

1. Upload file aplikasi.
2. Pastikan `.env` server sesuai.
3. Pastikan database sudah ada.
4. Import `database.sql` untuk setup awal.
5. Pastikan folder `cache` dan folder report bisa ditulis jika memakai dashboard publik/weekly report.
6. Aktifkan rewrite jika ingin URL publik `/6400`, `/6401`, dan seterusnya.

### File Konfigurasi

`.env` tidak disimpan ke Git.

Contoh `.env.example` disediakan untuk panduan.

## Catatan Operasional

- Jika perubahan hanya satu file PHP, deploy cukup replace file tersebut.
- Jika perubahan melibatkan struktur database, jalankan SQL perubahan terlebih dahulu.
- Jika dashboard publik tidak berubah setelah deploy, buka menu `Dashboard Publik` lalu klik update.
- Jika cache publik kosong, halaman publik akan meminta superadmin membuat snapshot.
- Jika chart harian turun-naik karena ada tanggal tidak lengkap, gunakan menu `Isi Snapshot Tanggal`.
- Jika user PML/PCL tidak aktif setelah ganti petugas, cek apakah email tersebut masih terkait SubSLS pada master.

## Catatan Versi

Project pernah ditandai secara operasional sampai v1.9. Snapshot versi dipakai untuk menandai state kode dan struktur SQL, bukan backup data produksi. Untuk backup data, gunakan menu `Backup Database`.
