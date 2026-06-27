# PRD Daily SE 2026

## 1. Ringkasan Produk

Daily SE 2026 adalah aplikasi monitoring progress harian kegiatan Sensus Ekonomi 2026 di Provinsi Kalimantan Timur. Aplikasi memusatkan data progress pada level SubSLS, memetakan setiap SubSLS ke pengawas/PML dan pencacah/PCL, lalu menyajikan dashboard operasional untuk superadmin, admin kabupaten, viewer, PML, dan PCL.

Produk ini dibuat untuk kebutuhan cepat, ringan, dan mudah dideploy di hosting PHP/MySQL tanpa framework besar.

## 2. Tujuan

Tujuan utama:

- Memantau progress pendataan harian sampai level SubSLS.
- Menyediakan dashboard provinsi/kabupaten yang mudah dibaca.
- Memisahkan histori harian dan posisi terbaru agar dashboard tetap cepat.
- Memudahkan input/edit data harian oleh admin.
- Menyediakan dashboard publik read-only yang aman dan ringan.
- Menyediakan export/report untuk kebutuhan monitoring pimpinan dan kabupaten.

## 3. Masalah yang Diselesaikan

Sebelum aplikasi:

- Spreadsheet dengan 17.039 SubSLS x banyak hari terlalu berat.
- Monitoring harian sulit dilakukan jika input tersebar.
- Perubahan petugas membuat data master dan histori rawan tidak konsisten.
- Dashboard publik berisiko berat jika langsung query database.
- Pengawas/pencacah lintas wilayah menyulitkan filter sederhana berbasis wilayah saja.

Dengan aplikasi:

- Data harian disimpan terstruktur di MySQL.
- Dashboard mengambil snapshot terbaru.
- Public dashboard membaca cache JSON.
- Ganti petugas mengupdate master dan histori terkait.
- Filter petugas dan wilayah dibuat sesuai role.

## 4. Pengguna dan Role

### Superadmin

Pengelola provinsi. Membutuhkan kontrol penuh atas master, dashboard, input/edit, public dashboard, backup, dan user.

Kebutuhan:

- Melihat seluruh Kalimantan Timur.
- Mengelola semua kabupaten.
- Mengubah petugas.
- Reset password.
- Backup database.
- Generate dashboard publik.
- Generate weekly report provinsi.

### Admin Kabupaten

Pengelola kabupaten.

Kebutuhan:

- Melihat kabupaten sendiri.
- Input/edit data harian wilayahnya.
- Melihat progress pengawas/pencacah.
- Upload template harian/status selesai.
- Generate weekly report kabupaten.
- Download template ganti petugas untuk dikirim ke admin provinsi.

### Pengawas/PML

Koordinator pencacah pada wilayah kerja tertentu.

Kebutuhan:

- Melihat dashboard wilayahnya.
- Melihat progress pencacah di bawahnya.
- Edit data harian jika ada koreksi.
- Melihat data SubSLS.
- Mengubah status selesai SubSLS jika pekerjaan sudah memenuhi syarat.
- Download template untuk dikirim ke Tim SPBE.

### Pencacah/PCL

Petugas pencacah.

Kebutuhan:

- Melihat dashboard wilayah sendiri.
- Melihat progress wilayah sendiri.
- Melihat data SubSLS.

### Viewer Provinsi/Kabupaten

User read-only.

Kebutuhan:

- Melihat dashboard.
- Melihat progress.
- Melihat status terupdate.
- Melihat daftar/rekap petugas.
- Tidak mengubah data.

## 5. Definisi Data dan Istilah

### SubSLS

Unit wilayah kerja terkecil yang dipantau. Setiap SubSLS memiliki:

- ID SubSLS.
- Kode provinsi/kabupaten/kecamatan/desa/SLS/SubSLS.
- Nama wilayah.
- Email pengawas.
- Email pencacah.

### Status Progress

Status numerik:

```text
Open
Draft
Submit
Reject
Pending
Approved
```

### Target

```text
Target = Open + Draft + Submit + Reject + Pending + Approved
```

### Progress Pendataan

