<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Berkas;

use AdeMS\Dapur\User\FotoUser;
use AdeMS\Dapur\User\PenyimpanFoto;
use AdeMS\Dapur\User\ProfilUser;
use RuntimeException;

/**
 * Foto profil sebagai berkas (adems/dapur-user 2.7). Foto asli disimpan apa adanya di folder sistem "Foto Profil" di
 * Berkas Saya milik akun, sehingga ikut kuota, tampil di penjelajah berkas, dan menjadi riwayat foto yang bisa dipilih
 * lagi. Avatar persegi 256 piksel (WebP, tanpa EXIF) dibuat dari foto asli dan disimpan sebagai cache di
 * `<folder cache>/avatar/`; bila cache hilang, avatar dibuat ulang.
 *
 * Kode foto = kode berkas (22 huruf/angka). Foto lama dari FotoUser (kode 32 heksadesimal di data/upload/avatar) tetap
 * dilayani sampai dipindah oleh `php dapur pasang` (langkah '1.1-foto-profil'). Selama tabel berkas belum dibuat,
 * semua pekerjaan diteruskan ke FotoUser seperti sebelumnya.
 */
final class FotoBerkas implements PenyimpanFoto
{
    /** Penanda folder sistem di kolom berkas.sistem. */
    public const SISTEM = 'foto_profil';

    public const NAMA_FOLDER = 'Foto Profil';

    private const AKHIRAN = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(
        private readonly KelolaBerkas $kelola,
        private readonly string $folderAvatar,
        private readonly FotoUser $lama,
        private readonly \DateTimeZone $zonaWaktu = new \DateTimeZone('UTC'),
    ) {
    }

    private function aktif(): bool
    {
        return $this->kelola->daftar->tersedia();
    }

    public function siap(): bool
    {
        if (! $this->aktif()) {
            return $this->lama->siap();
        }

        // Folder yang belum ada dibuat otomatis, jadi yang diperiksa folder terdekat yang sudah ada.
        $folder = $this->kelola->pengaturan->folder;
        while (! is_dir($folder) && $folder !== dirname($folder)) {
            $folder = dirname($folder);
        }

        return FotoUser::didukung() && is_writable($folder);
    }

    public function simpan(int $userId, string $fileUnggahan): string
    {
        if (! $this->aktif()) {
            return $this->lama->simpan($userId, $fileUnggahan);
        }

        $jenis = FotoUser::periksa($fileUnggahan);
        $nama  = 'Foto profil ' . (new \DateTimeImmutable('now', $this->zonaWaktu))->format('Y-m-d H.i.s') . '.' . self::AKHIRAN[$jenis];

        try {
            $folder = $this->kelola->folderSistem($userId, self::SISTEM, self::NAMA_FOLDER);
            $berkas = $this->kelola->simpanFile($userId, '', $folder, $nama, $fileUnggahan);
        } catch (GagalBerkas $e) {
            throw new RuntimeException('Foto tidak bisa disimpan: ' . $e->getMessage(), 0, $e);
        }

        $this->avatar($berkas);

        return $berkas->kode;
    }

    public function file(string $kode): ?string
    {
        if (FotoUser::kodeLama($kode)) {
            return $this->lama->file($kode);
        }

        $berkas = $this->foto($kode);

        return $berkas === null ? null : $this->avatar($berkas);
    }

    public function lepas(string $kode): void
    {
        // Foto berkas tetap di riwayat foto; hanya foto lama di data/upload/avatar yang dihapus.
        if (FotoUser::kodeLama($kode)) {
            $this->lama->lepas($kode);
        }
    }

    public function pemilik(string $kode): ?int
    {
        return $this->foto($kode)?->pemilikId;
    }

    public function adaRiwayat(): bool
    {
        return $this->aktif();
    }

    public function riwayat(int $userId): array
    {
        if (! $this->aktif()) {
            return [];
        }

        $folder = $this->kelola->daftar->sistem($userId, '', self::SISTEM);
        if ($folder === null) {
            return [];
        }

        $foto = array_values(array_filter(
            $this->kelola->daftar->isi($userId, '', $folder->id),
            static fn (Berkas $b): bool => ! $b->folder && self::gambar($b),
        ));
        // Terbaru dulu; foto yang diunggah pada detik yang sama diurutkan menurut nomor berkasnya.
        usort($foto, static fn (Berkas $x, Berkas $y): int => [$y->dibuatPada, $y->id] <=> [$x->dibuatPada, $x->id]);

        return array_map(static fn (Berkas $b): array => ['kode' => $b->kode, 'nama' => $b->nama, 'waktu' => $b->dibuatPada], $foto);
    }

