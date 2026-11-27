<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Berkas\Handler;

use AdeMS\Dapur\Asset\GagalAset;
use AdeMS\Dapur\Asset\Penyimpanan;
use AdeMS\Dapur\Asset\Unggahan;
use AdeMS\Dapur\Berkas\Berkas;
use AdeMS\Dapur\Berkas\DaftarBerkas;
use AdeMS\Dapur\Berkas\GagalBerkas;
use AdeMS\Dapur\Berkas\JenisBerkas;
use AdeMS\Dapur\Berkas\KelolaBerkas;
use AdeMS\Dapur\Berkas\NamaBerkas;
use AdeMS\Dapur\User\PenyajiHalaman;
use AdeMS\Dapur\User\Pintu;
use AdeMS\Dapur\User\Sesi;
use AdeMS\Dapur\User\TokenCsrf;
use AdeMS\Dapur\User\User;
use DateTimeImmutable;
use Closure;
use DateTimeZone;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Penjelajah berkas (/berkas): Berkas Saya dan lokasi alias milik user, Dibagikan dengan saya, Tempat sampah, dan
 * pencarian. Semua tindakan diperiksa ulang di server (KelolaBerkas), tidak hanya disembunyikan di tampilan.
 *
 * Alamat: ?lihat=<lokasi>|bagi|sampah&folder=<kode>&cari=<kata>&tampil=daftar|ikon
 */
final class JelajahHandler implements RequestHandlerInterface
{
    public const TEMPLATE = 'berkas::jelajah';

    public const SESI_PESAN = 'berkas.pesan';
    public const SESI_GALAT = 'berkas.galat';

    /** Kode berkas yang baru dibuang, untuk tombol Batalkan pada pesan berikutnya. */
    public const SESI_URUNG = 'berkas.urung';

    public const LIHAT_BAGI   = 'bagi';
    public const LIHAT_SAMPAH = 'sampah';

    public function __construct(
        private readonly KelolaBerkas $kelola,
        private readonly Unggahan $unggahan,
        private readonly PenyajiHalaman $penyaji,
        private readonly Pintu $pintu,
        private readonly DateTimeZone $zonaWaktu,
        private readonly string $lingkup,
        private readonly string $template = self::TEMPLATE,
        /**
         * Kode foto profil yang sedang dipakai akun (foto akun dan foto per website): fn (int $userId): list<string>.
         * Dipakai untuk memberi peringatan sebelum berkas itu dibuang atau dipindah.
         */
        private readonly ?Closure $fotoDipakai = null,
    ) {
    }

    /** @var array<int, array<string, true>> kode foto profil yang dipakai, per pemilik (sekali per permintaan) */
    private array $fotoProfil = [];

    private function fotoProfil(Berkas $b): bool
    {
        if ($b->folder || $this->fotoDipakai === null) {
            return false;
        }

        $this->fotoProfil[$b->pemilikId] ??= array_fill_keys(($this->fotoDipakai)($b->pemilikId), true);

        return isset($this->fotoProfil[$b->pemilikId][$b->kode]);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(User::class);
        $sesi = $request->getAttribute(Sesi::class);
        assert($user instanceof User && $sesi instanceof Sesi);

        if (! $this->kelola->daftar->tersedia()) {
            return $request->getMethod() === 'POST'
                ? new EmptyResponse(503)
                : $this->penyaji->halaman($this->template, $request, ['belumSiap' => true] + $this->kosong(), 503);
        }

        if ($request->getMethod() === 'POST') {
            return $this->kirim($request, $user, $sesi);
        }

        $q = $request->getQueryParams();
        if (isset($q['daftar_folder'])) {
            return $this->daftarFolder($user, is_string($q['lokasi'] ?? null) ? $q['lokasi'] : '');
        }

        if (is_string($q['bagi'] ?? null)) {
            return $this->infoBagi($user, $q['bagi']);
        }

        // Tempat sampah yang kedaluwarsa dibersihkan sesekali, tanpa perlu tugas terjadwal.
        if (random_int(1, 50) === 1) {
            try {
                $this->kelola->bersihkanKedaluwarsa();
            } catch (Throwable $e) {
                error_log('dapur-berkas: ' . $e->getMessage());
            }
        }

        $pesan = $sesi->ambil(self::SESI_PESAN);
        $galat = $sesi->ambil(self::SESI_GALAT);
        $urung = $sesi->ambil(self::SESI_URUNG);
        $sesi->hapus(self::SESI_PESAN);
        $sesi->hapus(self::SESI_GALAT);
        $sesi->hapus(self::SESI_URUNG);

        return $this->penyaji->halaman($this->template, $request, $this->data($user, $q) + [
            'pesan' => is_string($pesan) ? $pesan : null,
            'galat' => is_string($galat) ? $galat : null,
            'urung' => is_string($pesan) && is_string($urung) ? $urung : null,
        ] + $this->kosong());
    }

