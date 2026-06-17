# Daily SE 2026

Aplikasi web PHP native ringan untuk monitoring harian kegiatan SE 2026. Aplikasi ini dibuat untuk berjalan sederhana di Laragon/Apache + MySQL/MariaDB, dengan data master wilayah sampai level SubSLS.

## Ringkasan Aplikasi

Daily SE 2026 dipakai untuk mencatat dan memantau progress harian per SubSLS. Setiap SubSLS punya petugas pengawas dan pencacah. Pengawas atau admin dapat mengisi status harian, sedangkan dashboard membaca posisi terbaru dari tabel status terupdate.

Status utama yang dipakai:

```text
open, submit, reject, pending, approved
```

Di database, nama kolomnya:

```text
open_count
submitted_by_pencacah
rejected_by_pengawas
draft_count
approved_by_pengawas
```

`target` dihitung otomatis dari jumlah:

```text
open + submit + reject + pending + approved
```

Selain 5 status progress di atas, ada juga status selesai SubSLS:

```text
Belum Selesai / Selesai
```

Status selesai ini disimpan di tabel terpisah `subsls_completion_status`.

## Setup Cepat

1. Buat database MySQL/MariaDB:

```sql
CREATE DATABASE dailyse2026 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

2. Import struktur database:

```text
database.sql
```

3. Buat file `.env` dari `.env.example`, lalu sesuaikan koneksi database.

Contoh Laragon default:

```env
DB_HOST=127.0.0.1
DB_NAME=dailyse2026
DB_USER=root
DB_PASS=
APP_NAME="Daily SE 2026"
APP_TIMEZONE=Asia/Makassar
```

4. Buka aplikasi:

```text
http://localhost/dailyse2026
```

5. Akun superadmin awal:

```text
username: 6400@bps.go.id
```

Password tidak ditulis di README publik. Gunakan password yang disepakati internal atau reset langsung dari database/admin internal jika diperlukan.

6. Import master wilayah dari menu `Import Master`.

Header CSV yang dibutuhkan:

```text
kdprov,kdkab,kdkec,kddesa,kdsls,kdsubsls,PENGAWAS,PENCACAH,idsubsls_25_2,nmprov,nmkab,nmkec,nmdesa,nmsls,nmsubsls,idsubls
```

Import master akan mengisi:

- `master_prov`
- `master_kab`
- `master_kec`
- `master_desa`
- `master_sls`
- `master_subsls`
- user admin kabupaten
- user pengawas
- user pencacah
- snapshot awal `subsls_status`

## Role User

### Superadmin

Superadmin adalah pengelola level provinsi. Akun awal:

```text
6400@bps.go.id
```

Yang bisa dilakukan superadmin:

- melihat dashboard semua kabupaten
- melihat progress pengawas dan pencacah
- input harian untuk pengawas tertentu
- edit harian lintas wilayah
- melihat daftar petugas
- melihat status terupdate
- mengubah status selesai SubSLS
- ganti petugas manual
- ganti petugas by template
- reset password user
- import master wilayah

### Admin Kabupaten

Admin kabupaten mengelola satu kabupaten sesuai `kab_id`.

Contoh akun:

```text
6401@bps.go.id
6402@bps.go.id
...
```

Yang bisa dilakukan admin kabupaten:

- melihat dashboard untuk kabupatennya
- melihat progress pengawas dan pencacah di kabupatennya
- input harian untuk pengawas di kabupatennya
- edit harian di kabupatennya
- melihat daftar petugas
- melihat status terupdate
- mengubah status selesai SubSLS
- upload harian by template
- download template ganti petugas untuk dikirim ke admin provinsi

### Pengawas

Pengawas adalah PML. User pengawas dibuat otomatis dari email unik di master wilayah.

Yang bisa dilakukan pengawas:

- melihat dashboard wilayah pengawas
- input harian
- edit harian untuk tanggal yang pernah diinput/diupload
- melihat data SubSLS wilayahnya
- mengubah status selesai SubSLS
- download template harian hari ini
- download template status selesai SubSLS

Catatan:

- Pengawas tidak upload template langsung.
- Template yang diisi pengawas dikirim ke Tim SPBE sesuai wilayah kerja.

### Pencacah

User pencacah dibuat otomatis dari email unik di master wilayah.

Yang bisa dilakukan pencacah:

- melihat dashboard wilayah pencacah
- melihat data SubSLS wilayahnya

Pencacah tidak melakukan input harian langsung di aplikasi.

### Viewer Provinsi

Akun:

```text
viewer6400@bps.go.id
```

Yang bisa dilakukan viewer provinsi:

- melihat dashboard provinsi
- melihat progress pengawas
- melihat progress pencacah

Viewer hanya melihat data, tidak mengubah data.

### Viewer Kabupaten

Contoh akun:

```text
viewer6401@bps.go.id
viewer6402@bps.go.id
...
```

Yang bisa dilakukan viewer kabupaten:

- melihat dashboard kabupatennya
- melihat progress pengawas di kabupatennya
- melihat progress pencacah di kabupatennya

Viewer kabupaten hanya melihat data, tidak mengubah data.

## Menu Utama

### Dashboard

Dashboard adalah tampilan utama progress terbaru. Data dashboard membaca tabel `subsls_status` dan `subsls_completion_status`.

Tab dashboard:

1. `Progress Submit+Approve`
2. `Progress By Status`
3. `Progress Selesai SubSLS`
4. `Performa Pengawas`
5. `Performa Pencacah`

Tab 1 sampai 3 memiliki filter wilayah dan petugas.

Untuk superadmin/viewer provinsi:

```text
Kabupaten -> Kecamatan -> Desa -> Pengawas -> Pencacah
```

Untuk admin kabupaten/viewer kabupaten:

```text
Kecamatan -> Desa -> Pengawas -> Pencacah
```

Untuk pengawas:

```text
Pencacah
```

Untuk pencacah:

```text
langsung wilayah pencacah
```

#### Progress Submit+Approve

Menampilkan persentase:

```text
(submit + approved) / target * 100
```

Warna progress:

- merah: di bawah 20%
- orange: 20% sampai kurang dari 40%
- biru: 40% sampai kurang dari 75%
- hijau: 75% sampai 100%

#### Progress By Status

Menampilkan stacked bar chart dari 5 status:

```text
open, submit, reject, pending, approved
```

Total stacked bar dibuat sebagai persen dari target, idealnya total 100%.

#### Progress Selesai SubSLS

Menampilkan persentase:

```text
SubSLS Selesai / Total SubSLS * 100
```

Warna progress memakai range yang sama dengan Submit+Approve.

#### Performa Pengawas

Hanya untuk superadmin, admin kabupaten, viewer provinsi, dan viewer kabupaten.

Menampilkan:

- 10 pengawas terbaik
- semua pengawas perlu perhatian

Kriteria perlu perhatian berdasarkan tanggal:

- sampai 15 Juli 2026: selesai SubSLS di bawah 40%
- sampai 30 Juli 2026: selesai SubSLS di bawah 65%
- sampai 15 Agustus 2026: selesai SubSLS di bawah 85%

Tabel perlu perhatian bisa di-export CSV dan Excel.

#### Performa Pencacah

Sama seperti performa pengawas, tetapi agregasinya per pencacah.

### Input Harian

Dipakai untuk mengisi progress harian 5 status.

Yang bisa mengakses:

- superadmin
- admin kabupaten
- pengawas

Untuk superadmin/admin kabupaten:

1. pilih wilayah
2. pilih pengawas
3. klik `Tampilkan Form Input`
4. isi status per SubSLS
5. klik `Kirim Data Hari Ini`

Untuk pengawas:

1. masuk menu `Input Harian`
2. tanggal otomatis hari ini
3. klik `Tampilkan Form Input`
4. sistem menampilkan tab per pencacah
5. isi status per SubSLS
6. klik `Kirim Data Hari Ini`

Jika pengawas sudah input/upload pada hari itu, sistem menampilkan pesan bahwa tanggal hari ini sudah diinput/upload.

Sebelum submit, sistem menampilkan popup konfirmasi:

```text
Sudah Lengkap Semua Pencacah?
```

Setelah sukses, data masuk ke:

- `daily_status`
- `submit_locks`
- `subsls_status`

`daily_status` menyimpan histori harian.  
`subsls_status` menyimpan posisi terbaru per SubSLS.

### Edit Harian

Dipakai untuk memperbaiki data harian yang sudah pernah masuk.

Yang bisa mengakses:

- superadmin
- admin kabupaten
- pengawas

Alur:

1. pilih filter wilayah/petugas
2. klik `Tampilkan Tanggal`
3. pilih tanggal yang akan diedit
4. klik `Tampilkan Form Edit`
5. edit nilai status
6. klik `Edit Data Tanggal Ini`

Tanggal harus dipilih dari tanggal yang memang sudah ada di `daily_status`. Tanggal tidak bisa dibuat bebas dari menu ini.

Setelah edit, sistem memperbarui:

- row tanggal tersebut di `daily_status`
- posisi terbaru di `subsls_status`, dihitung ulang dari tanggal terbaru per SubSLS

Catatan penting:

Jika yang diedit adalah tanggal lama, `subsls_status` tetap mengambil data tanggal terbaru. Jadi edit tanggal lama tidak merusak dashboard terbaru.

### Data SubSLS

Menu ini untuk pengawas dan pencacah.

Fungsinya menampilkan data SubSLS wilayah kerja user yang login.

Urutan data berdasarkan `idsubsls`, bukan berdasarkan pencacah.

### Progress Pengawas

Menu ini untuk:

- superadmin
- admin kabupaten
- viewer provinsi
- viewer kabupaten

Fungsinya melihat pergerakan harian pengawas.

Filter:

```text
Kabupaten/Kecamatan/Desa -> Pengawas
```

Chart menampilkan beberapa line status harian:

```text
open, submit, reject, pending, approved
```

### Progress Pencacah

Menu ini mirip Progress Pengawas, tetapi agregasinya per pencacah.

Filter:

```text
Kabupaten/Kecamatan/Desa -> Pencacah
```

Chart menampilkan pergerakan harian 5 status:

```text
open, submit, reject, pending, approved
```

### Daftar Petugas

Menu ini untuk:

- superadmin
- admin kabupaten

Fungsinya melihat daftar SubSLS beserta pengawas dan pencacah.

Kolom `Kode SubSLS` menampilkan kode lengkap 16 digit:

```text
kab_id + kdkec + kddesa + kdsls + kdsubsls
```

Contoh:

```text
6472030003000501
```

Untuk admin kabupaten, tersedia tombol:

```text
Download Template Ganti Petugas
```

Template ini hanya untuk diisi dan dikirim ke admin provinsi. Admin kabupaten tidak upload template ganti petugas langsung dari menu ini.

### Status Terupdate

Menu ini untuk:

- superadmin
- admin kabupaten

Fungsinya melihat posisi terbaru per SubSLS.

Data berasal dari:

- `master_subsls`
- `subsls_status`
- `subsls_completion_status`

Kolom yang ditampilkan:

- Kode SubSLS 16 digit
- Desa
- SLS
- SubSLS
- Pengawas
- Pencacah
- Target
- Open
- Submit
- Reject
- Pending
- Approved
- Last Update
- Updated By
- Status Selesai

Data bisa di-download:

- CSV
- Excel

Export mengikuti filter yang sedang aktif.

### Status Selesai SubSLS

Menu ini untuk:

- superadmin
- admin kabupaten
- pengawas

Fungsinya mengubah status selesai per SubSLS.

Nilai status:

```text
Belum Selesai
Selesai
```

Superadmin harus memilih sampai level desa.  
Admin kabupaten memilih kecamatan dan desa.  
Pengawas memilih pencacah atau semua pencacah di wilayahnya.

Perubahan masuk ke tabel:

```text
subsls_completion_status
```

### Ganti Petugas

Menu ini hanya untuk superadmin.

Fungsinya mengubah pengawas dan pencacah pada satu desa.

Alur:

1. pilih kabupaten
2. pilih kecamatan
3. pilih desa
4. klik filter
5. ubah pengawas/pencacah pada list SubSLS
6. klik `Update Master Petugas`

Saat update, sistem:

- mengubah `master_subsls`
- mengubah histori `daily_status` agar konsisten
- membuat user baru jika email belum ada
- sinkron status aktif/tidak aktif user pengawas dan pencacah

Jika petugas tidak berubah, row tersebut dilewati.

### Ganti Petugas By Template

Ada di dalam menu `Ganti Petugas` sebagai popup/template.

Template berisi beberapa row SubSLS yang ingin diubah. Tidak wajib semua SubSLS diisi.

Kolom kode wilayah memakai kode, bukan nama:

```text
provinsi, kabupaten, kecamatan, desa, sls, nama_sls, subsls
```

Upload template akan memperbarui petugas sesuai row yang ada di template.

### Upload Harian By Template

Untuk superadmin dan admin kabupaten.

Fungsinya upload progress harian dari file Excel.

Template harian berisi:

- tanggal
- provinsi
- kabupaten
- kecamatan
- desa
- sls
- nama_sls
- subsls
- pengawas_email
- pencacah_email
- open
- submit
- reject
- pending
- approved

Validasi:

- tanggal tidak boleh lebih besar dari hari upload
- SubSLS harus ada di master
- admin kabupaten hanya boleh upload wilayah kabupatennya

Jika upload hanya sebagian SubSLS pada satu pengawas/tanggal, sistem tetap melengkapi SubSLS lain milik pengawas tersebut dengan nilai terakhir dari `subsls_status`.

Tujuannya agar ketika pengawas membuka `Edit Harian`, semua SubSLS yang relevan pada tanggal itu tetap muncul.

### Template Harian Pengawas

Pengawas bisa download:

```text
Download Template Excel Hari Ini
```

Template otomatis berisi tanggal hari ini dan wilayah kerja pengawas.

Pengawas tidak bisa upload langsung. Template yang sudah diisi dikirim ke Tim SPBE sesuai wilayah kerja.

### Upload By Template Status SubSLS

Untuk superadmin dan admin kabupaten, upload bisa dilakukan dari menu Status Selesai SubSLS.

Untuk pengawas, behavior-nya download-only seperti template harian.

Template berisi kolom wilayah dan:

```text
status_selesai
```

Nilai yang valid:

```text
Belum Selesai
Selesai
```

### Ganti Password

Semua user yang login dapat mengganti password sendiri dari ikon gear kanan atas.

Form berisi:

- password lama
- password baru
- ulangi password baru

Input password bisa ditampilkan/sembunyikan dengan ikon mata.

### Ganti Password User

Menu ini hanya untuk superadmin.

Fungsinya reset password user lain.

Alur:

1. pilih kabupaten
2. buka tab Admin/Pengawas/Pencacah
3. cari email dengan search bar
4. klik reset password
5. isi password baru dua kali

## Cara Kerja Data

### Master Wilayah

Master wilayah tersusun berjenjang:

```text
master_prov
master_kab
master_kec
master_desa
master_sls
master_subsls
```

`master_subsls` menyimpan:

- `pengawas_email`
- `pencacah_email`

### Daily Status

Tabel `daily_status` menyimpan histori progress harian per SubSLS.

Kunci unik:

```text
tanggal + subsls_id
```

Setiap perubahan menyimpan:

- tanggal
- SubSLS
- kabupaten
- pengawas
- pencacah
- 5 status
- target
- updated_by

### Submit Locks

Tabel `submit_locks` dipakai untuk menandai bahwa pengawas sudah melakukan input harian pada tanggal tertentu.

Ini mencegah pengawas submit ulang lewat menu input harian pada hari yang sama.

Perbaikan setelah submit dilakukan lewat menu:

```text
Edit Harian
```

### SubSLS Status

Tabel `subsls_status` adalah snapshot posisi terbaru per SubSLS.

Dashboard utama membaca tabel ini agar lebih ringan dibanding menghitung seluruh histori harian setiap kali halaman dibuka.

### Status Selesai

Tabel `subsls_completion_status` menyimpan status:

```text
Belum Selesai / Selesai
```

Dashboard `Progress Selesai SubSLS` membaca tabel ini.

### Users

Tabel `users` menyimpan semua user.

Role yang dipakai:

```text
superadmin
admin_kab
pengawas
pencacah
viewer_prov
viewer_kab
```

Kolom `active` dipakai untuk menandai apakah user pengawas/pencacah masih terkait dengan minimal satu SubSLS di master terbaru.

Jika ganti petugas menyebabkan user lama tidak punya SubSLS lagi, user tersebut menjadi tidak aktif.

## Deploy

### Deploy Manual ke Laragon

Letakkan folder project di:

```text
D:\laragon\www\dailyse2026
```

Lalu buka:

```text
http://localhost/dailyse2026
```

### Deploy dari Git

Clone repository:

```bash
git clone https://github.com/muniagaivander/dailyse2026.git
```

Update dari GitHub:

```bash
git pull
```

Setelah pull, pastikan file `.env` server sudah sesuai.

### File yang Tidak Di-upload ke Git

File `.env` tidak di-upload karena berisi konfigurasi lokal/server.

Yang di-upload:

```text
.env.example
```

## Catatan Operasional

- Jika hanya ada perubahan satu file PHP, deploy server bisa cukup replace file itu.
- Jika ada perubahan struktur database, import/alter SQL harus dijalankan juga.
- Untuk perubahan dashboard terakhir, file yang berubah adalah `index.php`.
- Untuk perubahan tampilan Daftar Petugas/Status Terupdate, file yang berubah adalah `petugas.php` dan `status_view.php`.
- Jika upload/template terasa lama, aplikasi menampilkan progress overlay agar user tahu proses sedang berjalan.

## Snapshot Versi

State aplikasi pernah ditandai sebagai:

- versi 1.0
- versi 1.1

Snapshot versi disimpan di luar folder aplikasi utama pada workspace lokal pengembangan. Snapshot tersebut adalah cadangan code dan struktur SQL, bukan cadangan data produksi.