```text
Progress Pendataan = Submit + Reject + Pending + Approved
```

Persentase:

```text
Progress Pendataan / Target * 100
```

### Status Selesai SubSLS

Status final:

```text
Belum Selesai
Selesai
```

Rule:

SubSLS hanya dapat menjadi `Selesai` jika:

```text
Approved = Target
```

## 6. Scope Fitur

### In Scope

- Login role-based.
- Dashboard login.
- Dashboard publik cache-based.
- Input harian admin.
- Edit harian.
- Progress by daerah.
- Progress by pengawas.
- Progress by pencacah.
- Status terupdate table/card view.
- Rekap petugas.
- Daftar petugas.
- Status selesai SubSLS.
- Template harian.
- Template ganti petugas.
- Template status selesai.
- Weekly report.
- Export daily.
- Isi snapshot tanggal.
- Backup database.
- Import master.
- Popup informasi login.
- Ganti password mandiri dan reset password oleh superadmin.

### Out of Scope Saat Ini

- Multi kegiatan dalam satu instance aplikasi.
- API eksternal.
- Real-time websocket.
- Mobile app native.
- Hak akses granular per fitur di luar role yang sudah ada.
- Audit log lengkap per field.

## 7. Requirement Fungsional

### FR-01 Login dan Role

Sistem harus mengizinkan user login dengan email dan password.

Sistem harus membatasi menu sesuai role.

Semua user harus dapat mengganti password sendiri.

Superadmin harus dapat reset password user lain.

### FR-02 Import Master

Sistem harus mengimpor master wilayah dari CSV.

Sistem harus membentuk hierarchy:

```text
provinsi -> kabupaten -> kecamatan -> desa -> SLS -> SubSLS
```

Sistem harus membuat user pengawas dan pencacah dari email unik.

Sistem harus mengisi snapshot awal `subsls_status`.

### FR-03 Dashboard Login

Sistem harus menampilkan:

- Card status.
- Progress Pendataan.
- Progress By Status.
- Progress Selesai SubSLS.
- Performa pengawas.
- Performa pencacah.

Dashboard harus mengikuti filter wilayah/petugas sesuai role.

Dashboard harus menampilkan label persen pada bar Progress Pendataan.

Dashboard harus menyediakan export CSV/XLSX untuk data grafik/tabel.

Dashboard harus menampilkan maksimal 10 Performa Sementara dan 10 Performa Mingguan untuk PML maupun PCL.

Performa Sementara menggunakan target internal 15 Agustus 2026 dan menggabungkan Indeks Ketepatan Laju, Konsistensi Harian, serta Momentum 7 Hari.

Performa Mingguan hanya menggunakan periode terakhir yang sudah selesai, dimulai dari minggu 15-21 Juni 2026. Perhitungan menggabungkan Pencapaian Target Mingguan, Konsistensi Harian, dan Kemampuan Mengejar Sisa Target.

Hari tanpa tambahan progress harus bernilai nol. Petugas yang telah selesai sebelum awal minggu tidak boleh masuk ranking mingguan.

Superadmin dan admin kabupaten harus dapat mengekspor seluruh Performa Sementara sesuai jenis petugas pada tab aktif. Cakupan export harus mengikuti wilayah akun.

### FR-04 Dashboard Publik

Sistem harus menyediakan URL publik `/6400` dan `/64xx`.

Dashboard publik tidak boleh membutuhkan login.

Dashboard publik tidak boleh melakukan aksi tulis.

Dashboard publik harus membaca cache JSON.

Superadmin harus dapat generate/update cache dashboard publik.

Dashboard publik provinsi harus agregasi per kabupaten.

Dashboard publik kabupaten harus agregasi per kecamatan.

### FR-05 Input Harian

Admin harus dapat input data harian untuk pengawas tertentu.

Sistem harus menyimpan data ke:

- `daily_status`
- `submit_locks`
- `subsls_status`

Jika pengawas/tanggal sudah pernah diinput/upload, sistem harus menampilkan info dan mencegah submit ulang dari menu input.

### FR-06 Edit Harian

Sistem harus menampilkan tanggal yang benar-benar ada di `daily_status`.

