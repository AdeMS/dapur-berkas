<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Berkas;

use AdeMS\Dapur\Database\KonteksSkema;
use AdeMS\Dapur\Database\SkemaModul;

/**
 * Tabel modul berkas untuk `php dapur pasang`:
 *
 * - berkas: file dan folder (pemilik, lokasi, folder induk, nama, ukuran, jenis, kode alamat, waktu dibuang ke sampah);
 * - berkas_bagi: berbagi ke user, ke anggota satu website, atau publik lewat token; hak baca atau tulis;
 * - berkas_kuota: kuota khusus per user (tanpa baris = kuota bawaan);
 * - berkas_riwayat: catatan perubahan.
 */
final class BerkasSkema implements SkemaModul
{
    public const NAMA = 'berkas';

    public function nama(): string
    {
        return self::NAMA;
    }

    public function paket(): string
    {
        return 'adems/dapur-berkas';
    }

    public function butuh(): array
    {
        return ['user'];
    }

    public function tabel(KonteksSkema $k): array
    {
        $t     = new NamaTabel($k->awalan);
        $mysql = $k->platform === KonteksSkema::MYSQL;
        $id    = $mysql ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $ref   = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $besar = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $akhir = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $q     = static fn (string $n): string => $k->kutip($n);

        return [
            $t->berkas => "CREATE TABLE {$q($t->berkas)} (
                id {$id},
                kode CHAR(22) NOT NULL,
                pemilik_id {$ref} NOT NULL,
                lokasi VARCHAR(30) NOT NULL DEFAULT '',
                induk_id {$ref} NULL,
                folder SMALLINT NOT NULL DEFAULT 0,
                nama VARCHAR(255) NOT NULL,
                ukuran {$besar} NOT NULL DEFAULT 0,
                jenis VARCHAR(120) NULL,
                sistem VARCHAR(30) NULL,
                dibuat_pada DATETIME NOT NULL,
                diubah_pada DATETIME NULL,
                dihapus_pada DATETIME NULL,
                sampah_jalur VARCHAR(255) NULL,
                CONSTRAINT {$q($t->berkas . '_kode_unik')} UNIQUE (kode),
                CONSTRAINT {$q($t->berkas . '_pemilik_fk')} FOREIGN KEY (pemilik_id) REFERENCES {$q($t->user)} (id) ON DELETE CASCADE,
                CONSTRAINT {$q($t->berkas . '_induk_fk')} FOREIGN KEY (induk_id) REFERENCES {$q($t->berkas)} (id) ON DELETE CASCADE
            ){$akhir}",
            $t->bagi => "CREATE TABLE {$q($t->bagi)} (
                id {$id},
                berkas_id {$ref} NOT NULL,
                user_id {$ref} NULL,
                lingkup VARCHAR(190) NULL,
                token CHAR(32) NULL,
                hak VARCHAR(10) NOT NULL DEFAULT 'baca',
                dibuat_oleh {$ref} NULL,
                dibuat_pada DATETIME NOT NULL,
                sampai DATETIME NULL,
                CONSTRAINT {$q($t->bagi . '_token_unik')} UNIQUE (token),
                CONSTRAINT {$q($t->bagi . '_berkas_fk')} FOREIGN KEY (berkas_id) REFERENCES {$q($t->berkas)} (id) ON DELETE CASCADE,
                CONSTRAINT {$q($t->bagi . '_user_fk')} FOREIGN KEY (user_id) REFERENCES {$q($t->user)} (id) ON DELETE CASCADE
            ){$akhir}",
            $t->kuota => "CREATE TABLE {$q($t->kuota)} (
                user_id {$ref} NOT NULL PRIMARY KEY,
                kuota_mb INTEGER NOT NULL,
                diubah_pada DATETIME NOT NULL,
                CONSTRAINT {$q($t->kuota . '_user_fk')} FOREIGN KEY (user_id) REFERENCES {$q($t->user)} (id) ON DELETE CASCADE
            ){$akhir}",
            $t->riwayat => "CREATE TABLE {$q($t->riwayat)} (
                id {$id},
                berkas_id {$ref} NULL,
                pemilik_id {$ref} NOT NULL,
                pelaku_id {$ref} NULL,
                peristiwa VARCHAR(30) NOT NULL,
                keterangan VARCHAR(255) NULL,
                waktu DATETIME NOT NULL
            ){$akhir}",
        ];
    }

    public function kolom(KonteksSkema $k): array
    {
        return [];
    }

    public function indeks(KonteksSkema $k): array
    {
        $t = new NamaTabel($k->awalan);
        $i = static fn (string $nama, string $tabel, string $kolom): string => sprintf('CREATE INDEX %s ON %s (%s)', $k->kutip($nama), $k->kutip($tabel), $kolom);

        return [
            $t->berkas . '_isi_idx'      => $i($t->berkas . '_isi_idx', $t->berkas, 'pemilik_id, lokasi, induk_id'),
            $t->berkas . '_nama_idx'     => $i($t->berkas . '_nama_idx', $t->berkas, 'nama'),
            $t->bagi . '_user_idx'       => $i($t->bagi . '_user_idx', $t->bagi, 'user_id'),
            $t->bagi . '_lingkup_idx'    => $i($t->bagi . '_lingkup_idx', $t->bagi, 'lingkup'),
            $t->riwayat . '_pemilik_idx' => $i($t->riwayat . '_pemilik_idx', $t->riwayat, 'pemilik_id, waktu'),
        ];
    }

    public function langkah(KonteksSkema $k): array
    {
        return [];
    }
}
