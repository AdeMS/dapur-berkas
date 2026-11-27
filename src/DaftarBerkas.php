<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Berkas;

use Laminas\Db\Adapter\Adapter;
use Throwable;

/** Data berkas, berbagi, kuota, dan riwayat di database. Aturan (akses, kuota, file fisik) ada di KelolaBerkas. */
final class DaftarBerkas
{
    public const PERISTIWA = [
        'unggah'       => 'Unggah',
        'buat_folder'  => 'Folder dibuat',
        'ganti_nama'   => 'Ganti nama',
        'pindah'       => 'Pindah',
        'buang'        => 'Dibuang ke sampah',
        'pulihkan'     => 'Dipulihkan',
        'hapus'        => 'Dihapus permanen',
        'bagi'         => 'Dibagikan',
        'cabut_bagi'   => 'Berbagi dicabut',
        'tautan'       => 'Tautan publik dibuat',
        'cabut_tautan' => 'Tautan publik dihapus',
    ];

    private ?bool $tersedia = null;

    public function __construct(
        public readonly Adapter $db,
        private readonly NamaTabel $t,
    ) {
    }

    public function tersedia(): bool
    {
        if ($this->tersedia !== null) {
            return $this->tersedia;
        }

        try {
            $this->baris('SELECT 1 FROM ' . $this->q($this->t->berkas) . ' WHERE 1 = 0');

            return $this->tersedia = true;
        } catch (Throwable) {
            return $this->tersedia = false;
        }
    }

    // ------------------------------------------------------------------ berkas

    public function id(int $id): ?Berkas
    {
        $b = $this->baris('SELECT * FROM ' . $this->q($this->t->berkas) . ' WHERE id = ?', [$id]);

        return $b === [] ? null : Berkas::dariBaris($b[0]);
    }

    public function kode(string $kode): ?Berkas
    {
        if (! NamaBerkas::kodeSah($kode)) {
            return null;
        }

        $b = $this->baris('SELECT * FROM ' . $this->q($this->t->berkas) . ' WHERE kode = ?', [$kode]);

        return $b === [] ? null : Berkas::dariBaris($b[0]);
    }

    /**
     * Isi satu folder (indukId null = akar lokasi), tanpa yang dibuang. Folder dulu, lalu nama.
     *
     * @return list<Berkas>
     */
    public function isi(int $pemilikId, string $lokasi, ?int $indukId): array
    {
        $sql = 'SELECT * FROM ' . $this->q($this->t->berkas)
            . ' WHERE pemilik_id = ? AND lokasi = ? AND ' . ($indukId === null ? 'induk_id IS NULL' : 'induk_id = ?')
            . ' AND dihapus_pada IS NULL ORDER BY folder DESC, nama';

        return $this->berkas($this->baris($sql, $indukId === null ? [$pemilikId, $lokasi] : [$pemilikId, $lokasi, $indukId]));
    }

    /** Folder sistem milik akun di akar lokasi (mis. 'foto_profil'), tanpa yang dibuang. */
    public function sistem(int $pemilikId, string $lokasi, string $sistem): ?Berkas
    {
        $b = $this->baris(
            'SELECT * FROM ' . $this->q($this->t->berkas)
            . ' WHERE pemilik_id = ? AND lokasi = ? AND sistem = ? AND induk_id IS NULL AND dihapus_pada IS NULL ORDER BY id',
            [$pemilikId, $lokasi, $sistem],
        );

        return $b === [] ? null : Berkas::dariBaris($b[0]);
    }

    /** @return list<Berkas> anak langsung (termasuk yang dibuang) */
    public function anak(int $id): array
    {
        return $this->berkas($this->baris('SELECT * FROM ' . $this->q($this->t->berkas) . ' WHERE induk_id = ?', [$id]));
    }

    /** Berkas dengan nama ini di folder yang sama (tanpa yang dibuang). */
    public function namaDipakai(int $pemilikId, string $lokasi, ?int $indukId, string $nama, ?int $kecuali = null): bool
    {
        $sql   = 'SELECT id FROM ' . $this->q($this->t->berkas)
            . ' WHERE pemilik_id = ? AND lokasi = ? AND ' . ($indukId === null ? 'induk_id IS NULL' : 'induk_id = ?')
            . ' AND dihapus_pada IS NULL AND LOWER(nama) = LOWER(?)';
        $param = $indukId === null ? [$pemilikId, $lokasi, $nama] : [$pemilikId, $lokasi, $indukId, $nama];
        foreach ($this->baris($sql, $param) as $b) {
            if ((int) $b['id'] !== $kecuali) {
                return true;
            }
        }

        return false;
    }

