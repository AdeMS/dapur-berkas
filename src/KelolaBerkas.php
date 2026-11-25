<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Berkas;

use AdeMS\Dapur\Asset\Penyimpanan;
use Closure;

/**
 * Aturan berkas: siapa boleh apa, kuota, nama, tempat sampah, berbagi, dan file fisiknya.
 *
 * File fisik disimpan dengan nama asli dan susunan folder yang sama seperti di website, di akar lokasinya:
 * <folder berkas>/<kode akun>/ untuk lokasi bawaan, atau path alias untuk lokasi tambahan. Database tetap acuannya:
 * file yang ditaruh langsung di disk tidak tampil di website. Berkas yang dibuang dipindah ke <akar>/.sampah/<nomor>.
 */
final class KelolaBerkas
{
    public const HAK_BACA    = 'baca';
    public const HAK_TULIS   = 'tulis';
    public const HAK_PEMILIK = 'pemilik';

    private const PERINGKAT = [self::HAK_BACA => 1, self::HAK_TULIS => 2, self::HAK_PEMILIK => 3];

    /** Folder tempat sampah di akar tiap lokasi. */
    public const FOLDER_SAMPAH = '.sampah';

    /** @var array<int, list<string>> */
    private array $lingkupUser = [];

    /** @var array<string, ?int> */
    private array $pemilikAlias = [];

    /** @var array<int, string> */
    private array $kodeUser = [];

    /**
     * @param Closure(string): ?int         $cariUserEmail email -> nomor user (untuk pemilik alias dan berbagi)
     * @param Closure(int): list<string>    $lingkupMilik  nomor user -> lingkup yang bisa diaksesnya (untuk berbagi ke website)
     * @param Closure(int): string          $kodeAkun      nomor user -> kode acak akun (nama folder di lokasi bawaan)
     */
    public function __construct(
        public readonly DaftarBerkas $daftar,
        public readonly Pengaturan $pengaturan,
        private readonly Closure $cariUserEmail,
        private readonly Closure $lingkupMilik,
        private readonly Closure $kodeAkun,
    ) {
    }

    // ------------------------------------------------------------------ lokasi

    /**
     * Lokasi yang dimiliki user: lokasi bawaan ('') dan alias yang pemiliknya user ini.
     *
     * @return array<string, string> lokasi => judul
     */
    public function lokasiMilik(int $userId): array
    {
        $hasil = ['' => 'Berkas Saya'];
        foreach ($this->pengaturan->lokasi as $alias => $l) {
            if ($this->pemilikAlias($alias) === $userId) {
                $hasil[$alias] = $l['judul'];
            }
        }

        return $hasil;
    }

    public function pemilikAlias(string $alias): ?int
    {
        if (! array_key_exists($alias, $this->pemilikAlias)) {
            $l = $this->pengaturan->lokasi[$alias] ?? null;
            $this->pemilikAlias[$alias] = $l === null ? null : ($this->cariUserEmail)($l['pemilik']);
        }

        return $this->pemilikAlias[$alias];
    }

    public function judulLokasi(string $lokasi): string
    {
        return $lokasi === '' ? 'Berkas Saya' : ($this->pengaturan->lokasi[$lokasi]['judul'] ?? $lokasi);
    }

    /** Folder fisik akar lokasi untuk pemilik ini. */
    public function akarFisik(int $pemilikId, string $lokasi): string
    {
        if ($lokasi === '') {
            // Folder per akun memakai kode acak akun, bukan nomornya, agar tidak bisa ditebak.
            $kode = $this->kodeUser[$pemilikId] ??= ($this->kodeAkun)($pemilikId);
            if (preg_match('/^[0-9A-Za-z]{22}$/', $kode) !== 1) {
                throw new GagalBerkas('Kode akun belum dibuat. Jalankan php dapur pasang dari folder inti Dapur.');
            }

            return $this->pengaturan->folder . '/' . $kode;
        }

        $l = $this->pengaturan->lokasi[$lokasi] ?? throw new GagalBerkas("Lokasi {$lokasi} tidak dikenal.");
        if ($this->pemilikAlias($lokasi) !== $pemilikId) {
            throw new GagalBerkas("Lokasi {$lokasi} bukan milik akun ini.");
        }

        return $l['path'];
    }

