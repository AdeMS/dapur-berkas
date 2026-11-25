<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Berkas;

/**
 * Modul berkas: layanan, template 'berkas', dan tabel untuk `php dapur pasang`. Route halaman dipasang oleh modul yang
 * memakainya (mis. dashboard) lewat PabrikLayanan::jelajah/buka/publik, agar alamat dan pintu loginnya mengikuti modul
 * itu. Pengaturan (`'berkas' => [...]`, lihat kelas Pengaturan) sengaja tidak diberi nilai di sini.
 */
final class ConfigProvider
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return [
            'dapur'        => ['skema' => [BerkasSkema::class]],
            'templates'    => ['paths' => ['berkas' => [dirname(__DIR__) . '/templates']]],
            'dependencies' => [
                'factories' => array_fill_keys([
                    Pengaturan::class,
                    NamaTabel::class,
                    DaftarBerkas::class,
                    KelolaBerkas::class,
                    Gambar::class,
                    PabrikLayanan::UNGGAHAN,
                ], PabrikLayanan::class),
            ],
        ];
    }
}
