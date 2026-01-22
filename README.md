# SIKI APP (Sistem Informasi Keuangan & Inventori) v0.0.1

Aplikasi berbasis web untuk manajemen stok barang (Inventori) dan pencatatan keuangan sederhana (Arus Kas). Dibangun menggunakan **PHP Native** dan **MySQL** (tanpa framework berat), sehingga ringan dan mudah dimodifikasi.

## Fitur Utama

### 1. Manajemen Inventori (Gudang)
- **Barang Masuk & Keluar:** Input transaksi dengan dukungan Barcode Scanner (USB & Kamera HP).
- **Cetak Surat Jalan:** Otomatis generate surat jalan PDF siap cetak.
- **Manajemen Stok:** Stok berkurang otomatis saat barang keluar dan bertambah saat barang masuk.
- **Label Barcode:** Fitur cetak label barcode produk (ukuran 50x30mm thermal).
- **Multi Gudang:** Dukungan untuk banyak lokasi gudang.

### 2. Manajemen Keuangan
- **Pencatatan Arus Kas:** Input Pemasukan dan Pengeluaran harian.
- **Chart of Accounts (COA):** Kelola akun keuangan (HPP, Operasional, Gaji, dll).
- **Laporan Keuangan:** 
  - Laporan Laba Rugi (Income Statement).
  - Neraca (Balance Sheet).
  - Laporan Perubahan Modal.
  - Laporan Arus Kas.
- **Integrasi Otomatis:** Transaksi barang masuk/keluar otomatis mencatat HPP atau Penjualan jika dipilih.

### 3. Fitur Pendukung
- **Role Management:** Super Admin, Manager, SVP, Admin Gudang, Admin Keuangan.
- **System Logs:** Mencatat setiap aktivitas user (Audit Trail).
- **Backup & Restore:** Fitur backup database .sql langsung dari aplikasi.
- **Kop Surat Dinamis:** Logo dan info perusahaan bisa diatur lewat menu Pengaturan.

---

## Persyaratan Sistem (Requirements)

- Web Server: Apache / Nginx (XAMPP/Laragon recommended for Windows)
- PHP Versi: 7.4 atau lebih baru (Tested on 8.x)
- Database: MySQL / MariaDB
- Browser: Chrome / Edge (Recommended untuk fitur Scanner & Print)

## Instalasi

1. **Extract File:**
   Simpan folder project di dalam folder `htdocs` (XAMPP) atau `www` (Laragon). Contoh: `C:\xampp\htdocs\siki_app`.

2. **Buat Database:**
   - Buka phpMyAdmin (`http://localhost/phpmyadmin`).
   - Buat database baru dengan nama `siki_db`.
   - Import file `database.sql` yang ada di root folder project ke dalam database `siki_db`.

3. **Konfigurasi:**
   - Buka file `config.php`.
   - Sesuaikan setting database jika perlu (default user `root`, password kosong).

4. **Jalankan:**
   - Buka browser dan akses `http://localhost/siki_app`.

## Akun Login Default

| Username | Password | Role |
|----------|----------|------|
| **superadmin** | **admin123** | SUPER ADMIN (Akses Penuh) |
| **manager** | **admin123** | MANAGER |
| **svp** | **admin123** | SVP |
| **gudang** | **admin123** | ADMIN GUDANG |
| **keuangan** | **admin123** | ADMIN KEUANGAN |

> **Catatan:** Segera ganti password setelah login pertama kali melalui menu **Manajemen User** (Hanya Super Admin).

## Struktur Folder

- `/` : Root file (index.php, config.php, dll).
- `/views` : Halaman-halaman antarmuka (modules).
- `/uploads` : Tempat penyimpanan logo perusahaan dan gambar produk.
- `/services` : (Legacy) File sisa template React (Bisa diabaikan jika full PHP).

---

## Deployment (Hosting)

1. Upload semua file ke `public_html`.
2. Buat database MySQL di cPanel hosting.
3. Import `database.sql`.
4. Edit `config.php` sesuaikan dengan user/pass database hosting.
5. Pastikan folder `uploads` memiliki permission write (755 atau 777).

---

**SIKI APP v0.0.1** - Initial Release