    /** Jalur fisik berkas di disk (juga untuk berkas di tempat sampah). */
    public function jalurFisik(Berkas $b): string
    {
        $nama = [];
        $node = $b;
        while (true) {
            if ($node->sampahJalur !== null) {
                return $this->akarFisik($b->pemilikId, $b->lokasi) . '/' . $node->sampahJalur . ($nama === [] ? '' : '/' . implode('/', array_reverse($nama)));
            }

            $nama[] = $node->nama;
            if ($node->indukId === null) {
                break;
            }

            $node = $this->daftar->id($node->indukId) ?? throw new GagalBerkas('Folder induk tidak ditemukan.');
        }

        return $this->akarFisik($b->pemilikId, $b->lokasi) . '/' . implode('/', array_reverse($nama));
    }

    /** @return list<Berkas> folder dari akar sampai induk langsung */
    public function leluhur(Berkas $b): array
    {
        $hasil = [];
        $id    = $b->indukId;
        while ($id !== null && count($hasil) < 100) {
            $induk = $this->daftar->id($id);
            if ($induk === null) {
                break;
            }
            array_unshift($hasil, $induk);
            $id = $induk->indukId;
        }

        return $hasil;
    }

    // ------------------------------------------------------------------ akses

    /** Hak user atas berkas: 'pemilik', 'tulis', 'baca', atau null. Berbagi pada folder berlaku untuk isinya. */
    public function hak(int $userId, Berkas $b): ?string
    {
        if ($b->pemilikId === $userId) {
            return self::HAK_PEMILIK;
        }

        if ($b->dibuang()) {
            return null;
        }

        $rantai  = [$b->id, ...array_map(static fn (Berkas $l): int => $l->id, $this->leluhur($b))];
        $lingkup = $this->lingkupUser($userId);
        $hak     = null;
        foreach ($this->daftar->bagiUntuk($rantai) as $bagi) {
            $cocok = ($bagi['user_id'] !== null && (int) $bagi['user_id'] === $userId)
                || ($bagi['lingkup'] !== null && in_array($bagi['lingkup'], $lingkup, true));
            if ($cocok && ($hak === null || self::PERINGKAT[$bagi['hak']] > self::PERINGKAT[$hak])) {
                $hak = $bagi['hak'] === self::HAK_TULIS ? self::HAK_TULIS : self::HAK_BACA;
            }
        }

        return $hak;
    }

    public function bolehBaca(int $userId, Berkas $b): bool
    {
        return $this->hak($userId, $b) !== null;
    }

    public function bolehTulis(int $userId, Berkas $b): bool
    {
        return in_array($this->hak($userId, $b), [self::HAK_TULIS, self::HAK_PEMILIK], true);
    }

    /** @return list<string> */
    private function lingkupUser(int $userId): array
    {
        return $this->lingkupUser[$userId] ??= ($this->lingkupMilik)($userId);
    }

    /**
     * Folder yang boleh dibuka dan isinya ditulis: folder milik sendiri atau folder bersama berhak tulis.
     * Mengembalikan [pemilik, lokasi, folder] tujuan.
     *
     * @return array{int, string, ?Berkas}
     */
    private function tujuanTulis(int $userId, string $lokasi, ?Berkas $folder): array
    {
        if ($folder === null) {
            $this->akarFisik($userId, $lokasi); // memastikan lokasi milik user

            return [$userId, $lokasi, null];
        }

        if (! $folder->folder || $folder->dibuang()) {
            throw new GagalBerkas('Folder tujuan tidak ditemukan.');
        }

        if (! $this->bolehTulis($userId, $folder)) {
            throw new GagalBerkas('Anda tidak punya hak menulis di folder ini.');
        }

        return [$folder->pemilikId, $folder->lokasi, $folder];
    }