    public function buang(int $userId, string $kode): void
    {
        $berkas = $this->foto($kode);
        if ($berkas === null || $berkas->pemilikId !== $userId) {
            throw new RuntimeException('Foto itu tidak ditemukan di riwayat foto Anda.');
        }

        try {
            $this->kelola->buang($userId, $berkas);
        } catch (GagalBerkas $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        @unlink($this->fileAvatar($kode));
    }

    /**
     * Memindah foto lama (FotoUser, data/upload/avatar) ke folder Foto Profil masing-masing akun, lalu mengganti kode
     * fotonya di akun dan profil website. File lama tidak dihapus, agar aman bila pemasangan dibatalkan; sesudahnya
     * folder data/upload/avatar boleh dihapus. Untuk langkah '1.1-foto-profil' perintah `php dapur pasang`.
     *
     * @return string|null keterangan hasil, null bila tidak ada yang dipindah
     */
    public function pindahkanFotoLama(ProfilUser $profil): ?string
    {
        if (! $this->aktif() || ! $profil->tersedia()) {
            return null;
        }

        $baru   = [];
        $akun   = [];
        $hilang = 0;
        foreach ($profil->semuaFoto() as $f) {
            if (! FotoUser::kodeLama($f['kode'])) {
                continue;
            }

            $kunci = $f['user_id'] . ':' . $f['kode'];
            if (! isset($baru[$kunci])) {
                $file = $this->lama->file($f['kode']);
                if ($file === null) {
                    $hilang++;
                    continue;
                }

                $folder       = $this->kelola->folderSistem($f['user_id'], self::SISTEM, self::NAMA_FOLDER);
                $berkas       = $this->kelola->simpanFile($f['user_id'], '', $folder, 'Foto profil lama.webp', $file, false);
                $baru[$kunci] = $berkas->kode;
                $profil->gantiKodeFoto($f['user_id'], $f['kode'], $berkas->kode);
                $akun[$f['user_id']] = true;
            }
        }

        if ($baru === [] && $hilang === 0) {
            return null;
        }

        return sprintf('foto profil lama dipindah ke folder Foto Profil: %d foto dari %d akun', count($baru), count($akun))
            . ($hilang > 0 ? ", {$hilang} foto tidak ditemukan" : '')
            . '; folder data/upload/avatar boleh dihapus';
    }

    /** Berkas foto di folder Foto Profil (bukan folder, belum dibuang), atau null. */
    private function foto(string $kode): ?Berkas
    {
        if (! $this->aktif() || FotoUser::kodeLama($kode) || ! FotoUser::kodeSah($kode)) {
            return null;
        }

        $b = $this->kelola->daftar->kode($kode);
        if ($b === null || $b->folder || $b->dibuang() || $b->lokasi !== '' || $b->indukId === null || ! self::gambar($b)) {
            return null;
        }

        $induk = $this->kelola->daftar->id($b->indukId);

        return $induk !== null && $induk->sistem === self::SISTEM && ! $induk->dibuang() ? $b : null;
    }

    /** Avatar dari foto asli: dibuat bila belum ada atau bila foto aslinya lebih baru. */
    private function avatar(Berkas $b): ?string
    {
        $avatar = $this->fileAvatar($b->kode);
        try {
            $asli = $this->kelola->jalurFisik($b);
        } catch (GagalBerkas) {
            return null;
        }

        if (! is_file($asli)) {
            return null;
        }

        if (is_file($avatar) && filemtime($avatar) >= filemtime($asli)) {
            return $avatar;
        }

        $jenis = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($asli);
        if (! isset(self::AKHIRAN[$jenis]) || ! self::folderAvatarSiap($this->folderAvatar)) {
            return null;
        }

        try {
            FotoUser::buatAvatar($asli, $jenis, $avatar);
        } catch (RuntimeException) {
            return null;
        }

        return $avatar;
    }

    private function fileAvatar(string $kode): string
    {
        return $this->folderAvatar . '/' . $kode . '.webp';
    }

    private static function gambar(Berkas $b): bool
    {
        return in_array(strtolower(pathinfo($b->nama, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true);
    }

    private static function folderAvatarSiap(string $folder): bool
    {
        if (! is_dir($folder) && @mkdir($folder, 02770, true)) {
            @chmod($folder, 02770);
        }

        return is_dir($folder) && is_writable($folder);
    }
}
