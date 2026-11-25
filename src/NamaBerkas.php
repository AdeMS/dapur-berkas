<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Berkas;

use Normalizer;

/**
 * Aturan nama berkas dan folder.
 *
 * Berkas disimpan di disk dengan NAMA ASLINYA. Nama hanya diubah bila ada bagian yang berbahaya atau bermasalah:
 *
 * - karakter yang tidak boleh ada di nama file (/ \ : * ? " < > |), NUL, dan karakter kendali -> '_';
 * - karakter pembalik arah teks (mis. U+202E, trik agar "fotoexe.jpg" tampak seperti gambar) -> '_';
 * - spasi atau titik di ujung nama dibuang;
 * - diawali '.' (file tersembunyi), '@' (folder sistem NAS), atau '-' -> diberi '_' di depan;
 * - nama cadangan Windows (CON, PRN, AUX, NUL, COM1-9, LPT1-9) -> diberi '_' di depan;
 * - lebih dari 255 byte -> dipotong, akhiran (mis. .jpg) dipertahankan;
 * - huruf beraksen disamakan penulisannya (normalisasi Unicode NFC) bila ekstensi intl ada.
 */
final class NamaBerkas
{
    public const PANJANG_MAKS = 255;

    private const HURUF_KODE = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /** Panjang kode acak berkas di alamat website (/berkas/<kode>). */
    public const PANJANG_KODE = 22;

    /** Panjang token tautan publik (/b/<token>). */
    public const PANJANG_TOKEN = 32;

    public static function bersihkan(string $nama): string
    {
        if (! mb_check_encoding($nama, 'UTF-8')) {
            $nama = mb_convert_encoding($nama, 'UTF-8', 'UTF-8');
        }

        if (class_exists(Normalizer::class)) {
            $nama = Normalizer::normalize($nama, Normalizer::FORM_C) ?: $nama;
        }

        // Karakter terlarang, kendali, dan pembalik arah teks.
        $nama = preg_replace('/[\x00-\x1f\x7f\/\\\\:*?"<>|]|\x{200E}|\x{200F}|[\x{202A}-\x{202E}]|[\x{2066}-\x{2069}]/u', '_', $nama) ?? '';
        $nama = rtrim(ltrim($nama, ' '), ' .');

        if ($nama === '') {
            return 'berkas';
        }

        if (in_array($nama[0], ['.', '@', '-'], true)) {
            $nama = '_' . $nama;
        }

        $dasar = strtoupper(explode('.', $nama)[0]);
        if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/', $dasar) === 1) {
            $nama = '_' . $nama;
        }

        return self::potong($nama);
    }

    /** Memotong nama menjadi paling panjang 255 byte tanpa memotong huruf, dengan akhiran tetap utuh. */
    private static function potong(string $nama): string
    {
        if (strlen($nama) <= self::PANJANG_MAKS) {
            return $nama;
        }

        $akhiran = pathinfo($nama, PATHINFO_EXTENSION);
        $akhiran = $akhiran !== '' && strlen($akhiran) <= 20 ? '.' . $akhiran : '';
        $dasar   = substr($nama, 0, strlen($nama) - strlen($akhiran));

        return mb_strcut($dasar, 0, self::PANJANG_MAKS - strlen($akhiran), 'UTF-8') . $akhiran;
    }

    /** Nama berikutnya bila nama sudah dipakai: "Pantai.jpg" -> "Pantai (2).jpg". */
    public static function berikutnya(string $nama, int $nomor, bool $folder): string
    {
        $akhiran = $folder ? '' : pathinfo($nama, PATHINFO_EXTENSION);
        $akhiran = $akhiran !== '' ? '.' . $akhiran : '';
        $dasar   = substr($nama, 0, strlen($nama) - strlen($akhiran));
        $dasar   = preg_replace('/ \(\d+\)$/', '', $dasar) ?? $dasar;

        return self::potong($dasar . " ({$nomor})" . $akhiran);
    }

    /**
     * Nama rapi untuk alamat: huruf kecil tanpa aksen, spasi dan tanda baca jadi '-'. Hanya pemanis alamat; kuncinya
     * adalah kode atau token. Mis. "Laporan Bulan Juli (final).pdf" -> "laporan-bulan-juli-final.pdf".
     */
    public static function rapi(string $nama): string
    {
        $akhiran = strtolower(pathinfo($nama, PATHINFO_EXTENSION));
        $dasar   = $akhiran === '' ? $nama : substr($nama, 0, -strlen($akhiran) - 1);
        if (function_exists('transliterator_transliterate')) {
            $dasar = transliterator_transliterate('Any-Latin; Latin-ASCII', $dasar) ?: $dasar;
        }

        $dasar = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($dasar)) ?? '', '-');
        $dasar = substr($dasar === '' ? 'berkas' : $dasar, 0, 80);

        return $akhiran !== '' && preg_match('/^[a-z0-9]{1,10}$/', $akhiran) === 1 ? $dasar . '.' . $akhiran : $dasar;
    }

    public static function kode(int $panjang = self::PANJANG_KODE): string
    {
        $hasil = '';
        for ($i = 0; $i < $panjang; $i++) {
            $hasil .= self::HURUF_KODE[random_int(0, 61)];
        }

        return $hasil;
    }

    public static function kodeSah(string $kode, int $panjang = self::PANJANG_KODE): bool
    {
        return preg_match('/^[0-9A-Za-z]{' . $panjang . '}$/', $kode) === 1;
    }
}