    // ------------------------------------------------------------------ kuota

    /** Batas kuota lokasi bawaan dalam byte; 0 = tanpa batas. */
    public function batasKuota(int $userId): int
    {
        $khusus = $this->daftar->kuotaKhusus($userId);

        return $khusus === null ? $this->pengaturan->kuota : max(0, $khusus) * 1024 * 1024;
    }

    /** @return array{pakai: int, batas: int} */
    public function kuota(int $userId): array
    {
        return ['pakai' => $this->daftar->pemakaian($userId), 'batas' => $this->batasKuota($userId)];
    }

    /** Memastikan ukuran tambahan masih muat di kuota pemilik (hanya lokasi bawaan). */
    public function periksaKuota(int $pemilikId, string $lokasi, int $tambahan): void
    {
        if ($lokasi !== '') {
            return;
        }

        $batas = $this->batasKuota($pemilikId);
        if ($batas > 0 && $this->daftar->pemakaian($pemilikId) + $tambahan > $batas) {
            throw new GagalBerkas(sprintf(
                'Kuota penyimpanan tidak cukup (terpakai %s dari %s). Kosongkan tempat sampah atau hapus berkas lain.',
                Penyimpanan::ukuranTeks($this->daftar->pemakaian($pemilikId)),
                Penyimpanan::ukuranTeks($batas),
            ));
        }
    }

    // ------------------------------------------------------------------ folder dan file

    public function buatFolder(int $userId, string $lokasi, ?Berkas $induk, string $nama): Berkas
    {
        [$pemilik, $lokasi, $induk] = $this->tujuanTulis($userId, $lokasi, $induk);
        $nama = NamaBerkas::bersihkan($nama);
        if ($this->daftar->namaDipakai($pemilik, $lokasi, $induk?->id, $nama)) {
            throw new GagalBerkas("Sudah ada \"{$nama}\" di folder ini.");
        }

        $berkas = $this->daftar->tambah($pemilik, $lokasi, $induk?->id, true, $nama, 0, null);
        try {
            self::buatFolderFisik($this->jalurFisik($berkas));
        } catch (GagalBerkas $e) {
            $this->daftar->hapus([$berkas->id]);
            throw $e;
        }

        $this->daftar->catat($berkas->id, $pemilik, $userId, 'buat_folder', $this->jalurTampil($berkas));

        return $berkas;
    }

    /**
     * Folder di dalam folder (dibuat bila belum ada), untuk unggah folder: "Liburan/Pantai" -> folder Pantai.
     */
    public function folderBertingkat(int $userId, string $lokasi, ?Berkas $induk, string $jalur): ?Berkas
    {
        foreach (array_filter(explode('/', str_replace('\\', '/', $jalur)), static fn (string $b): bool => trim($b) !== '' && $b !== '.' && $b !== '..') as $bagian) {
            [$pemilik, $lok] = $this->tujuanTulis($userId, $lokasi, $induk);
            $nama = NamaBerkas::bersihkan($bagian);
            $ada  = null;
            foreach ($this->daftar->isi($pemilik, $lok, $induk?->id) as $b) {
                if ($b->folder && mb_strtolower($b->nama) === mb_strtolower($nama)) {
                    $ada = $b;
                    break;
                }
            }
            $induk = $ada ?? $this->buatFolder($userId, $lokasi, $induk, $nama);
        }

        return $induk;
    }

