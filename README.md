# Berkas Dapur AdeMS

Modul berkas untuk [Dapur AdeMS](https://github.com/AdeMS/dapur): file dan folder milik user, seperti penyimpanan
awan sederhana.

- **Berkas Saya**: file dan folder tiap user, dengan kuota (bawaan 1 GB). Folder tiap akun di server memakai kode acak
  akun (adems/dapur-user 2.6), bukan nomornya.
- **Lokasi tambahan (alias)**: folder lain di server, misalnya folder foto di NAS, milik satu akun.
- **Berbagi**: ke akun tertentu atau ke semua anggota satu website, dengan hak *hanya baca* atau *boleh menulis*.
- **Tautan publik**: siapa pun yang memegang tautan bisa melihat tanpa login; bisa diberi masa berlaku. Foto JPEG
  dikirim tanpa data EXIF (termasuk lokasi GPS).
- **Tempat sampah**: berkas yang dibuang bisa dipulihkan dan dihapus permanen otomatis setelah 30 hari.
- **Penjelajah berkas** dengan tampilan daftar atau ikon, pratinjau gambar/video/audio/PDF, unggah file atau satu
  folder sekaligus (tarik-lepas), dan **pencarian** nama berkas.

Halamannya dipasang oleh [Dashboard Dapur AdeMS](https://github.com/AdeMS/dapur-dashboard) 2.9 ke atas di menu
**Berkas** (`/berkas`, atau `/dashboard/berkas` di dashboard website), untuk semua user yang login.

## Konsep

### Database sebagai acuan, nama asli di disk

Setiap berkas dan folder tercatat di tabel `berkas`. Website hanya menampilkan berkas yang diunggah lewat website;
file yang ditaruh langsung di folder server tidak tampil.

File fisiknya disimpan dengan **nama asli** dan **susunan folder yang sama** seperti di website, sehingga tetap mudah
dibaca dari server (mis. lewat SSH atau pengelola file NAS) dan mudah dicadangkan:

```text
data/berkas/<kode akun>/Liburan 2026/Pantai.jpg
```

Nama hanya diubah bila ada bagian yang berbahaya atau bermasalah (lihat `NamaBerkas`): karakter `/ \ : * ? " < > |`,
karakter kendali, karakter pembalik arah teks, awalan `.` `@` `-`, nama cadangan Windows (`CON`, `NUL`, ...), atau
lebih dari 255 byte. Bila nama sudah dipakai di folder yang sama, berkas baru menjadi `Pantai (2).jpg`; berkas lama
tidak pernah ditimpa.

### Alamat memakai kode

Alamat di website memakai **kode acak** per berkas, bukan nama atau jalurnya:

```text
/berkas/k3J9xQ...(22 huruf)/Pantai.jpg     <- perlu login; akses diperiksa setiap kali dibuka
/b/<token 32 huruf>/pantai.jpg             <- tautan publik
```

Nama di ujung alamat hanya pemanis. Susunan folder pemilik tidak pernah terlihat dari tautan publik.

### Hak akses

| Hak | Bisa |
|---|---|
| pemilik | semua, termasuk memindah, berbagi, dan tempat sampah |
| boleh menulis | melihat, mengunggah ke folder itu, ganti nama, membuang ke sampah |
| hanya baca | melihat dan mengunduh |

Berbagi sebuah folder berlaku untuk seluruh isinya. Berkas yang diunggah ke folder milik orang lain menjadi milik
pemilik folder dan masuk ke kuotanya. Berkas di tempat sampah tetap dihitung dalam kuota.

### Jenis berkas

Gambar, audio, video, PDF, teks, font, dokumen kantor (doc/docx/xls/xlsx/ppt/pptx/odt/ods/odp/rtf), arsip
(zip/7z/rar/gz/tar), dan beberapa jenis peta (kml/kmz/gpx). Skrip dan halaman web (`.php`, `.phtml`, `.phar`,
`.html`) tidak diterima. Hanya gambar, PDF, audio, video, dan teks yang dibuka langsung di browser; jenis lain selalu
diunduh.

## Pemasangan

1. Pasang Dashboard 2.9 (membawa modul ini) atau tambahkan `"adems/dapur-berkas": "^1.0"` ke `composer.local.json`,
   lalu `composer update`.
2. Buat tabelnya: `php dapur pasang`.
3. Siapkan folder `data/berkas/` di inti Dapur yang **bisa ditulis web server** (mis. grup web server, izin 2770).
   Folder sementara unggahan dan gambar kecil berada di `data/cache/`.

Ekstensi PHP `gd` dipakai untuk gambar kecil dan salinan foto tanpa EXIF; `intl` untuk menyamakan penulisan huruf
beraksen. Tanpa keduanya modul tetap berjalan.

## Pengaturan

Tulis di `config/autoload/berkas.local.php` (tidak bisa diubah dari website). Contoh lengkap dengan penjelasan ada di
`config/berkas.local.php.dist`:

```bash
cp vendor/adems/dapur-berkas/config/berkas.local.php.dist config/autoload/berkas.local.php
```

Isinya:

```php
return [
    'berkas' => [
        'folder'         => '/path/ke/data/berkas', // bawaan <inti>/data/berkas
        'kuota_mb'       => 1024,                   // kuota bawaan tiap user, 0 = tanpa batas
        'sampah_hari'    => 30,                     // umur berkas di tempat sampah
        'unggah_maks_mb' => 2048,                   // ukuran paling besar satu berkas
        // Lokasi tambahan: berkas milik satu akun disimpan di folder lain.
        'lokasi' => [
            'foto' => ['path' => '/path/ke/foto', 'pemilik' => 'nama@contoh.com', 'judul' => 'Foto'],
        ],
    ],
];
```

Lokasi tambahan:

- tampil di penjelajah hanya untuk akun pemiliknya (isinya tetap bisa dibagikan seperti berkas biasa);
- tidak memakai kuota bawaan (kapasitasnya diatur di server);
- foldernya harus bisa ditulis web server, dan sebaiknya kosong saat mulai dipakai: file lama yang tidak tercatat di
  database tidak tampil, dan file baru tidak akan menimpanya (unggahan ke nama yang sama ditolak).

Kuota khusus per user diatur lewat terminal (lihat Perintah).

Unggahan dikirim **bertahap** per 1 MB dari browser, jadi file yang lebih besar dari `upload_max_filesize` PHP tetap
bisa diunggah tanpa mengubah pengaturan server.

## Perintah

```text
vendor/bin/dapur-berkas info                         pengaturan, lokasi tambahan, status tabel
vendor/bin/dapur-berkas kuota <email> [<MB>|bawaan]  lihat atau atur kuota (0 = tanpa batas)
vendor/bin/dapur-berkas bersihkan                    hapus permanen isi tempat sampah yang kedaluwarsa
```

Tempat sampah juga dibersihkan sesekali secara otomatis saat penjelajah dibuka, jadi perintah `bersihkan` tidak wajib
dijadwalkan.

## Memakai di modul lain

Modul yang memasang halaman berkas memakai `PabrikLayanan`:

```php
PabrikLayanan::jelajah($container, $pintu, $penyaji); // halaman penjelajah (GET dan POST)
PabrikLayanan::buka($container, $pintu);              // /berkas/<kode>[/<nama>]
PabrikLayanan::publik($container, $pintu);            // /b/<token>[/...], tanpa login
```

`$pintu` (`AdeMS\Dapur\User\Pintu`) menentukan alamat dasar dan lingkup website; `$penyaji` (`PenyajiHalaman`) membuat
halaman tampil di dalam kerangka modul itu. Lihat `RuteDelegator` di Dashboard sebagai contoh.

## Tabel

| Tabel | Isi |
|---|---|
| `berkas` | file dan folder: kode, pemilik, lokasi, folder induk, nama, ukuran, jenis, waktu dibuang |
| `berkas_bagi` | berbagi ke user, ke lingkup website, atau token tautan publik; hak; masa berlaku |
| `berkas_kuota` | kuota khusus per user |
| `berkas_riwayat` | catatan perubahan |

## Uji

```bash
composer install
composer test
```

## Lisensi

BSD-3-Clause. Lihat [LICENSE.md](LICENSE.md).
