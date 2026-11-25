<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Berkas;

use Composer\InstalledVersions;

/**
 * Pengaturan berkas dari file konfigurasi, `'berkas' => [...]`. Tidak bisa diubah dari website.
 *
 *     'berkas' => [
 *         // Lokasi bawaan: berkas tiap user di <folder>/<kode akun>/. Bawaan: <inti>/data/berkas
 *         'folder'      => '/path/ke/data/berkas',
 *         // Kuota bawaan tiap user di lokasi bawaan, dalam MB (0 = tanpa batas). Bawaan 1024.
 *         'kuota_mb'    => 1024,
 *         // Umur berkas di tempat sampah sebelum dihapus permanen, dalam hari. Bawaan 30.
 *         'sampah_hari' => 30,
 *         // Ukuran paling besar satu berkas yang diunggah, dalam MB (dikirim bertahap per 1 MB). Bawaan 2048.
 *         'unggah_maks_mb' => 2048,
 *         // Lokasi tambahan (alias): berkas disimpan di folder lain, milik satu akun.
 *         'lokasi' => [
 *             'foto' => ['path' => '/path/ke/foto', 'pemilik' => 'nama@contoh.com', 'judul' => 'Foto'],
 *         ],
 *     ],
 *
 * Nilai bawaan diletakkan di kelas ini, bukan di ConfigProvider, agar modul yang dimuat sesudah konfigurasi inti tidak
 * menimpa pengaturan inti.
 */
final class Pengaturan
{
    public const KUOTA_MB    = 1024;
    public const SAMPAH_HARI = 30;
    public const UNGGAH_MAKS_MB = 2048;

    /** Nama bagian penjelajah yang tidak boleh dipakai sebagai alias. */
    public const NAMA_TERPAKAI = ['saya', 'bagi', 'sampah', 'cari'];

    public readonly string $folder;
    public readonly string $folderSementara;
    public readonly string $folderCache;
    public readonly int $kuota;
    public readonly int $sampahHari;
    public readonly int $unggahMaks;

    /** @var array<string, array{path: string, pemilik: string, judul: string}> alias => lokasi */
    public readonly array $lokasi;

    /** @param array<string, mixed> $config isi $config['berkas'] */
    public function __construct(array $config = [], ?string $folderInti = null)
    {
        $inti = $folderInti ?? self::folderInti();

        $this->folder          = rtrim(self::teks($config['folder'] ?? null) ?? $inti . '/data/berkas', '/');
        $this->folderSementara = rtrim(self::teks($config['folder_sementara'] ?? null) ?? $inti . '/data/cache/berkas-unggah', '/');
        $this->folderCache     = rtrim(self::teks($config['folder_cache'] ?? null) ?? $inti . '/data/cache/berkas', '/');
        $this->kuota           = max(0, (int) ($config['kuota_mb'] ?? self::KUOTA_MB)) * 1024 * 1024;
        $this->sampahHari      = max(1, (int) ($config['sampah_hari'] ?? self::SAMPAH_HARI));
        $this->unggahMaks      = max(1, (int) ($config['unggah_maks_mb'] ?? self::UNGGAH_MAKS_MB)) * 1024 * 1024;

        $lokasi = [];
        foreach (is_array($config['lokasi'] ?? null) ? $config['lokasi'] : [] as $alias => $isi) {
            if (
                ! is_string($alias) || preg_match('/^[a-z0-9][a-z0-9-]{0,29}$/', $alias) !== 1
                || in_array($alias, self::NAMA_TERPAKAI, true)
                || ! is_array($isi) || self::teks($isi['path'] ?? null) === null || self::teks($isi['pemilik'] ?? null) === null
            ) {
                continue;
            }

            $lokasi[$alias] = [
                'path'    => rtrim((string) $isi['path'], '/'),
                'pemilik' => strtolower(trim((string) $isi['pemilik'])),
                'judul'   => self::teks($isi['judul'] ?? null) ?? ucfirst($alias),
            ];
        }
        $this->lokasi = $lokasi;
    }

    private static function teks(mixed $nilai): ?string
    {
        return is_string($nilai) && trim($nilai) !== '' ? $nilai : null;
    }

    public static function folderInti(): string
    {
        $folder = InstalledVersions::getRootPackage()['install_path'];

        return rtrim((string) (realpath($folder) ?: $folder), '/');
    }
}
