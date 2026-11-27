<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Berkas\Test;

use AdeMS\Dapur\Asset\Unggahan;
use AdeMS\Dapur\Berkas\Handler\JelajahHandler;
use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\NamaTabel as NamaTabelUser;
use AdeMS\Dapur\User\PenyajiHalaman;
use AdeMS\Dapur\User\Pintu;
use AdeMS\Dapur\User\Sesi;
use AdeMS\Dapur\User\User;
use DateTimeZone;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Penjelajah berkas menandai berkas yang sedang dipakai sebagai foto profil (untuk peringatan buang/pindah). */
final class JelajahFotoProfilTest extends TestCase
{
    private Bantu $b;

    protected function setUp(): void
    {
        $this->b = new Bantu();
    }

    protected function tearDown(): void
    {
        $this->b->hapus();
    }

    public function testBerkasFotoProfilDitandai(): void
    {
        $kelola = $this->b->kelola();
        $ade    = $this->b->user['ade@contoh.test'];
        $dipakai = $kelola->simpanFile($ade, '', null, 'dipakai.png', $this->b->file('a'));
        $lain    = $kelola->simpanFile($ade, '', null, 'lain.png', $this->b->file('b'));

        $penyaji = new class implements PenyajiHalaman {
            /** @var array<string, mixed> */
            public array $data = [];

            public function halaman(string $template, ServerRequestInterface $request, array $data = [], int $status = 200): ResponseInterface
            {
                $this->data = $data;

                return new EmptyResponse($status);
            }
        };

        $diminta = [];
        $handler = new JelajahHandler(
            $kelola,
            new Unggahan($this->b->folder . '/unggah', 10),
            $penyaji,
            new Pintu('dashboard'),
            new DateTimeZone('UTC'),
            'dashboard',
            JelajahHandler::TEMPLATE,
            function (int $userId) use (&$diminta, $dipakai): array {
                $diminta[] = $userId;

                return [$dipakai->kode];
            },
        );

        $user = (new DaftarUser($this->b->db, new NamaTabelUser('uji_')))->cariId($ade);
        self::assertNotNull($user);
        $sesi = new class implements Sesi {
            public function ambil(string $kunci): mixed
            {
                return null;
            }

            public function simpan(string $kunci, mixed $nilai): void
            {
            }

            public function hapus(string $kunci): void
            {
            }

            public function gantiId(): void
            {
            }

            public function hancurkan(): void
            {
            }
        };

        $handler->handle((new ServerRequest([], [], '/berkas', 'GET'))
            ->withAttribute(User::class, $user)
            ->withAttribute(Sesi::class, $sesi));

        $tanda = array_column($penyaji->data['isi'], 'fotoProfil', 'kode');
        self::assertTrue($tanda[$dipakai->kode]);
        self::assertFalse($tanda[$lain->kode]);
        // Daftar foto yang dipakai diambil sekali per pemilik.
        self::assertSame([$ade], $diminta);
    }
}
