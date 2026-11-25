<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Berkas;

use GdImage;
use Throwable;

/**
 * Turunan gambar yang disimpan di cache:
 *
 * - gambar kecil (thumbnail) WebP untuk tampilan ikon di penjelajah;
 * - salinan JPEG tanpa data EXIF (termasuk lokasi GPS) untuk tautan publik, agar lokasi pengambilan foto tidak ikut
 *   tersebar.
 *
 * Bila ekstensi gd tidak ada atau gambar terlalu besar, mengembalikan null (pemanggil memakai file asli / ikon).
 */
final class Gambar
{
    public const UKURAN_KECIL = 320;

    /** Gambar lebih dari ini (piksel) tidak diproses, agar memori server tidak habis. */
    public const PIKSEL_MAKS = 24_000_000;

    public function __construct(private readonly string $folderCache)
    {
    }

    public function kecil(string $file, string $kode): ?string
    {
        $tujuan = sprintf('%s/kecil/%s-%x.webp', $this->folderCache, $kode, (int) @filemtime($file));
        if (is_file($tujuan)) {
            return $tujuan;
        }

        $gambar = $this->buka($file);
        if ($gambar === null || ! function_exists('imagewebp')) {
            return null;
        }

        $lebar  = imagesx($gambar);
        $tinggi = imagesy($gambar);
        $skala  = min(1, self::UKURAN_KECIL / max($lebar, $tinggi));
        $kecil  = imagescale($gambar, max(1, (int) round($lebar * $skala)), max(1, (int) round($tinggi * $skala)));
        if ($kecil === false) {
            return null;
        }

        return $this->simpan($tujuan, static fn (string $ke): bool => imagewebp($kecil, $ke, 80), $kecil);
    }

    /** Salinan JPEG tanpa EXIF; untuk jenis lain mengembalikan file asli. */
    public function tanpaExif(string $file, string $kode): string
    {
        if (! in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['jpg', 'jpeg'], true)) {
            return $file;
        }

        $tujuan = sprintf('%s/publik/%s-%x.jpg', $this->folderCache, $kode, (int) @filemtime($file));
        if (is_file($tujuan)) {
            return $tujuan;
        }

        $gambar = $this->buka($file);
        if ($gambar === null) {
            return $file;
        }

        return $this->simpan($tujuan, static fn (string $ke): bool => imagejpeg($gambar, $ke, 90), $gambar) ?? $file;
    }

    private function buka(string $file): ?GdImage
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $info = @getimagesize($file);
        if ($info === false || $info[0] * $info[1] > self::PIKSEL_MAKS) {
            return null;
        }

        try {
            $gambar = match ($info[2]) {
                IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
                IMAGETYPE_PNG  => @imagecreatefrompng($file),
                IMAGETYPE_GIF  => @imagecreatefromgif($file),
                IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
                default        => false,
            };
        } catch (Throwable) {
            return null;
        }

        if (! $gambar instanceof GdImage) {
            return null;
        }

        // Putar sesuai orientasi kamera (EXIF) sebelum EXIF-nya dibuang.
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $orientasi = (int) (@exif_read_data($file)['Orientation'] ?? 1);
            $putar     = [3 => 180, 6 => -90, 8 => 90][$orientasi] ?? 0;
            if ($putar !== 0) {
                $diputar = imagerotate($gambar, $putar, 0);
                if ($diputar !== false) {
                    $gambar = $diputar;
                }
            }
        }

        imagepalettetotruecolor($gambar);
        imagesavealpha($gambar, true);

        return $gambar;
    }

    /** @param callable(string): bool $tulis */
    private function simpan(string $tujuan, callable $tulis, GdImage $gambar): ?string
    {
        try {
            KelolaBerkas::buatFolderFisik(dirname($tujuan));
            $sementara = $tujuan . '.' . bin2hex(random_bytes(4));
            if (! $tulis($sementara) || ! @rename($sementara, $tujuan)) {
                @unlink($sementara);

                return null;
            }
            @chmod($tujuan, 0660);

            return $tujuan;
        } catch (Throwable) {
            return null;
        }
    }
}
