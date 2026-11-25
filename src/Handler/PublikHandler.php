<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Berkas\Handler;

use AdeMS\Dapur\Asset\Http\KirimFile;
use AdeMS\Dapur\Asset\Penyimpanan;
use AdeMS\Dapur\Berkas\Berkas;
use AdeMS\Dapur\Berkas\Gambar;
use AdeMS\Dapur\Berkas\GagalBerkas;
use AdeMS\Dapur\Berkas\JenisBerkas;
use AdeMS\Dapur\Berkas\KelolaBerkas;
use AdeMS\Dapur\Berkas\NamaBerkas;
use AdeMS\Dapur\User\Pintu;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\TextResponse;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Tautan publik tanpa login: /b/<token>[/<nama-rapi>] untuk berkas, /b/<token> untuk folder (daftar isi), dan
 * /b/<token>/f/<kode>/<nama-rapi> untuk berkas di dalam folder yang dibagikan. Token adalah kuncinya; susunan folder
 * pemilik di atas folder yang dibagikan tidak pernah terlihat. Foto JPEG dikirim tanpa data EXIF (lokasi GPS).
 */
final class PublikHandler implements RequestHandlerInterface
{
    public const TEMPLATE = 'berkas::publik';

    public function __construct(
        private readonly KelolaBerkas $kelola,
        private readonly Gambar $gambar,
        private readonly TemplateRendererInterface $renderer,
        private readonly Pintu $pintu,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (! $this->kelola->daftar->tersedia()) {
            return self::tidakAda(); // tabel belum dibuat (php dapur pasang)
        }

        $token = (string) $request->getAttribute('token', '');
        $bagi  = $this->kelola->daftar->token($token);
        $akar  = $bagi === null ? null : $this->kelola->daftar->id((int) $bagi['berkas_id']);
        if ($akar === null || $akar->dibuang()) {
            return self::tidakAda();
        }

        $sisa = explode('/', trim((string) $request->getAttribute('sisa', ''), '/'));
        if (! $akar->folder) {
            return $this->kirim($request, $akar);
        }

        // Berkas di dalam folder yang dibagikan: /f/<kode>/...
        if (($sisa[0] ?? '') === 'f' && isset($sisa[1])) {
            $b = $this->kelola->daftar->kode($sisa[1]);
            if ($b === null || $b->folder || ! $this->diDalam($akar, $b)) {
                return self::tidakAda();
            }

            return $this->kirim($request, $b);
        }

        $folder = $akar;
        $minta  = $request->getQueryParams()['folder'] ?? null;
        if (is_string($minta)) {
            $f = $this->kelola->daftar->kode($minta);
            if ($f === null || ! $f->folder || ! $this->diDalam($akar, $f)) {
                return self::tidakAda();
            }
            $folder = $f;
        }

        $dasar = $this->pintu->rute('/b/' . $token);
        $isi   = [];
        foreach ($this->kelola->daftar->isi($folder->pemilikId, $folder->lokasi, $folder->id) as $b) {
            $isi[] = [
                'nama'       => $b->nama,
                'folder'     => $b->folder,
                'ikon'       => $b->folder ? 'folder-fill' : JenisBerkas::ikon($b->nama),
                'ukuranTeks' => $b->folder ? '' : Penyimpanan::ukuranTeks($b->ukuran),
                'alamat'     => $b->folder ? $dasar . '?folder=' . $b->kode : $dasar . '/f/' . $b->kode . '/' . NamaBerkas::rapi($b->nama),
            ];
        }

        $jejak = [];
        $mulai = false;
        foreach ([...$this->kelola->leluhur($folder), $folder] as $f) {
            $mulai = $mulai || $f->id === $akar->id;
            if ($mulai) {
                $jejak[] = ['nama' => $f->nama, 'alamat' => $f->id === $akar->id ? $dasar : $dasar . '?folder=' . $f->kode];
            }
        }

        $html = $this->renderer->render(self::TEMPLATE, ['judul' => $folder->nama, 'isi' => $isi, 'jejak' => $jejak]);

        return new HtmlResponse($html, 200, ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex', 'Referrer-Policy' => 'no-referrer']);
    }

    private function diDalam(Berkas $akar, Berkas $b): bool
    {
        if ($b->dibuang() || $b->pemilikId !== $akar->pemilikId) {
            return false;
        }

        foreach ($this->kelola->leluhur($b) as $l) {
            if ($l->id === $akar->id) {
                return true;
            }
        }

        return false;
    }

    private function kirim(ServerRequestInterface $request, Berkas $b): ResponseInterface
    {
        try {
            $file = $this->kelola->jalurFisik($b);
        } catch (GagalBerkas) {
            return self::tidakAda();
        }

        if (! is_file($file)) {
            return self::tidakAda();
        }

        $unduh = isset($request->getQueryParams()['unduh']) || ! JenisBerkas::dibukaLangsung($b->nama);
        $kirim = JenisBerkas::gambar($b->nama) ? $this->gambar->tanpaExif($file, $b->kode) : $file;

        return KirimFile::kirim(
            $request,
            $kirim,
            (string) JenisBerkas::contentType($b->nama),
            'private, max-age=300',
            $unduh ? $b->nama : null,
            $unduh ? null : $b->nama,
        )->withHeader('X-Robots-Tag', 'noindex')->withHeader('Referrer-Policy', 'no-referrer');
    }

    private static function tidakAda(): ResponseInterface
    {
        return new TextResponse('Tautan tidak ditemukan atau sudah tidak berlaku.', 404, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