    /**
     * Menyimpan file unggahan. Bila nama sudah ada, berkas baru diberi nama "nama (2).ext" (berkas lama tidak ditimpa).
     */
    public function simpanFile(int $userId, string $lokasi, ?Berkas $induk, string $namaFile, string $fileSementara): Berkas
    {
        [$pemilik, $lokasi, $induk] = $this->tujuanTulis($userId, $lokasi, $induk);
        $nama = NamaBerkas::bersihkan($namaFile);
        if (! JenisBerkas::diizinkan($nama)) {
            throw new GagalBerkas("Jenis berkas tidak diizinkan: {$nama}.");
        }

        if (! is_file($fileSementara)) {
            throw new GagalBerkas('File unggahan tidak ditemukan.');
        }

        $ukuran = (int) filesize($fileSementara);
        $this->periksaKuota($pemilik, $lokasi, $ukuran);

        for ($n = 2, $calon = $nama; $this->daftar->namaDipakai($pemilik, $lokasi, $induk?->id, $calon); $n++) {
            $calon = NamaBerkas::berikutnya($nama, $n, false);
        }

        $berkas = $this->daftar->tambah($pemilik, $lokasi, $induk?->id, false, $calon, $ukuran, JenisBerkas::contentType($calon));
        try {
            $tujuan = $this->jalurFisik($berkas);
            self::buatFolderFisik(dirname($tujuan));
            if (file_exists($tujuan) || ! @copy($fileSementara, $tujuan)) {
                throw new GagalBerkas(file_exists($tujuan)
                    ? "Sudah ada file \"{$calon}\" di disk yang tidak tercatat di website. Pindahkan file itu dulu lewat NAS."
                    : 'Berkas tidak bisa disimpan. Pastikan folder penyimpanan bisa ditulis web server.');
            }
            @chmod($tujuan, Penyimpanan::IZIN_FILE);
        } catch (GagalBerkas $e) {
            $this->daftar->hapus([$berkas->id]);
            throw $e;
        }

        $this->daftar->catat($berkas->id, $pemilik, $userId, 'unggah', $this->jalurTampil($berkas));

        return $berkas;
    }

    public function gantiNama(int $userId, Berkas $b, string $baru): Berkas
    {
        if ($b->dibuang() || ! $this->bolehTulis($userId, $b)) {
            throw new GagalBerkas('Anda tidak punya hak mengubah berkas ini.');
        }

        if ($b->sistem !== null) {
            throw new GagalBerkas('Folder sistem tidak bisa diganti namanya.');
        }

        $baru = NamaBerkas::bersihkan($baru);
        if (! $b->folder && ! JenisBerkas::diizinkan($baru)) {
            throw new GagalBerkas("Jenis berkas tidak diizinkan: {$baru}.");
        }

        if ($baru === $b->nama) {
            return $b;
        }

        if ($this->daftar->namaDipakai($b->pemilikId, $b->lokasi, $b->indukId, $baru, $b->id)) {
            throw new GagalBerkas("Sudah ada \"{$baru}\" di folder ini.");
        }

        $lama = $this->jalurFisik($b);
        $ke   = dirname($lama) . '/' . $baru;
        if (file_exists($ke) && mb_strtolower($ke) !== mb_strtolower($lama)) {
            throw new GagalBerkas("Sudah ada \"{$baru}\" di disk yang tidak tercatat di website.");
        }

        if (file_exists($lama) && ! @rename($lama, $ke)) {
            throw new GagalBerkas('Nama tidak bisa diganti di disk.');
        }

        $this->daftar->perbarui($b->id, $baru, $b->indukId);
        $hasil = $this->daftar->id($b->id) ?? $b;
        $this->daftar->catat($b->id, $b->pemilikId, $userId, 'ganti_nama', $b->nama . ' -> ' . $baru);

        return $hasil;
    }

