<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Berkas;

/** Satu berkas atau folder. */
final class Berkas
{
    public function __construct(
        public readonly int $id,
        public readonly string $kode,
        public readonly int $pemilikId,
        public readonly string $lokasi,
        public readonly ?int $indukId,
        public readonly bool $folder,
        public readonly string $nama,
        public readonly int $ukuran,
        public readonly ?string $jenis,
        public readonly ?string $sistem,
        public readonly string $dibuatPada,
        public readonly ?string $diubahPada,
        public readonly ?string $dihapusPada,
        public readonly ?string $sampahJalur,
    ) {
    }

    /** @param array<string, mixed> $b baris tabel berkas */
    public static function dariBaris(array $b): self
    {
        return new self(
            (int) $b['id'],
            (string) $b['kode'],
            (int) $b['pemilik_id'],
            (string) $b['lokasi'],
            $b['induk_id'] === null ? null : (int) $b['induk_id'],
            (int) $b['folder'] === 1,
            (string) $b['nama'],
            (int) $b['ukuran'],
            $b['jenis'] === null ? null : (string) $b['jenis'],
            $b['sistem'] === null ? null : (string) $b['sistem'],
            (string) $b['dibuat_pada'],
            $b['diubah_pada'] === null ? null : (string) $b['diubah_pada'],
            $b['dihapus_pada'] === null ? null : (string) $b['dihapus_pada'],
            $b['sampah_jalur'] === null ? null : (string) $b['sampah_jalur'],
        );
    }

    public function dibuang(): bool
    {
        return $this->dihapusPada !== null;
    }
}
