# Daily SE 2026

PHP native ringan untuk Laragon + MySQL/MariaDB.

## Setup Cepat

1. Buat database MySQL:

```sql
CREATE DATABASE dailyse2026 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

2. Import `database.sql` ke database `dailyse2026`.

3. Edit `config.php` jika user/password MySQL Laragon kamu berbeda.

Default:

```php
$DB_USER = 'root';
$DB_PASS = '';
```

4. Buka:

```text
http://localhost/dailyse2026
```

5. Login awal:

```text
6400@bps.go.id
password: 162534
```

6. Masuk menu `Import Master`, upload CSV master wilayah dengan header:

```text
kdprov,kdkab,kdkec,kddesa,kdsls,kdsubsls,PENGAWAS,PENCACAH,idsubsls_25_2,nmprov,nmkab,nmkec,nmdesa,nmsls,nmsubsls,idsubls
```

Import akan:

- mengisi master provinsi, kabupaten, kecamatan, desa, SLS, SubSLS
- membuat user admin kabupaten
- membuat user pengawas dari email unik kolom `PENGAWAS`
- membuat status snapshot `subsls_status`

## Role

- `superadmin`: semua kabupaten, import master, ganti petugas
- `admin_kab`: dashboard/progress sesuai kabupaten
- `pengawas`: input dan edit harian wilayah sendiri

## Status

Status yang dipakai:

```text
open,draft,submitted_by_pencacah,approved_by_pengawas,rejected_by_pengawas
```

`target` dihitung otomatis dari 5 status tersebut.