    /** Memindah berkas ke folder lain milik pemilik yang sama dan di lokasi yang sama (null = akar lokasi). */
    public function pindah(int $userId, Berkas $b, ?Berkas $tujuan): Berkas
    {
        if ($b->pemilikId !== $userId || $b->dibuang()) {
            throw new GagalBerkas('Hanya pemilik yang bisa memindah berkas.');
        }

        if ($b->sistem !== null) {
            throw new GagalBerkas('Folder sistem tidak bisa dipindah.');
        }

        if ($tujuan !== null) {
            if (! $tujuan->folder || $tujuan->dibuang() || $tujuan->pemilikId !== $b->pemilikId || $tujuan->lokasi !== $b->lokasi) {
                throw new GagalBerkas('Folder tujuan tidak sah.');
            }

            if ($tujuan->id === $b->id || in_array($b->id, array_map(static fn (Berkas $l): int => $l->id, $this->leluhur($tujuan)), true)) {
                throw new GagalBerkas('Folder tidak bisa dipindah ke dalam dirinya sendiri.');
            }
        }

        if ($tujuan?->id === $b->indukId) {
            return $b;
        }

        if ($this->daftar->namaDipakai($b->pemilikId, $b->lokasi, $tujuan?->id, $b->nama)) {
            throw new GagalBerkas("Sudah ada \"{$b->nama}\" di folder tujuan.");
        }

        $lama = $this->jalurFisik($b);
        $ke   = ($tujuan === null ? $this->akarFisik($b->pemilikId, $b->lokasi) : $this->jalurFisik($tujuan)) . '/' . $b->nama;
        if (file_exists($ke)) {
            throw new GagalBerkas("Sudah ada \"{$b->nama}\" di disk folder tujuan yang tidak tercatat di website.");
        }

        self::buatFolderFisik(dirname($ke));
        if (file_exists($lama) && ! @rename($lama, $ke)) {
            throw new GagalBerkas('Berkas tidak bisa dipindah di disk.');
        }

        $this->daftar->perbarui($b->id, $b->nama, $tujuan?->id);
        $this->daftar->catat($b->id, $b->pemilikId, $userId, 'pindah', $this->jalurTampil($this->daftar->id($b->id) ?? $b));

        return $this->daftar->id($b->id) ?? $b;
    }

    // ------------------------------------------------------------------ tempat sampah

    public function buang(int $userId, Berkas $b): void
    {
        if ($b->dibuang() || ! $this->bolehTulis($userId, $b)) {
            throw new GagalBerkas('Anda tidak punya hak membuang berkas ini.');
        }

        if ($b->sistem !== null) {
            throw new GagalBerkas('Folder sistem tidak bisa dibuang.');
        }

        $akar  = $this->akarFisik($b->pemilikId, $b->lokasi);
        $lama  = $this->jalurFisik($b);
        $jalur = self::FOLDER_SAMPAH . '/' . $b->id;
        self::buatFolderFisik($akar . '/' . self::FOLDER_SAMPAH);
        if (file_exists($lama) && ! @rename($lama, $akar . '/' . $jalur)) {
            throw new GagalBerkas('Berkas tidak bisa dipindah ke tempat sampah.');
        }

        $waktu = DaftarBerkas::sekarang();
        $this->daftar->tandaiBuang([$b->id, ...$this->keturunan($b, false)], $waktu);
        $this->daftar->aturSampah($b->id, $jalur, $b->indukId);
        $this->daftar->catat($b->id, $b->pemilikId, $userId, 'buang', $this->jalurTampil($b));
    }