    /**
     * @param array<string, mixed> $q
     * @return array<string, mixed>
     */
    private function data(User $user, array $q): array
    {
        $milik  = $this->kelola->lokasiMilik($user->id);
        $lihat  = is_string($q['lihat'] ?? null) ? $q['lihat'] : '';
        $cari   = is_string($q['cari'] ?? null) ? mb_substr(trim($q['cari']), 0, 100) : '';
        $tampil = ($q['tampil'] ?? '') === 'ikon' ? 'ikon' : 'daftar';
        $folder = is_string($q['folder'] ?? null) ? $this->kelola->daftar->kode($q['folder']) : null;

        // Folder milik orang lain hanya bisa dibuka bila dibagikan.
        if ($folder !== null && (! $folder->folder || $folder->dibuang() || ! $this->kelola->bolehBaca($user->id, $folder))) {
            $folder = null;
        }

        if ($folder !== null) {
            $lihat = $folder->pemilikId === $user->id ? $folder->lokasi : self::LIHAT_BAGI;
        } elseif ($lihat !== self::LIHAT_BAGI && $lihat !== self::LIHAT_SAMPAH && ! isset($milik[$lihat])) {
            $lihat = '';
        }

        $data = [
            'lokasiMilik' => $milik,
            'lihat'       => $lihat,
            'cari'        => $cari,
            'tampil'      => $tampil,
            'folder'      => $folder,
            'kuota'       => $this->kuota($user->id),
            'lingkup'     => $this->lingkup,
            'alamatDasar' => $this->pintu->rute('/berkas'),
            'jenisBerkas' => JenisBerkas::teks(),
            'sampahHari'  => $this->kelola->pengaturan->sampahHari,
            'unggahMaks'  => $this->kelola->pengaturan->unggahMaks,
            // Jumlah yang dibuang langsung (isi folder yang ikut dibuang tidak dihitung terpisah).
            'jumlahSampah' => count($this->kelola->daftar->sampah($user->id)),
        ];

        if ($cari !== '') {
            $baris = [];
            foreach ($this->kelola->cari($user->id, $cari) as $h) {
                $baris[] = $this->baris($h['berkas'], $h['hak'], $user->id) + ['jalur' => $h['jalur']];
            }

            return ['mode' => 'cari', 'isi' => $baris, 'jejak' => []] + $data;
        }

        if ($lihat === self::LIHAT_SAMPAH && $folder === null) {
            $baris = [];
            foreach ($this->kelola->daftar->sampah($user->id) as $b) {
                $baris[] = $this->baris($b, KelolaBerkas::HAK_PEMILIK, $user->id) + [
                    'dibuangTeks' => $this->waktu($b->dihapusPada),
                    'asal'        => $this->kelola->judulLokasi($b->lokasi),
                ];
            }

            return ['mode' => 'sampah', 'isi' => $baris, 'jejak' => []] + $data;
        }

        if ($lihat === self::LIHAT_BAGI && $folder === null) {
            $baris = [];
            foreach ($this->kelola->dibagikanKe($user->id) as $h) {
                $baris[] = $this->baris($h['berkas'], $h['hak'], $user->id);
            }

            return ['mode' => 'bagi', 'isi' => $baris, 'jejak' => [], 'bisaTulis' => false] + $data;
        }

        // Isi satu folder (atau akar lokasi milik sendiri).
        $pemilik  = $folder?->pemilikId ?? $user->id;
        $lokasi   = $folder?->lokasi ?? $lihat;
        $hak      = $folder === null ? KelolaBerkas::HAK_PEMILIK : (string) $this->kelola->hak($user->id, $folder);
        $baris    = [];
        foreach ($this->kelola->daftar->isi($pemilik, $lokasi, $folder?->id) as $b) {
            $baris[] = $this->baris($b, $hak === KelolaBerkas::HAK_PEMILIK ? $hak : $hak, $user->id);
        }

        return [
            'mode'       => 'folder',
            'isi'        => $baris,
            'jejak'      => $this->jejak($user->id, $folder, $lokasi),
            'bisaTulis'  => in_array($hak, [KelolaBerkas::HAK_PEMILIK, KelolaBerkas::HAK_TULIS], true),
            'milikSaya'  => $pemilik === $user->id,
            'lokasiAktif' => $lokasi,
        ] + $data;
    }

