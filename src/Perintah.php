<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Berkas;

use AdeMS\Dapur\Asset\Penyimpanan;

/**
 * Perintah terminal modul berkas:
 *
 *   vendor/bin/dapur-berkas info
 *   vendor/bin/dapur-berkas kuota <email> [<MB>|bawaan]
 *   vendor/bin/dapur-berkas bersihkan
 */
final class Perintah
{
    /** @param \Closure(string): ?int $cariUserEmail */
    public function __construct(
        private readonly KelolaBerkas $kelola,
        private readonly \Closure $cariUserEmail,
    ) {
    }

    /** @param list<string> $argumen */
    public function jalankan(array $argumen): int
    {
        $bebas = array_values(array_filter($argumen, static fn (string $a): bool => ! str_starts_with($a, '--')));

        try {
            return match ($bebas[0] ?? '') {
                'info'      => $this->info(),
                'kuota'     => $this->kuota($bebas[1] ?? '', $bebas[2] ?? null),
                'bersihkan' => $this->bersihkan(),
                default     => $this->bantuan(),
            };
        } catch (GagalBerkas $e) {
            fwrite(STDERR, 'Gagal: ' . $e->getMessage() . PHP_EOL);

            return 1;
        }
    }

    private function info(): int
    {
        $p = $this->kelola->pengaturan;
        printf('Folder berkas   : %s%s', $p->folder, PHP_EOL);
        printf('Kuota bawaan    : %s%s', $p->kuota > 0 ? Penyimpanan::ukuranTeks($p->kuota) : 'tanpa batas', PHP_EOL);
        printf('Tempat sampah   : %d hari%s', $p->sampahHari, PHP_EOL);
        printf('Unggah maksimal : %s%s', Penyimpanan::ukuranTeks($p->unggahMaks), PHP_EOL);
        printf('Tabel           : %s%s', $this->kelola->daftar->tersedia() ? 'siap' : 'belum dibuat (jalankan: php dapur pasang)', PHP_EOL);
        echo 'Lokasi tambahan :' . ($p->lokasi === [] ? ' (tidak ada)' : '') . PHP_EOL;
        foreach ($p->lokasi as $alias => $l) {
            printf(
                '  %-12s %s  pemilik %s%s%s',
                $alias,
                $l['path'],
                $l['pemilik'],
                $this->kelola->pemilikAlias($alias) === null ? ' (AKUN TIDAK DITEMUKAN)' : '',
                is_dir($l['path']) ? (is_writable($l['path']) ? '' : ' (folder tidak bisa ditulis akun ini)') : ' (FOLDER TIDAK ADA)',
            );
            echo PHP_EOL;
        }

        return 0;
    }

    private function kuota(string $email, ?string $mb): int
    {
        $id = ($this->cariUserEmail)(strtolower(trim($email)));
        if ($id === null) {
            throw new GagalBerkas("Akun {$email} tidak ditemukan.");
        }

        if ($mb !== null) {
            if ($mb !== 'bawaan' && ! ctype_digit($mb)) {
                throw new GagalBerkas('Kuota harus angka MB (0 = tanpa batas) atau "bawaan".');
            }
            $this->kelola->daftar->aturKuota($id, $mb === 'bawaan' ? null : (int) $mb);
        }

        $k = $this->kelola->kuota($id);
        printf(
            'Kuota %s: terpakai %s dari %s%s%s',
            $email,
            Penyimpanan::ukuranTeks($k['pakai']),
            $k['batas'] > 0 ? Penyimpanan::ukuranTeks($k['batas']) : 'tanpa batas',
            $this->kelola->daftar->kuotaKhusus($id) === null ? ' (bawaan)' : ' (khusus)',
            PHP_EOL,
        );

        return 0;
    }

    private function bersihkan(): int
    {
        printf('%d berkas di tempat sampah yang lewat %d hari dihapus permanen.%s', $this->kelola->bersihkanKedaluwarsa(), $this->kelola->pengaturan->sampahHari, PHP_EOL);

        return 0;
    }

    private function bantuan(): int
    {
        echo <<<TEKS
            Perintah modul berkas Dapur AdeMS:

              vendor/bin/dapur-berkas info
              vendor/bin/dapur-berkas kuota <email> [<MB>|bawaan]   (0 = tanpa batas)
              vendor/bin/dapur-berkas bersihkan

            TEKS;

        return 1;
    }
}
