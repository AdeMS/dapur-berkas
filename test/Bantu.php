<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Berkas\Test;

use AdeMS\Dapur\Berkas\BerkasSkema;
use AdeMS\Dapur\Berkas\DaftarBerkas;
use AdeMS\Dapur\Berkas\KelolaBerkas;
use AdeMS\Dapur\Berkas\NamaTabel;
use AdeMS\Dapur\Berkas\Pengaturan;
use AdeMS\Dapur\Database\KonteksSkema;
use AdeMS\Dapur\User\UserSkema;
use Laminas\Db\Adapter\Adapter;

/** Lingkungan uji: folder sementara + SQLite di memori dengan tabel user dan berkas. */
final class Bantu
{
    public readonly string $folder;
    public readonly Adapter $db;
    public readonly DaftarBerkas $daftar;

    /** @var array<string, int> email => nomor user */
    public array $user = [];

    /** @var array<int, list<string>> */
    public array $lingkup = [];

    /** @var array<string, mixed> */
    public array $config = [];

    public function __construct()
    {
        $this->folder = sys_get_temp_dir() . '/dapur-berkas-uji-' . bin2hex(random_bytes(6));
        mkdir($this->folder, 0777, true);

        $this->db = new Adapter(['driver' => 'Pdo_Sqlite', 'database' => ':memory:']);
        $k        = new KonteksSkema($this->db, 'uji_');
        foreach ([new UserSkema(), new BerkasSkema()] as $skema) {
            foreach ($skema->tabel($k) as $sql) {
                $k->jalankan($sql);
            }
            foreach ($skema->indeks($k) as $sql) {
                $k->jalankan($sql);
            }
        }

        foreach (['ade@contoh.test', 'budi@contoh.test', 'cici@contoh.test'] as $email) {
            $k->jalankan('INSERT INTO uji_user (email, nama, kata_sandi, aktif, dibuat_pada) VALUES (?, ?, ?, 1, ?)', [$email, $email, 'x', '2026-01-01 00:00:00']);
            $this->user[$email] = (int) $this->db->getDriver()->getLastGeneratedValue();
        }

        $this->daftar = new DaftarBerkas($this->db, new NamaTabel('uji_'));
    }

    public function kelola(): KelolaBerkas
    {
        return new KelolaBerkas(
            $this->daftar,
            new Pengaturan($this->config, $this->folder),
            fn (string $email): ?int => $this->user[$email] ?? null,
            fn (int $id): array => $this->lingkup[$id] ?? [],
            fn (int $id): string => $this->kode($id),
        );
    }

    /** Kode akun uji: tetap per nomor user. */
    public function kode(int $id): string
    {
        return str_pad('Kode' . $id, 22, 'x');
    }

    public function file(string $isi = 'isi'): string
    {
        $f = $this->folder . '/tmp-' . bin2hex(random_bytes(4));
        file_put_contents($f, $isi);

        return $f;
    }

    public function hapus(): void
    {
        exec('rm -rf ' . escapeshellarg($this->folder));
    }
}