    /**
     * Jejak folder (breadcrumb). Untuk folder yang dibagikan, dimulai dari folder yang dibagikan.
     *
     * @return list<array{nama: string, kode: ?string, lihat: ?string}>
     */
    private function jejak(int $userId, ?Berkas $folder, string $lokasi): array
    {
        if ($folder === null) {
            return [['nama' => $this->kelola->judulLokasi($lokasi), 'kode' => null, 'lihat' => $lokasi]];
        }

        $rantai = [...$this->kelola->leluhur($folder), $folder];
        if ($folder->pemilikId === $userId) {
            $hasil = [['nama' => $this->kelola->judulLokasi($lokasi), 'kode' => null, 'lihat' => $lokasi]];
            foreach ($rantai as $f) {
                $hasil[] = ['nama' => $f->nama, 'kode' => $f->kode, 'lihat' => null];
            }

            return $hasil;
        }

        $akar  = $this->kelola->akarBagi($userId, $folder);
        $hasil = [['nama' => 'Dibagikan dengan saya', 'kode' => null, 'lihat' => self::LIHAT_BAGI]];
        $mulai = false;
        foreach ($rantai as $f) {
            $mulai = $mulai || ($akar !== null && $f->id === $akar->id);
            if ($mulai) {
                $hasil[] = ['nama' => $f->nama, 'kode' => $f->kode, 'lihat' => null];
            }
        }

        return $hasil;
    }

    /** @return array<string, mixed> */
    private function baris(Berkas $b, string $hak, int $userId): array
    {
        $alamat = $this->pintu->rute('/berkas/' . $b->kode . ($b->folder ? '' : '/' . rawurlencode($b->nama)));

        return [
            'kode'       => $b->kode,
            'nama'       => $b->nama,
            'folder'     => $b->folder,
            'sistem'     => $b->sistem,
            'ukuranTeks' => $b->folder ? '' : Penyimpanan::ukuranTeks($b->ukuran),
            'diubahTeks' => $this->waktu($b->diubahPada ?? $b->dibuatPada),
            'ikon'       => $b->folder ? 'folder-fill' : JenisBerkas::ikon($b->nama),
            'gambar'     => ! $b->folder && JenisBerkas::gambar($b->nama),
            'pratinjau'  => $b->folder ? null : self::jenisPratinjau($b->nama),
            'alamat'     => $alamat,
            'alamatKecil' => ! $b->folder && JenisBerkas::gambar($b->nama) ? $alamat . '?kecil=1' : null,
            'alamatUnduh' => $b->folder ? null : $alamat . '?unduh=1',
            'hak'        => $hak,
            'milikSaya'  => $b->pemilikId === $userId,
            'dibagikan'  => $b->pemilikId === $userId && $this->kelola->daftar->bagiBerkas($b->id) !== [],
            'fotoProfil' => $this->fotoProfil($b),
        ];
    }

    private static function jenisPratinjau(string $nama): ?string
    {
        $jenis = (string) JenisBerkas::contentType($nama);
        if (! JenisBerkas::dibukaLangsung($nama)) {
            return null;
        }

        return match (true) {
            str_starts_with($jenis, 'image/') => 'gambar',
            str_starts_with($jenis, 'video/') => 'video',
            str_starts_with($jenis, 'audio/') => 'audio',
            $jenis === 'application/pdf'      => 'pdf',
            default                           => 'teks',
        };
    }

    /** @return array{pakai: int, batas: int, pakaiTeks: string, batasTeks: string, persen: int} */
    private function kuota(int $userId): array
    {
        $k = $this->kelola->kuota($userId);

        return $k + [
            'pakaiTeks' => Penyimpanan::ukuranTeks($k['pakai']),
            'batasTeks' => $k['batas'] > 0 ? Penyimpanan::ukuranTeks($k['batas']) : 'tanpa batas',
            'persen'    => $k['batas'] > 0 ? (int) min(100, round($k['pakai'] / $k['batas'] * 100)) : 0,
        ];
    }

    private function waktu(?string $utc): string
    {
        if ($utc === null) {
            return '';
        }

        try {
            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($this->zonaWaktu)->format('Y-m-d H:i');
        } catch (\Exception) {
            return $utc;
        }
    }

