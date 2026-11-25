<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Berkas\Handler;

use AdeMS\Dapur\Asset\Http\KirimFile;
use AdeMS\Dapur\Berkas\Gambar;
use AdeMS\Dapur\Berkas\GagalBerkas;
use AdeMS\Dapur\Berkas\JenisBerkas;
use AdeMS\Dapur\Berkas\KelolaBerkas;
use AdeMS\Dapur\User\Pintu;
use AdeMS\Dapur\User\User;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Membuka berkas lewat kodenya: /berkas/<kode>[/<nama>] (nama hanya pemanis alamat). Akses diperiksa setiap kali.
 *
 * - folder: diarahkan ke penjelajah;
 * - ?unduh=1: selalu diunduh;
 * - ?kecil=1: gambar kecil (thumbnail) untuk tampilan ikon;
 * - selain itu: dibuka di browser bila jenisnya aman (gambar, pdf, audio, video, teks), selain itu diunduh.
 */
final class BukaHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly KelolaBerkas $kelola,
        private readonly Gambar $gambar,
        private readonly Pintu $pintu,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (! $this->kelola->daftar->tersedia()) {
            return self::tidakAda(); // tabel belum dibuat (php dapur pasang)
        }

        $user = $request->getAttribute(User::class);
        assert($user instanceof User);

        $b = $this->kelola->daftar->kode((string) $request->getAttribute('kode', ''));
        if ($b === null || ! $this->kelola->bolehBaca($user->id, $b)) {
            return self::tidakAda();
        }

        if ($b->folder) {
            return new RedirectResponse($this->pintu->rute('/berkas') . '?folder=' . $b->kode);
        }

        try {
            $file = $this->kelola->jalurFisik($b);
        } catch (GagalBerkas) {
            return self::tidakAda();
        }

        if (! is_file($file)) {
            error_log("dapur-berkas: file berkas {$b->kode} tidak ada di disk");

            return self::tidakAda();
        }

        $q = $request->getQueryParams();
        if (isset($q['kecil']) && JenisBerkas::gambar($b->nama)) {
            $kecil = $this->gambar->kecil($file, $b->kode);
            if ($kecil !== null) {
                return KirimFile::kirim($request, $kecil, 'image/webp', 'private, max-age=86400');
            }
        }

        $unduh = isset($q['unduh']) || ! JenisBerkas::dibukaLangsung($b->nama);

        return KirimFile::kirim(
            $request,
            $file,
            (string) JenisBerkas::contentType($b->nama),
            'private, max-age=3600',
            $unduh ? $b->nama : null,
            $unduh ? null : $b->nama,
        )->withHeader('X-Robots-Tag', 'noindex');
    }

    private static function tidakAda(): ResponseInterface
    {
        return new TextResponse('Berkas tidak ditemukan.', 404, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