    /** Mengembalikan berkas dari tempat sampah ke folder asalnya (atau ke akar bila folder asal sudah tidak ada). */
    public function pulihkan(int $userId, Berkas $b): Berkas
    {
        if ($b->pemilikId !== $userId || $b->sampahJalur === null) {
            throw new GagalBerkas('Berkas tidak ada di tempat sampah Anda.');
        }

        $induk = $b->indukId === null ? null : $this->daftar->id($b->indukId);
        if ($induk !== null && $induk->dibuang()) {
            $induk = null;
        }

        if (! $b->folder) {
            $this->periksaKuota($b->pemilikId, $b->lokasi, 0);
        }

        $nama = $b->nama;
        for ($n = 2; $this->daftar->namaDipakai($b->pemilikId, $b->lokasi, $induk?->id, $nama); $n++) {
            $nama = NamaBerkas::berikutnya($b->nama, $n, $b->folder);
        }

        $dari = $this->jalurFisik($b);
        $ke   = ($induk === null ? $this->akarFisik($b->pemilikId, $b->lokasi) : $this->jalurFisik($induk)) . '/' . $nama;
        if (file_exists($ke)) {
            throw new GagalBerkas("Sudah ada \"{$nama}\" di disk yang tidak tercatat di website.");
        }

        self::buatFolderFisik(dirname($ke));
        if (file_exists($dari) && ! @rename($dari, $ke)) {
            throw new GagalBerkas('Berkas tidak bisa dikembalikan dari tempat sampah.');
        }

        $this->daftar->aturSampah($b->id, null, $induk?->id, $nama);
        $this->daftar->batalBuang($this->keturunan($b, true));
        $this->daftar->catat($b->id, $b->pemilikId, $userId, 'pulihkan', $nama);

        return $this->daftar->id($b->id) ?? $b;
    }

    public function hapusPermanen(int $userId, Berkas $b): void
    {
        if ($b->pemilikId !== $userId || $b->sampahJalur === null) {
            throw new GagalBerkas('Hanya berkas di tempat sampah Anda yang bisa dihapus permanen.');
        }

        $this->hapusFisikDanData($b);
        $this->daftar->catat(null, $b->pemilikId, $userId, 'hapus', $b->nama);
    }

    /** @return int jumlah berkas yang dihapus */
    public function kosongkanSampah(int $userId): int
    {
        $sampah = $this->daftar->sampah($userId);
        foreach ($sampah as $b) {
            $this->hapusFisikDanData($b);
        }

        if ($sampah !== []) {
            $this->daftar->catat(null, $userId, $userId, 'hapus', 'tempat sampah dikosongkan (' . count($sampah) . ' berkas)');
        }

        return count($sampah);
    }

    /** Menghapus permanen isi tempat sampah yang lebih tua dari batas umur (semua user). @return int jumlah */
    public function bersihkanKedaluwarsa(): int
    {
        $batas = gmdate('Y-m-d H:i:s', time() - $this->pengaturan->sampahHari * 86400);
        $hapus = 0;
        foreach ($this->daftar->sampahKedaluwarsa($batas) as $b) {
            try {
                $this->hapusFisikDanData($b);
                $this->daftar->catat(null, $b->pemilikId, null, 'hapus', $b->nama . ' (otomatis, lewat ' . $this->pengaturan->sampahHari . ' hari)');
                $hapus++;
            } catch (GagalBerkas $e) {
                error_log('dapur-berkas: sampah tidak bisa dibersihkan: ' . $e->getMessage());
            }
        }

        return $hapus;
    }

    private function hapusFisikDanData(Berkas $b): void
    {
        $jalur = $this->jalurFisik($b);
        if (file_exists($jalur) || is_link($jalur)) {
            try {
                Penyimpanan::hapusFisik($jalur);
            } catch (\AdeMS\Dapur\Asset\GagalAset $e) {
                throw new GagalBerkas($e->getMessage());
            }
        }

        $this->daftar->hapus([$b->id, ...$this->keturunan($b, false)]);
    }

    /**
     * Semua keturunan folder. Dengan $berhentiDiSampah, keturunan yang dibuang sendiri (punya jalur sampah) dilewati.
     *
     * @return list<int>
     */
    private function keturunan(Berkas $b, bool $berhentiDiSampah): array
    {
        if (! $b->folder) {
            return [];
        }

        $hasil = [];
        foreach ($this->daftar->anak($b->id) as $anak) {
            if ($berhentiDiSampah && $anak->sampahJalur !== null) {
                continue;
            }
            $hasil[] = $anak->id;
            array_push($hasil, ...$this->keturunan($anak, $berhentiDiSampah));
        }

        return $hasil;
    }