    /** JSON: semua folder milik user di satu lokasi, untuk pilihan "Pindah ke". */
    private function daftarFolder(User $user, string $lokasi): ResponseInterface
    {
        if (! isset($this->kelola->lokasiMilik($user->id)[$lokasi])) {
            return new JsonResponse(['folder' => []], 404);
        }

        $hasil = [];
        $jalan = function (?int $induk, ?string $kodeInduk, string $awalan) use (&$jalan, &$hasil, $user, $lokasi): void {
            foreach ($this->kelola->daftar->isi($user->id, $lokasi, $induk) as $b) {
                if ($b->folder && count($hasil) < 2000) {
                    $hasil[] = ['kode' => $b->kode, 'nama' => $b->nama, 'induk' => $kodeInduk, 'jalur' => $awalan . $b->nama];
                    $jalan($b->id, $b->kode, $awalan . $b->nama . ' / ');
                }
            }
        };
        $jalan(null, null, '');

        return new JsonResponse(['folder' => $hasil, 'akar' => $this->kelola->judulLokasi($lokasi)], 200, ['Cache-Control' => 'no-store']);
    }

    /** JSON: daftar berbagi satu berkas (hanya pemilik). */
    private function infoBagi(User $user, string $kode): ResponseInterface
    {
        $b = $this->kelola->daftar->kode($kode);
        if ($b === null || $b->pemilikId !== $user->id || $b->dibuang()) {
            return new JsonResponse(['bagi' => []], 404);
        }

        $hasil = [];
        foreach ($this->kelola->daftar->bagiBerkas($b->id) as $bagi) {
            $hasil[] = [
                'id'     => (int) $bagi['id'],
                'jenis'  => $bagi['token'] !== null ? 'tautan' : ($bagi['user_id'] !== null ? 'user' : 'lingkup'),
                'tujuan' => $bagi['token'] !== null ? $this->alamatPublik((string) $bagi['token'], $b) : (string) ($bagi['email'] ?? $bagi['lingkup'] ?? ''),
                'nama'   => (string) ($bagi['nama_user'] ?? ''),
                'hak'    => (string) $bagi['hak'],
                'sampai' => $bagi['sampai'] === null ? null : $this->waktu((string) $bagi['sampai']),
            ];
        }

        return new JsonResponse(['bagi' => $hasil, 'nama' => $b->nama], 200, ['Cache-Control' => 'no-store']);
    }

    private function alamatPublik(string $token, Berkas $b): string
    {
        return $this->pintu->rute('/b/' . $token . ($b->folder ? '' : '/' . NamaBerkas::rapi($b->nama)));
    }