User harus memilih tanggal tertentu sebelum form edit muncul.

Sistem harus update row `daily_status` yang dipilih.

Sistem harus menghitung ulang `subsls_status` dari data terbaru per SubSLS.

Sistem harus menyediakan export CSV/XLSX untuk data edit tanggal tertentu.

### FR-07 Upload Harian Template

Sistem harus menyediakan template XLSX.

Sistem harus memvalidasi:

- Tanggal tidak boleh setelah hari upload.
- SubSLS harus ada.
- Admin kabupaten hanya boleh wilayah kabupatennya.

Jika upload sebagian SubSLS pada pengawas/tanggal, sistem harus melengkapi SubSLS lain memakai kondisi terbaru agar edit harian lengkap.

### FR-08 Status Selesai SubSLS

Sistem harus mengizinkan superadmin, admin kabupaten, dan PML mengubah status selesai sesuai scope.

Sistem harus menolak status `Selesai` jika `Approved != Target`.

Sistem harus memberikan daftar error per row untuk upload template.

### FR-09 Ganti Petugas

Superadmin harus dapat mengganti petugas per desa.

Sistem harus update:

- `master_subsls`
- `daily_status`
- `users`

Sistem harus menandai user pengawas/pencacah aktif jika masih punya SubSLS dan tidak aktif jika tidak punya SubSLS.

### FR-10 Progress

Progress by daerah harus menampilkan line chart Progress Pendataan per hari.

Progress by pengawas/pencacah harus menampilkan:

- Line chart Progress Pendataan.
- Line chart status.

Chart harus berubah setelah tombol filter ditekan, bukan otomatis pada setiap perubahan dropdown.

### FR-11 Status Terupdate

Sistem harus menampilkan status terbaru dalam Table View dan Card View.

Card View harus tersedia jika scope data tidak terlalu besar.

Sistem harus menyediakan search PML/PCL yang saling terkait.

Sistem harus menyediakan export CSV/XLSX.

### FR-12 Rekap Petugas

Sistem harus menampilkan rekap PML/PCL berdasarkan wilayah kerja.

Tabel harus dipaginasi 100 row.

Sistem harus menyediakan export CSV/XLSX.

### FR-12A Rekap Petugas Daily

Sistem harus menampilkan rekap PML/PCL per tanggal untuk periode Juni sampai Agustus 2026.

Setiap tanggal harus memiliki subkolom Count dan Persen. Count dihitung dari Submit, Reject, Pending, dan Approved. Persen dihitung terhadap Target pada tanggal yang sama.

Filter wilayah serta export CSV/XLSX harus tersedia. Halaman bersifat export-only dan query rekap hanya dijalankan ketika pengguna menekan tombol download.

### FR-13 Weekly Report

Sistem harus generate report periode 7 hari.

Report harus berisi:

- Card status.
- Summary Progress Pendataan.
- Top 5 wilayah.
- 5 wilayah perlu perhatian.
- Untuk admin kabupaten, top/perhatian pengawas dan pencacah.
- Chart progress.
- Tabel ringkasan.
- Delta minggu lalu.
- Analisis proyeksi.

### FR-14 Isi Snapshot Tanggal

Sistem harus mengisi SubSLS kosong pada tanggal tertentu memakai data terakhir sebelum tanggal tersebut.

Sistem harus memberi ringkasan sebelum snapshot dijalankan.

### FR-15 Backup Database

Superadmin harus dapat download backup SQL dari aplikasi.

## 8. Requirement Non-Fungsional

### Performa

- Dashboard login membaca `subsls_status`, bukan menghitung seluruh `daily_status` setiap load.
- Dashboard publik membaca cache JSON.
- Tabel besar memakai pagination.
- Upload dan proses panjang menampilkan progress overlay.

### Keamanan

- Role membatasi akses menu dan aksi.
- Dashboard publik read-only dan cache-based.
- Password disimpan dalam hash.
- `.env` tidak masuk Git.
- Backup database hanya untuk superadmin.

### Maintainability

