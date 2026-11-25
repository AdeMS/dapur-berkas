<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Berkas;

use AdeMS\Dapur\Asset\JenisFile;

/**
 * Jenis berkas yang boleh diunggah user: semua jenis aset (gambar, audio, video, font, pdf, teks) ditambah dokumen
 * kantor dan arsip. Skrip dan halaman web (.php, .phtml, .phar, .html, .htm, .js dibuka langsung) tidak diterima.
 *
 * Hanya gambar, pdf, audio, video, dan teks yang dibuka langsung di browser; jenis lain selalu diunduh.
 */
final class JenisBerkas
{
    /** @var array<string, string> akhiran => Content-Type, tambahan dari jenis aset */
    public const TAMBAHAN = [
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt'  => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt'  => 'application/vnd.oasis.opendocument.text',
        'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp'  => 'application/vnd.oasis.opendocument.presentation',
        'rtf'  => 'application/rtf',
        'zip'  => 'application/zip',
        '7z'   => 'application/x-7z-compressed',
        'rar'  => 'application/vnd.rar',
        'gz'   => 'application/gzip',
        'tar'  => 'application/x-tar',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
        'mov'  => 'video/quicktime',
        'mkv'  => 'video/x-matroska',
        'm4v'  => 'video/mp4',
        'kml'  => 'application/vnd.google-earth.kml+xml',
        'kmz'  => 'application/vnd.google-earth.kmz',
        'gpx'  => 'application/gpx+xml',
        'xml'  => 'application/xml',
    ];

    /** Awalan Content-Type yang boleh dibuka langsung di browser. */
    private const DIBUKA_LANGSUNG = ['image/', 'audio/', 'video/', 'text/plain', 'text/csv', 'application/pdf'];

    /** Jenis yang tidak pernah dibuka langsung walau berawalan di atas (bisa berisi skrip atau tidak didukung browser). */
    private const SELALU_UNDUH = ['js', 'mjs', 'css', 'map', 'json', 'heic', 'heif', 'mkv', 'mov'];

    public static function diizinkan(string $nama): bool
    {
        return self::contentType($nama) !== null;
    }

    public static function contentType(string $nama): ?string
    {
        $akhiran = strtolower(pathinfo($nama, PATHINFO_EXTENSION));

        return self::TAMBAHAN[$akhiran] ?? JenisFile::contentType($nama);
    }

    public static function dibukaLangsung(string $nama): bool
    {
        $jenis = self::contentType($nama);
        if ($jenis === null || in_array(strtolower(pathinfo($nama, PATHINFO_EXTENSION)), self::SELALU_UNDUH, true)) {
            return false;
        }

        foreach (self::DIBUKA_LANGSUNG as $awalan) {
            if (str_starts_with($jenis, $awalan)) {
                return true;
            }
        }

        return false;
    }

    public static function gambar(string $nama): bool
    {
        return in_array(strtolower(pathinfo($nama, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
    }

    /** Ikon Bootstrap Icons untuk daftar berkas. */
    public static function ikon(string $nama): string
    {
        $jenis = (string) self::contentType($nama);

        return match (true) {
            str_starts_with($jenis, 'image/') => 'file-earmark-image',
            str_starts_with($jenis, 'audio/') => 'file-earmark-music',
            str_starts_with($jenis, 'video/') => 'file-earmark-play',
            $jenis === 'application/pdf'      => 'file-earmark-pdf',
            str_contains($jenis, 'word') || str_contains($jenis, 'opendocument.text') => 'file-earmark-word',
            str_contains($jenis, 'sheet') || str_contains($jenis, 'excel') || $jenis === 'text/csv' => 'file-earmark-excel',
            str_contains($jenis, 'presentation') || str_contains($jenis, 'powerpoint') => 'file-earmark-ppt',
            in_array($jenis, ['application/zip', 'application/x-7z-compressed', 'application/vnd.rar', 'application/gzip', 'application/x-tar'], true) => 'file-earmark-zip',
            str_starts_with($jenis, 'text/')  => 'file-earmark-text',
            default                           => 'file-earmark',
        };
    }

    public static function teks(): string
    {
        return implode(', ', array_unique([...array_keys(JenisFile::DAFTAR), ...array_keys(self::TAMBAHAN)]));
    }
}