    public function tambah(int $pemilikId, string $lokasi, ?int $indukId, bool $folder, string $nama, int $ukuran, ?string $jenis, ?string $sistem = null): Berkas
    {
        do {
            $kode = NamaBerkas::kode();
        } while ($this->kode($kode) !== null);

        $this->jalankan(
            'INSERT INTO ' . $this->q($this->t->berkas)
            . ' (kode, pemilik_id, lokasi, induk_id, folder, nama, ukuran, jenis, sistem, dibuat_pada) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$kode, $pemilikId, $lokasi, $indukId, $folder ? 1 : 0, $nama, $ukuran, $jenis, $sistem, self::sekarang()],
        );

        return $this->kode($kode) ?? throw new GagalBerkas('Berkas tidak tersimpan.');
    }

    public function perbarui(int $id, string $nama, ?int $indukId, ?int $ukuran = null): void
    {
        $this->jalankan(
            'UPDATE ' . $this->q($this->t->berkas) . ' SET nama = ?, induk_id = ?, diubah_pada = ?' . ($ukuran === null ? '' : ', ukuran = ' . $ukuran) . ' WHERE id = ?',
            [$nama, $indukId, self::sekarang(), $id],
        );
    }

    /** @param list<int> $ids */
    public function tandaiBuang(array $ids, string $waktu): void
    {
        foreach (array_chunk($ids, 200) as $bagian) {
            $this->jalankan(
                'UPDATE ' . $this->q($this->t->berkas) . ' SET dihapus_pada = ? WHERE id IN (' . implode(',', array_fill(0, count($bagian), '?')) . ')',
                [$waktu, ...$bagian],
            );
        }
    }

    public function aturSampah(int $id, ?string $jalur, ?int $indukId, ?string $nama = null): void
    {
        $this->jalankan(
            'UPDATE ' . $this->q($this->t->berkas) . ' SET sampah_jalur = ?, induk_id = ?' . ($nama === null ? '' : ', nama = ?')
            . ($jalur === null ? ', dihapus_pada = NULL' : '') . ' WHERE id = ?',
            $nama === null ? [$jalur, $indukId, $id] : [$jalur, $indukId, $nama, $id],
        );
    }

    /** @param list<int> $ids */
    public function batalBuang(array $ids): void
    {
        foreach (array_chunk($ids, 200) as $bagian) {
            $this->jalankan(
                'UPDATE ' . $this->q($this->t->berkas) . ' SET dihapus_pada = NULL WHERE id IN (' . implode(',', array_fill(0, count($bagian), '?')) . ')',
                $bagian,
            );
        }
    }

    /** @param list<int> $ids */
    public function hapus(array $ids): void
    {
        // Anak dihapus lebih dulu (SQLite tanpa PRAGMA foreign_keys tidak menjalankan ON DELETE CASCADE).
        foreach (array_chunk(array_reverse($ids), 200) as $bagian) {
            $tanda = implode(',', array_fill(0, count($bagian), '?'));
            $this->jalankan('DELETE FROM ' . $this->q($this->t->bagi) . " WHERE berkas_id IN ({$tanda})", $bagian);
            $this->jalankan('DELETE FROM ' . $this->q($this->t->berkas) . " WHERE id IN ({$tanda})", $bagian);
        }
    }

    /** @return list<Berkas> isi tempat sampah (yang dibuang langsung, bukan isi folder yang ikut dibuang) */
    public function sampah(int $pemilikId): array
    {
        return $this->berkas($this->baris(
            'SELECT * FROM ' . $this->q($this->t->berkas) . ' WHERE pemilik_id = ? AND sampah_jalur IS NOT NULL ORDER BY dihapus_pada DESC',
            [$pemilikId],
        ));
    }

    /** @return list<Berkas> sampah yang sudah lewat batas umur, semua user */
    public function sampahKedaluwarsa(string $batas, int $jumlah = 200): array
    {
        return $this->berkas($this->baris(
            'SELECT * FROM ' . $this->q($this->t->berkas) . ' WHERE sampah_jalur IS NOT NULL AND dihapus_pada < ? ORDER BY dihapus_pada LIMIT ' . max(1, $jumlah),
            [$batas],
        ));
    }