- PHP native dengan file terpisah per fitur.
- Nama tabel dan kolom konsisten.
- Template XLSX punya header eksplisit.
- README dan PRD harus diperbarui saat fitur besar berubah.

### Usability

- Filter bertahap agar user tidak memilih kombinasi yang salah.
- Chart tidak terlalu reaktif untuk menu berat.
- Label chart dan card dibuat jelas.
- Search tersedia pada dropdown/tabel yang berisi banyak user.

## 9. Data Model

Tabel utama:

```text
users
master_prov
master_kab
master_kec
master_desa
master_sls
master_subsls
subsls_status
subsls_completion_status
daily_status
submit_locks
```

Relasi utama:

- `master_subsls.sls_id -> master_sls.id`
- `master_sls.desa_id -> master_desa.id`
- `master_desa.kec_id -> master_kec.id`
- `master_kec.kab_id -> master_kab.id`
- `subsls_status.subsls_id -> master_subsls.id`
- `daily_status.subsls_id -> master_subsls.id`
- `subsls_completion_status.subsls_id -> master_subsls.id`
- `master_subsls.pengawas_email -> users.email`
- `master_subsls.pencacah_email -> users.email`

Catatan:

Email petugas disimpan langsung di beberapa tabel untuk menjaga histori dan memudahkan update massal.

## 10. Alur Data Utama

### Input/Upload Harian

```text
Form/template -> Validasi -> daily_status -> submit_locks -> subsls_status
```

### Edit Harian

```text
Pilih tanggal -> Update daily_status -> Refresh subsls_status dari tanggal terbaru
```

### Dashboard Login

```text
subsls_status + subsls_completion_status + master wilayah -> agregasi dashboard
```

### Dashboard Publik

```text
Superadmin update cache -> cache/public_dashboard.json -> public_dashboard.php
```

### Snapshot Tanggal

```text
Pilih tanggal -> cari SubSLS kosong -> isi dari data terakhir sebelum tanggal -> daily_status
```

## 11. Acceptance Criteria

### Dashboard

- Progress Pendataan memakai submit+reject+pending+approved.
- Card menampilkan `SubSLS Selesai`, bukan hanya `Selesai`.
- Label persen muncul pada bar Progress Pendataan.
- Public dashboard tidak query database langsung saat dibuka.

### Template

- Header template harian berurutan open, draft, submit, reject, pending, approved.
- Upload tanggal masa depan ditolak dengan daftar row error.
- Upload sebagian tetap membuat edit harian lengkap untuk pengawas/tanggal.

### Status Selesai

- Jika Approved kurang dari Target, update ke Selesai ditolak.
- Upload template status selesai menampilkan semua row error yang ditemukan.

### Ganti Petugas

- Update petugas mengubah master dan histori terkait.
- User lama yang tidak punya SubSLS menjadi tidak aktif.
- User baru otomatis dibuat jika belum ada.

### Progress

- Progress by daerah berubah hanya setelah tombol filter.
- Y-axis line chart memakai batas 10/25/50/75/100 sesuai maksimum data.

## 12. Risiko dan Mitigasi

### Data Harian Tidak Lengkap

Risiko:

Line chart turun-naik jika tanggal tertentu hanya diisi sebagian.

Mitigasi:

Menu `Isi Snapshot Tanggal`.

### File Cache Publik Tidak Terbuat

Risiko:

Dashboard publik kosong.

Mitigasi:

Menu `Dashboard Publik` menampilkan status folder cache dan tombol update.

### Upload Besar Lama

Risiko:

User mengira aplikasi freeze.

Mitigasi:

Progress overlay pada upload/proses panjang.

### Petugas Lintas Wilayah

Risiko:

Filter berbasis wilayah saja tidak cukup.

Mitigasi:

Progress pengawas/pencacah memilih petugas dulu, lalu wilayah yang memang dimiliki petugas.

## 13. Future Improvement

Ide pengembangan berikutnya:

- Audit log perubahan per row.
- Job queue untuk upload sangat besar.
- Role permission lebih granular.
- Halaman kesehatan sistem/cache.
- Export PDF dashboard publik.
- Unit test untuk validasi template.
- Migrasi database formal per versi.