    private function kirim(ServerRequestInterface $request, User $user, Sesi $sesi): ResponseInterface
    {
        $isi  = (array) $request->getParsedBody();
        $aksi = is_string($isi['aksi'] ?? null) ? $isi['aksi'] : '';
        $json = str_starts_with($aksi, 'unggah_') || ($isi['json'] ?? '') === '1';

        if (! (new TokenCsrf($sesi))->sah($isi[TokenCsrf::FIELD] ?? null)) {
            return $json ? new JsonResponse(['galat' => 'Halaman sudah kedaluwarsa. Muat ulang halaman.'], 400) : new EmptyResponse(400);
        }

        if (str_starts_with($aksi, 'unggah_')) {
            return $this->unggah($request, $user, $aksi, $isi);
        }

        $teks    = static fn (string $k): string => is_string($isi[$k] ?? null) ? trim($isi[$k]) : '';
        $berkas  = $this->kelola->daftar->kode($teks('kode'));
        $kembali = is_string($isi['kembali'] ?? null) && str_starts_with($isi['kembali'], '?') ? $isi['kembali'] : '';
        $hasil   = [];
        $urung   = null;

        try {
            $pesan = match ($aksi) {
                'buat_folder' => (function () use ($user, $teks): string {
                    $f = $this->kelola->buatFolder($user->id, $teks('lokasi'), $this->folderDari($teks('folder')), $teks('nama'));

                    return "Folder \"{$f->nama}\" dibuat.";
                })(),
                'ganti_nama' => (function () use ($user, $berkas, $teks): string {
                    $b = $this->kelola->gantiNama($user->id, $this->ada($berkas), $teks('nama'));

                    return "Nama diganti menjadi \"{$b->nama}\".";
                })(),
                'pindah' => (function () use ($user, $berkas, $teks): string {
                    $tujuan = $teks('tujuan') === '' ? null : $this->folderDari($teks('tujuan'));
                    $b      = $this->kelola->pindah($user->id, $this->ada($berkas), $tujuan);

                    return "\"{$b->nama}\" dipindah ke " . ($tujuan?->nama ?? $this->kelola->judulLokasi($b->lokasi)) . '.';
                })(),
                'buang' => (function () use ($user, $berkas, &$urung): string {
                    $b = $this->ada($berkas);
                    $this->kelola->buang($user->id, $b);
                    $urung = $b->kode;

                    return "\"{$b->nama}\" dipindah ke tempat sampah.";
                })(),
                'pulihkan' => (function () use ($user, $berkas): string {
                    $b = $this->kelola->pulihkan($user->id, $this->ada($berkas));

                    return "\"{$b->nama}\" dikembalikan.";
                })(),
                'hapus_permanen' => (function () use ($user, $berkas): string {
                    $b = $this->ada($berkas);
                    $this->kelola->hapusPermanen($user->id, $b);

                    return "\"{$b->nama}\" dihapus permanen.";
                })(),
                'kosongkan_sampah' => sprintf('Tempat sampah dikosongkan (%d berkas).', $this->kelola->kosongkanSampah($user->id)),
                'bagi_email' => (function () use ($user, $berkas, $teks): string {
                    $email = $this->kelola->bagikanKeEmail($user->id, $this->ada($berkas), $teks('email'), $teks('hak'));

                    return "Dibagikan ke {$email}.";
                })(),
                'bagi_lingkup' => (function () use ($user, $berkas, $teks): string {
                    $this->kelola->bagikanKeLingkup($user->id, $this->ada($berkas), $this->lingkup, $teks('hak'));

                    return 'Dibagikan ke semua anggota website ini.';
                })(),
                'tautan' => (function () use ($user, $berkas, $teks, &$hasil): string {
                    $b     = $this->ada($berkas);
                    $hari  = ctype_digit($teks('hari')) && (int) $teks('hari') > 0 ? (int) $teks('hari') : null;
                    $token = $this->kelola->buatTautan($user->id, $b, $hari);
                    $hasil = ['alamat' => $this->alamatPublik($token, $b)];

                    return 'Tautan publik dibuat.';
                })(),
                'ubah_hak' => (function () use ($user, $berkas, $teks): string {
                    $this->kelola->ubahHakBagi($user->id, $this->ada($berkas), (int) $teks('bagi_id'), $teks('hak'));

                    return $teks('hak') === KelolaBerkas::HAK_TULIS ? 'Hak diubah menjadi boleh menulis.' : 'Hak diubah menjadi hanya baca.';
                })(),
                'cabut_bagi' => (function () use ($user, $berkas, $teks): string {
                    $this->kelola->cabutBagi($user->id, $this->ada($berkas), (int) $teks('bagi_id'));

                    return 'Berbagi dicabut.';
                })(),
                default => throw new GagalBerkas('Aksi tidak dikenal.'),
            };
        } catch (GagalBerkas $e) {
            if ($json) {
                return new JsonResponse(['galat' => $e->getMessage()], 422);
            }
            $sesi->simpan(self::SESI_GALAT, $e->getMessage());

            return new RedirectResponse($this->pintu->rute('/berkas') . $kembali);
        }

        // Sesudah membuang, pesan di halaman berikutnya membawa tombol Batalkan (juga bila dibuang dari jendela
        // pratinjau lewat JSON, karena halaman dimuat ulang saat jendela itu ditutup).
        if ($urung !== null) {
            $sesi->simpan(self::SESI_PESAN, $pesan);
            $sesi->simpan(self::SESI_URUNG, $urung);
        }

        if ($json) {
            return new JsonResponse(['pesan' => $pesan] + $hasil);
        }

        $sesi->simpan(self::SESI_PESAN, $pesan);

        return new RedirectResponse($this->pintu->rute('/berkas') . $kembali);
    }

    private function ada(?Berkas $b): Berkas
    {
        return $b ?? throw new GagalBerkas('Berkas tidak ditemukan.');
    }

    private function folderDari(string $kode): ?Berkas
    {
        if ($kode === '') {
            return null;
        }

        $f = $this->kelola->daftar->kode($kode);
        if ($f === null || ! $f->folder) {
            throw new GagalBerkas('Folder tidak ditemukan.');
        }

        return $f;
    }