    /** Total ukuran berkas user di lokasi bawaan, termasuk yang ada di tempat sampah. */
    public function pemakaian(int $pemilikId): int
    {
        $b = $this->baris(
            'SELECT COALESCE(SUM(ukuran), 0) AS total FROM ' . $this->q($this->t->berkas) . " WHERE pemilik_id = ? AND lokasi = '' AND folder = 0",
            [$pemilikId],
        );

        return (int) ($b[0]['total'] ?? 0);
    }

    /**
     * Mencari nama berkas milik user (tanpa yang dibuang), paling banyak $jumlah.
     *
     * @return list<Berkas>
     */
    public function cariMilik(int $pemilikId, string $kata, int $jumlah = 100): array
    {
        return $this->berkas($this->baris(
            'SELECT * FROM ' . $this->q($this->t->berkas) . " WHERE pemilik_id = ? AND dihapus_pada IS NULL AND LOWER(nama) LIKE ? ESCAPE '!' ORDER BY folder DESC, nama LIMIT " . max(1, $jumlah),
            [$pemilikId, '%' . self::likeAman(mb_strtolower($kata)) . '%'],
        ));
    }

    /**
     * Mencari nama di bawah beberapa folder (untuk berkas yang dibagikan): berkas yang ditemukan lalu diperiksa leluhurnya.
     *
     * @return list<Berkas>
     */
    public function cariSemua(string $kata, int $kecualiPemilik, int $jumlah = 500): array
    {
        return $this->berkas($this->baris(
            'SELECT * FROM ' . $this->q($this->t->berkas) . " WHERE pemilik_id <> ? AND dihapus_pada IS NULL AND LOWER(nama) LIKE ? ESCAPE '!' ORDER BY nama LIMIT " . max(1, $jumlah),
            [$kecualiPemilik, '%' . self::likeAman(mb_strtolower($kata)) . '%'],
        ));
    }

    // ------------------------------------------------------------------ berbagi

    /** @return list<array<string, mixed>> berbagi pada satu berkas, beserta email user tujuan */
    public function bagiBerkas(int $berkasId): array
    {
        return $this->baris(
            'SELECT b.*, u.email, u.nama AS nama_user FROM ' . $this->q($this->t->bagi) . ' b LEFT JOIN ' . $this->q($this->t->user)
            . ' u ON u.id = b.user_id WHERE b.berkas_id = ? ORDER BY b.id',
            [$berkasId],
        );
    }

    /**
     * Berbagi yang masih berlaku pada berkas-berkas ini (untuk memeriksa akses berkas dan leluhurnya).
     *
     * @param list<int> $berkasIds
     * @return list<array<string, mixed>>
     */
    public function bagiUntuk(array $berkasIds): array
    {
        if ($berkasIds === []) {
            return [];
        }

        return $this->baris(
            'SELECT * FROM ' . $this->q($this->t->bagi) . ' WHERE berkas_id IN (' . implode(',', array_fill(0, count($berkasIds), '?')) . ') AND (sampai IS NULL OR sampai > ?)',
            [...$berkasIds, self::sekarang()],
        );
    }

    /**
     * Berkas yang dibagikan langsung ke user ini atau ke salah satu lingkupnya (tanpa yang dibuang).
     *
     * @param list<string> $lingkup
     * @return list<array{berkas: Berkas, hak: string}>
     */
    public function dibagikanKe(int $userId, array $lingkup): array
    {
        $syarat = 'b.user_id = ?';
        $param  = [$userId];
        if ($lingkup !== []) {
            $syarat .= ' OR b.lingkup IN (' . implode(',', array_fill(0, count($lingkup), '?')) . ')';
            $param   = [...$param, ...$lingkup];
        }

        $hasil = [];
        $sql   = 'SELECT f.*, b.hak AS hak_bagi FROM ' . $this->q($this->t->bagi) . ' b JOIN ' . $this->q($this->t->berkas) . ' f ON f.id = b.berkas_id'
            . " WHERE ({$syarat}) AND f.pemilik_id <> ? AND f.dihapus_pada IS NULL AND (b.sampai IS NULL OR b.sampai > ?) ORDER BY f.folder DESC, f.nama";
        foreach ($this->baris($sql, [...$param, $userId, self::sekarang()]) as $b) {
            $id = (int) $b['id'];
            // Satu berkas bisa dibagikan dua kali (ke user dan ke lingkup): hak tertinggi yang dipakai.
            if (! isset($hasil[$id]) || $b['hak_bagi'] === KelolaBerkas::HAK_TULIS) {
                $hasil[$id] = ['berkas' => Berkas::dariBaris($b), 'hak' => (string) $b['hak_bagi']];
            }
        }

        return array_values($hasil);
    }