    // ------------------------------------------------------------------ berbagi

    public function bagikanKeEmail(int $userId, Berkas $b, string $email, string $hak): string
    {
        $this->harusPemilik($userId, $b);
        $tujuan = ($this->cariUserEmail)(strtolower(trim($email)));
        if ($tujuan === null) {
            throw new GagalBerkas('Tidak ada akun dengan email itu.');
        }

        if ($tujuan === $userId) {
            throw new GagalBerkas('Berkas ini sudah milik Anda.');
        }

        foreach ($this->daftar->bagiBerkas($b->id) as $bagi) {
            if ($bagi['user_id'] !== null && (int) $bagi['user_id'] === $tujuan) {
                $this->daftar->hapusBagi((int) $bagi['id'], $b->id);
            }
        }

        $this->daftar->tambahBagi($b->id, $tujuan, null, null, self::hakSah($hak), $userId);
        $this->daftar->catat($b->id, $b->pemilikId, $userId, 'bagi', strtolower(trim($email)) . ' (' . self::hakSah($hak) . ')');

        return strtolower(trim($email));
    }

    public function bagikanKeLingkup(int $userId, Berkas $b, string $lingkup, string $hak): void
    {
        $this->harusPemilik($userId, $b);
        foreach ($this->daftar->bagiBerkas($b->id) as $bagi) {
            if ($bagi['lingkup'] === $lingkup) {
                $this->daftar->hapusBagi((int) $bagi['id'], $b->id);
            }
        }

        $this->daftar->tambahBagi($b->id, null, $lingkup, null, self::hakSah($hak), $userId);
        $this->daftar->catat($b->id, $b->pemilikId, $userId, 'bagi', 'anggota ' . $lingkup . ' (' . self::hakSah($hak) . ')');
    }

    /** Tautan publik (hanya baca); mengembalikan token. $hari null = tanpa batas waktu. */
    public function buatTautan(int $userId, Berkas $b, ?int $hari = null): string
    {
        $this->harusPemilik($userId, $b);
        $token  = NamaBerkas::kode(NamaBerkas::PANJANG_TOKEN);
        $sampai = $hari === null ? null : gmdate('Y-m-d H:i:s', time() + max(1, $hari) * 86400);
        $this->daftar->tambahBagi($b->id, null, null, $token, self::HAK_BACA, $userId, $sampai);
        $this->daftar->catat($b->id, $b->pemilikId, $userId, 'tautan', $hari === null ? 'tanpa batas waktu' : "berlaku {$hari} hari");

        return $token;
    }

    public function cabutBagi(int $userId, Berkas $b, int $bagiId): void
    {
        $this->harusPemilik($userId, $b);
        $ada = null;
        foreach ($this->daftar->bagiBerkas($b->id) as $bagi) {
            if ((int) $bagi['id'] === $bagiId) {
                $ada = $bagi;
            }
        }

        if ($ada === null) {
            throw new GagalBerkas('Berbagi tidak ditemukan.');
        }

        $this->daftar->hapusBagi($bagiId, $b->id);
        $this->daftar->catat($b->id, $b->pemilikId, $userId, $ada['token'] !== null ? 'cabut_tautan' : 'cabut_bagi', (string) ($ada['email'] ?? $ada['lingkup'] ?? ''));
    }

    private function harusPemilik(int $userId, Berkas $b): void
    {
        if ($b->pemilikId !== $userId || $b->dibuang()) {
            throw new GagalBerkas('Hanya pemilik yang bisa mengatur berbagi berkas ini.');
        }
    }

    private static function hakSah(string $hak): string
    {
        return $hak === self::HAK_TULIS ? self::HAK_TULIS : self::HAK_BACA;
    }

