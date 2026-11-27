<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Berkas;

use AdeMS\Dapur\Asset\Unggahan;
use AdeMS\Dapur\Berkas\Handler\BukaHandler;
use AdeMS\Dapur\Berkas\Handler\JelajahHandler;
use AdeMS\Dapur\Berkas\Handler\PublikHandler;
use AdeMS\Dapur\Database\PabrikPemasang;
use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\FotoUser;
use AdeMS\Dapur\User\PenyimpanFoto;
use AdeMS\Dapur\User\Konteks;
use AdeMS\Dapur\User\PenyajiDasar;
use AdeMS\Dapur\User\PenyajiHalaman;
use AdeMS\Dapur\User\Pengaturan as PengaturanUser;
use AdeMS\Dapur\User\Pintu;
use Laminas\Db\Adapter\Adapter;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Factory layanan modul berkas. Handler halaman dibuat lewat metode statis dengan Pintu (lingkup dan alamat dasar) dari
 * modul yang memasangnya, misalnya dashboard.
 */
final class PabrikLayanan
{
    public const UNGGAHAN = 'berkas.unggahan';

    public function __invoke(ContainerInterface $c, string $nama): object
    {
        return match ($nama) {
            Pengaturan::class   => new Pengaturan(self::config($c)['berkas'] ?? []),
            NamaTabel::class    => new NamaTabel(PabrikPemasang::awalan(self::config($c))),
            DaftarBerkas::class => new DaftarBerkas(self::db($c), $c->get(NamaTabel::class)),
            KelolaBerkas::class => self::kelola($c),
            Gambar::class       => new Gambar($c->get(Pengaturan::class)->folderCache),
            self::UNGGAHAN      => new Unggahan($c->get(Pengaturan::class)->folderSementara, $c->get(Pengaturan::class)->unggahMaks),
            PenyimpanFoto::class => new FotoBerkas(
                $c->get(KelolaBerkas::class),
                $c->get(Pengaturan::class)->folderCache . '/avatar',
                $c->get(FotoUser::class),
                $c->get(PengaturanUser::class)->zonaWaktu,
            ),
            BerkasSkema::class => new BerkasSkema(static function () use ($c): ?string {
                $foto = $c->get(PenyimpanFoto::class);

                return $foto instanceof FotoBerkas ? $foto->pindahkanFotoLama($c->get(DaftarUser::class)->profil()) : null;
            }),
            default             => throw new RuntimeException("Layanan berkas tidak dikenal: {$nama}"),
        };
    }

    public static function jelajah(ContainerInterface $c, Pintu $pintu, ?PenyajiHalaman $penyaji = null, string $template = JelajahHandler::TEMPLATE): JelajahHandler
    {
        return new JelajahHandler(
            $c->get(KelolaBerkas::class),
            $c->get(self::UNGGAHAN),
            $penyaji ?? new PenyajiDasar($c->get(TemplateRendererInterface::class), $pintu),
            $pintu,
            $c->get(PengaturanUser::class)->zonaWaktu,
            $pintu->lingkup,
            $template,
            static function (int $userId) use ($c): array {
                $profil = $c->get(DaftarUser::class)->profil();
                $kode   = [];
                foreach ($profil->semuaFoto() as $f) {
                    if ($f['user_id'] === $userId) {
                        $kode[] = $f['kode'];
                    }
                }

                return $kode;
            },
        );
    }

    public static function buka(ContainerInterface $c, Pintu $pintu): BukaHandler
    {
        return new BukaHandler($c->get(KelolaBerkas::class), $c->get(Gambar::class), $pintu);
    }

    public static function publik(ContainerInterface $c, Pintu $pintu): PublikHandler
    {
        return new PublikHandler($c->get(KelolaBerkas::class), $c->get(Gambar::class), $c->get(TemplateRendererInterface::class), $pintu);
    }

    private static function kelola(ContainerInterface $c): KelolaBerkas
    {
        $user = $c->get(DaftarUser::class);
        assert($user instanceof DaftarUser);

        return new KelolaBerkas(
            $c->get(DaftarBerkas::class),
            $c->get(Pengaturan::class),
            static fn (string $email): ?int => $user->cariEmail($email)?->id,
            static fn (int $id): array => array_column($user->aksesUser($id), 'lingkup'),
            static fn (int $id): string => (string) $user->cariId($id)?->kode,
        );
    }

    /** @return array<string, mixed> */
    private static function config(ContainerInterface $c): array
    {
        $config = $c->has('config') ? $c->get('config') : [];

        return is_array($config) ? $config : [];
    }

    private static function db(ContainerInterface $c): Adapter
    {
        $db = $c->get(Adapter::class);
        assert($db instanceof Adapter);

        return $db;
    }

    /** Lingkup aktif (inti 'dashboard' atau domain website), untuk perintah terminal. */
    public static function lingkup(): string
    {
        return Konteks::lingkup();
    }
}