    public function tambahBagi(int $berkasId, ?int $userId, ?string $lingkup, ?string $token, string $hak, int $olehId, ?string $sampai = null): void
    {
        $this->jalankan(
            'INSERT INTO ' . $this->q($this->t->bagi) . ' (berkas_id, user_id, lingkup, token, hak, dibuat_oleh, dibuat_pada, sampai) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$berkasId, $userId, $lingkup, $token, $hak, $olehId, self::sekarang(), $sampai],
        );
    }

    public function ubahHakBagi(int $bagiId, int $berkasId, string $hak): void
    {
        $this->jalankan('UPDATE ' . $this->q($this->t->bagi) . ' SET hak = ? WHERE id = ? AND berkas_id = ? AND token IS NULL', [$hak, $bagiId, $berkasId]);
    }

    public function hapusBagi(int $bagiId, int $berkasId): void
    {
        $this->jalankan('DELETE FROM ' . $this->q($this->t->bagi) . ' WHERE id = ? AND berkas_id = ?', [$bagiId, $berkasId]);
    }

    /** @return array<string, mixed>|null berbagi publik dengan token ini yang masih berlaku */
    public function token(string $token): ?array
    {
        if (! NamaBerkas::kodeSah($token, NamaBerkas::PANJANG_TOKEN)) {
            return null;
        }

        $b = $this->baris(
            'SELECT * FROM ' . $this->q($this->t->bagi) . ' WHERE token = ? AND (sampai IS NULL OR sampai > ?)',
            [$token, self::sekarang()],
        );

        return $b[0] ?? null;
    }

    // ------------------------------------------------------------------ kuota dan riwayat

    public function kuotaKhusus(int $userId): ?int
    {
        $b = $this->baris('SELECT kuota_mb FROM ' . $this->q($this->t->kuota) . ' WHERE user_id = ?', [$userId]);

        return $b === [] ? null : (int) $b[0]['kuota_mb'];
    }

    public function aturKuota(int $userId, ?int $mb): void
    {
        $this->jalankan('DELETE FROM ' . $this->q($this->t->kuota) . ' WHERE user_id = ?', [$userId]);
        if ($mb !== null) {
            $this->jalankan('INSERT INTO ' . $this->q($this->t->kuota) . ' (user_id, kuota_mb, diubah_pada) VALUES (?, ?, ?)', [$userId, $mb, self::sekarang()]);
        }
    }

    public function catat(?int $berkasId, int $pemilikId, ?int $pelakuId, string $peristiwa, string $keterangan = ''): void
    {
        $this->jalankan(
            'INSERT INTO ' . $this->q($this->t->riwayat) . ' (berkas_id, pemilik_id, pelaku_id, peristiwa, keterangan, waktu) VALUES (?, ?, ?, ?, ?, ?)',
            [$berkasId, $pemilikId, $pelakuId, $peristiwa, $keterangan === '' ? null : mb_substr($keterangan, 0, 255), self::sekarang()],
        );
    }

    /** @return list<array<string, mixed>> */
    public function riwayat(int $pemilikId, int $jumlah = 20): array
    {
        return $this->baris(
            'SELECT * FROM ' . $this->q($this->t->riwayat) . ' WHERE pemilik_id = ? ORDER BY waktu DESC, id DESC LIMIT ' . max(1, min(500, $jumlah)),
            [$pemilikId],
        );
    }

    public static function sekarang(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private static function likeAman(string $kata): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $kata);
    }

    private function q(string $nama): string
    {
        return $this->db->getPlatform()->quoteIdentifier($nama);
    }

    /** @param list<mixed> $parameter */
    private function jalankan(string $sql, array $parameter = []): void
    {
        $this->db->query($sql, $parameter);
    }

    /**
     * @param list<mixed> $parameter
     * @return list<array<string, mixed>>
     */
    private function baris(string $sql, array $parameter = []): array
    {
        $hasil = [];
        foreach ($this->db->query($sql, $parameter) as $b) {
            $hasil[] = is_array($b) ? $b : (array) $b->getArrayCopy();
        }

        return $hasil;
    }

    /**
     * @param list<array<string, mixed>> $baris
     * @return list<Berkas>
     */
    private function berkas(array $baris): array
    {
        return array_map(Berkas::dariBaris(...), $baris);
    }
}