    /**
     * Unggah bertahap (dari JavaScript halaman ini): unggah_mulai, unggah_potongan, unggah_selesai, unggah_batal.
     *
     * @param array<string, mixed> $isi
     */
    private function unggah(ServerRequestInterface $request, User $user, string $aksi, array $isi): ResponseInterface
    {
        $kode = is_string($isi['kode_unggah'] ?? null) ? $isi['kode_unggah'] : '';
        try {
            switch ($aksi) {
                case 'unggah_mulai':
                    $lokasi = (string) ($isi['lokasi'] ?? '');
                    $folder = $this->folderDari((string) ($isi['folder'] ?? ''));
                    $file   = str_replace('\\', '/', (string) ($isi['file'] ?? ''));
                    $nama   = basename($file);
                    $ukuran = (int) ($isi['ukuran'] ?? -1);
                    if ($nama === '' || ! JenisBerkas::diizinkan(NamaBerkas::bersihkan($nama))) {
                        throw new GagalBerkas("Jenis berkas tidak diizinkan: {$nama}.");
                    }

                    // Periksa hak dan kuota sebelum mulai mengirim.
                    $tujuan = $folder ?? null;
                    if ($tujuan !== null && ! $this->kelola->bolehTulis($user->id, $tujuan)) {
                        throw new GagalBerkas('Anda tidak punya hak menulis di folder ini.');
                    }
                    if ($tujuan === null) {
                        $this->kelola->akarFisik($user->id, $lokasi);
                    }
                    $this->kelola->periksaKuota($tujuan?->pemilikId ?? $user->id, $tujuan?->lokasi ?? $lokasi, $ukuran);

                    return new JsonResponse(['kode' => $this->unggahan->mulai($nama, $ukuran, $user->id, [
                        'lokasi'    => $lokasi,
                        'folder'    => (string) ($isi['folder'] ?? ''),
                        'subfolder' => trim(dirname('/' . $file), '/'),
                    ])]);

                case 'unggah_potongan':
                    $potongan = $request->getUploadedFiles()['potongan'] ?? null;
                    if (! $potongan instanceof UploadedFileInterface || $potongan->getError() !== UPLOAD_ERR_OK) {
                        throw new GagalBerkas('Potongan unggahan tidak diterima server.');
                    }

                    return new JsonResponse(['diterima' => $this->unggahan->potongan($kode, $user->id, (int) ($isi['nomor'] ?? -1), (string) $potongan->getStream())]);

                case 'unggah_selesai':
                    $hasil = $this->unggahan->selesai($kode, $user->id);
                    try {
                        $d      = $hasil['data'];
                        $folder = $this->folderDari((string) ($d['folder'] ?? ''));
                        if (($d['subfolder'] ?? '') !== '') {
                            $folder = $this->kelola->folderBertingkat($user->id, (string) $d['lokasi'], $folder, (string) $d['subfolder']);
                        }
                        $b = $this->kelola->simpanFile($user->id, (string) $d['lokasi'], $folder, $hasil['nama'], $hasil['file']);
                    } finally {
                        $this->unggahan->buang($kode);
                    }

                    return new JsonResponse(['pesan' => $b->nama === $hasil['nama'] ? "{$b->nama} diunggah." : "Diunggah sebagai {$b->nama}."]);

                case 'unggah_batal':
                    $this->unggahan->buang($kode);

                    return new JsonResponse(['pesan' => 'Unggahan dibatalkan.']);
            }

            throw new GagalBerkas('Aksi tidak dikenal.');
        } catch (GagalBerkas | GagalAset $e) {
            if ($aksi !== 'unggah_mulai') {
                $this->unggahan->buang($kode);
            }

            return new JsonResponse(['galat' => $e->getMessage()], 422);
        }
    }

    /** @return array<string, mixed> */
    private function kosong(): array
    {
        return [
            'belumSiap'   => false,
            'mode'        => 'folder',
            'isi'         => [],
            'jejak'       => [],
            'lokasiMilik' => [],
            'lihat'       => '',
            'cari'        => '',
            'tampil'      => 'daftar',
            'folder'      => null,
            'kuota'       => null,
            'bisaTulis'   => false,
            'milikSaya'   => true,
            'lokasiAktif' => '',
            'pesan'       => null,
            'galat'       => null,
            'urung'       => null,
            'lingkup'     => $this->lingkup,
            'alamatDasar' => $this->pintu->rute('/berkas'),
            'jenisBerkas' => JenisBerkas::teks(),
            'ukuranPotongan' => Unggahan::POTONGAN,
            'unggahMaks'  => 0,
            'sampahHari'  => 30,
            'jumlahSampah' => 0,
            'zonaWaktu'   => $this->zonaWaktu->getName(),
        ];
    }
}
