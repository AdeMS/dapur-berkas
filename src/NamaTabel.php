<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Berkas;

use InvalidArgumentException;

/** Nama tabel modul berkas, mengikuti awalan tabel instalasi. Tabel user milik adems/dapur-user. */
final class NamaTabel
{
    public readonly string $berkas;
    public readonly string $bagi;
    public readonly string $kuota;
    public readonly string $riwayat;
    public readonly string $user;

    public function __construct(public readonly string $awalan = '')
    {
        if (preg_match('/^[A-Za-z0-9_]{0,32}$/', $awalan) !== 1) {
            throw new InvalidArgumentException('Awalan tabel hanya boleh huruf, angka, dan garis bawah (maksimal 32).');
        }

        $this->berkas  = $awalan . 'berkas';
        $this->bagi    = $awalan . 'berkas_bagi';
        $this->kuota   = $awalan . 'berkas_kuota';
        $this->riwayat = $awalan . 'berkas_riwayat';
        $this->user    = $awalan . 'user';
    }
}