    // ------------------------------------------------------------------ dibagikan dan pencarian

    /** @return list<array{berkas: Berkas, hak: string}> */
    public function dibagikanKe(int $userId): array
    {
        return $this->daftar->dibagikanKe($userId, $this->lingkupUser($userId));
    }

    /**
     * Mencari nama berkas di berkas milik sendiri (semua lokasi) dan yang dibagikan ke user ini.
     *
     * @return list<array{berkas: Berkas, hak: string, jalur: string}>
     */
    public function cari(int $userId, string $kata, int $jumlah = 100): array
    {
        $kata = trim($kata);
        if ($kata === '') {
            return [];
        }

        $hasil = [];
        foreach ($this->daftar->cariMilik($userId, $kata, $jumlah) as $b) {
            $hasil[] = ['berkas' => $b, 'hak' => self::HAK_PEMILIK, 'jalur' => $this->jalurTampil($b, $userId)];
        }

        foreach ($this->daftar->cariSemua($kata, $userId) as $b) {
            if (count($hasil) >= $jumlah) {
                break;
            }
            $hak = $this->hak($userId, $b);
            if ($hak !== null) {
                $hasil[] = ['berkas' => $b, 'hak' => $hak, 'jalur' => $this->jalurTampil($b, $userId)];
            }
        }

        return $hasil;
    }

    /**
     * Jalur folder untuk ditampilkan, mis. "Berkas Saya / Liburan / Pantai.jpg". Untuk user lain (berkas yang dibagikan),
     * hanya mulai dari folder yang dibagikan, agar susunan folder pemilik di atasnya tidak terlihat.
     */
    public function jalurTampil(Berkas $b, ?int $userId = null): string
    {
        $rantai = [...$this->leluhur($b), $b];

        if ($userId !== null && $userId !== $b->pemilikId) {
            $akarBagi = $this->akarBagi($userId, $b);
            $mulai    = count($rantai) - 1;
            foreach ($rantai as $i => $node) {
                if ($akarBagi !== null && $node->id === $akarBagi->id) {
                    $mulai = $i;
                }
            }

            return implode(' / ', array_map(static fn (Berkas $x): string => $x->nama, array_slice($rantai, $mulai)));
        }

        return $this->judulLokasi($b->lokasi) . ' / ' . implode(' / ', array_map(static fn (Berkas $x): string => $x->nama, $rantai));
    }

    /** Folder (atau berkas) teratas yang dibagikan ke user ini dalam rantai berkas $b. */
    public function akarBagi(int $userId, Berkas $b): ?Berkas
    {
        $lingkup = $this->lingkupUser($userId);
        $rantai  = [...$this->leluhur($b), $b];
        $ids     = [];
        foreach ($this->daftar->bagiUntuk(array_map(static fn (Berkas $x): int => $x->id, $rantai)) as $bagi) {
            if (($bagi['user_id'] !== null && (int) $bagi['user_id'] === $userId) || ($bagi['lingkup'] !== null && in_array($bagi['lingkup'], $lingkup, true))) {
                $ids[(int) $bagi['berkas_id']] = true;
            }
        }

        foreach ($rantai as $node) {
            if (isset($ids[$node->id])) {
                return $node;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ disk

    public static function buatFolderFisik(string $folder): void
    {
        if (is_dir($folder)) {
            return;
        }

        $dibuat = [];
        for ($f = $folder; ! is_dir($f) && $f !== dirname($f); $f = dirname($f)) {
            $dibuat[] = $f;
        }

        foreach (array_reverse($dibuat) as $f) {
            if (! @mkdir($f) && ! is_dir($f)) {
                throw new GagalBerkas('Folder penyimpanan tidak bisa dibuat. Pastikan folder berkas bisa ditulis web server.');
            }
            @chmod($f, Penyimpanan::IZIN_FOLDER);
        }
    }
}
