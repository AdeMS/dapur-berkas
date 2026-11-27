<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Berkas\Test;

use AdeMS\Dapur\Berkas\FotoBerkas;
use AdeMS\Dapur\Berkas\GagalBerkas;
use AdeMS\Dapur\Berkas\KelolaBerkas;
use AdeMS\Dapur\User\FotoUser;
use AdeMS\Dapur\User\NamaTabel as NamaTabelUser;
use AdeMS\Dapur\User\ProfilUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FotoBerkasTest extends TestCase
{
    private Bantu $b;
    private KelolaBerkas $kelola;
    private FotoUser $lama;
    private FotoBerkas $foto;
    private int $ade;

    protected function setUp(): void
    {
        if (! FotoUser::didukung()) {
            self::markTestSkipped('GD dengan WebP tidak tersedia.');
        }

        $this->b      = new Bantu();
        $this->kelola = $this->b->kelola();
        $this->lama   = new FotoUser($this->b->folder . '/upload/avatar');
        $this->foto   = new FotoBerkas($this->kelola, $this->b->folder . '/cache/avatar', $this->lama);
        $this->ade    = $this->b->user['ade@contoh.test'];
    }

    protected function tearDown(): void
    {
        if (isset($this->b)) {
            $this->b->hapus();
        }
    }

    public function testFotoDisimpanDiFolderSistemDenganRiwayat(): void
    {
        self::assertTrue($this->foto->siap());
        self::assertTrue($this->foto->adaRiwayat());

        $kode = $this->foto->simpan($this->ade, $this->gambar(600, 300));
        self::assertMatchesRegularExpression('/^[0-9A-Za-z]{22}$/', $kode);
        self::assertTrue(FotoUser::kodeSah($kode));

        // Foto asli tersimpan apa adanya di folder sistem "Foto Profil" Berkas Saya.
        $folder = $this->b->daftar->sistem($this->ade, '', FotoBerkas::SISTEM);
        self::assertNotNull($folder);
        self::assertSame('Foto Profil', $folder->nama);
        $berkas = $this->b->daftar->kode($kode);
        self::assertNotNull($berkas);
        self::assertSame($folder->id, $berkas->indukId);
        self::assertStringStartsWith('Foto profil ', $berkas->nama);
        self::assertStringEndsWith('.png', $berkas->nama);
        self::assertSame(600, getimagesize($this->kelola->jalurFisik($berkas))[0]);

        // Avatar persegi 256 piksel WebP.
        $avatar = $this->foto->file($kode);
        self::assertNotNull($avatar);
        self::assertSame([256, 256, IMAGETYPE_WEBP], array_slice(getimagesize($avatar), 0, 3));
        self::assertSame($this->ade, $this->foto->pemilik($kode));

        // Foto kedua: riwayat terbaru dulu; foto lama tetap ada setelah dilepas.
        $kode2 = $this->foto->simpan($this->ade, $this->gambar(100, 100));
        $this->foto->lepas($kode);
        self::assertSame([$kode2, $kode], array_column($this->foto->riwayat($this->ade), 'kode'));
        self::assertNotNull($this->foto->file($kode));

        // Folder sistem tidak bisa dibuang.
        $this->expectException(GagalBerkas::class);
        $this->kelola->buang($this->ade, $folder);
    }

    public function testBuangKeTempatSampah(): void
    {
        $kode = $this->foto->simpan($this->ade, $this->gambar(80, 80));
        $this->foto->buang($this->ade, $kode);

        self::assertSame([], $this->foto->riwayat($this->ade));
        self::assertNull($this->foto->file($kode));
        self::assertNull($this->foto->pemilik($kode));
        self::assertCount(1, $this->b->daftar->sampah($this->ade));

        // Akun lain tidak bisa membuang foto milik orang lain.
        $kode2 = $this->foto->simpan($this->ade, $this->gambar(80, 80));
        $this->expectException(RuntimeException::class);
        $this->foto->buang($this->b->user['budi@contoh.test'], $kode2);
    }

    public function testNamaFolderBentrokDanKuota(): void
    {
        $this->kelola->buatFolder($this->ade, '', null, 'Foto Profil');
        $kode = $this->foto->simpan($this->ade, $this->gambar(80, 80));
        $folder = $this->b->daftar->sistem($this->ade, '', FotoBerkas::SISTEM);
        self::assertSame('Foto Profil (2)', $folder?->nama);
        self::assertSame($this->ade, $this->foto->pemilik($kode));

        // Berkas biasa di luar folder Foto Profil bukan foto profil.
        $biasa = $this->kelola->simpanFile($this->ade, '', null, 'lain.png', $this->gambar(50, 50));
        self::assertNull($this->foto->pemilik($biasa->kode));
        self::assertNull($this->foto->file($biasa->kode));

        // Kuota penuh: foto baru ditolak dengan pesan kuota.
        $this->b->daftar->aturKuota($this->ade, 1);
        $this->kelola->simpanFile($this->ade, '', null, 'besar.txt', $this->b->file(str_repeat('x', 1100000)), false);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Kuota');
        $this->foto->simpan($this->ade, $this->gambar(300, 300));
    }

    public function testFotoLamaDipindahLewatPasang(): void
    {
        $profil = new ProfilUser($this->b->db, new NamaTabelUser('uji_'));
        $lama   = $this->lama->simpan($this->ade, $this->gambar(120, 120));
        $this->b->db->query('UPDATE uji_user SET foto = ? WHERE id = ?', [$lama, $this->ade]);
        $profil->simpan($this->ade, 'toko.test', [ProfilUser::FOTO => $lama]);
        $budi = $this->b->user['budi@contoh.test'];
        $this->b->db->query('UPDATE uji_user SET foto = ? WHERE id = ?', [str_repeat('a', 32), $budi]);

        // Sebelum dipindah, foto lama tetap dilayani.
        self::assertNotNull($this->foto->file($lama));

        $hasil = $this->foto->pindahkanFotoLama($profil);
        self::assertStringContainsString('1 foto dari 1 akun', (string) $hasil);
        self::assertStringContainsString('1 foto tidak ditemukan', (string) $hasil);

        $baru = $profil->fotoAkun()[$this->ade];
        self::assertMatchesRegularExpression('/^[0-9A-Za-z]{22}$/', $baru);
        self::assertSame($baru, $profil->ambil($this->ade, 'toko.test')[ProfilUser::FOTO]);
        self::assertNotNull($this->foto->file($baru));
        self::assertSame([$baru], array_column($this->foto->riwayat($this->ade), 'kode'));
        self::assertSame('Foto profil lama.webp', $this->b->daftar->kode($baru)?->nama);

        // Diulang: tidak ada lagi yang dipindah kecuali foto yang hilang.
        self::assertStringNotContainsString('dari 1 akun', (string) $this->foto->pindahkanFotoLama($profil));
    }

    private function gambar(int $lebar, int $tinggi): string
    {
        $g = imagecreatetruecolor($lebar, $tinggi);
        imagefill($g, 0, 0, imagecolorallocate($g, 30, 160, 90));
        $file = $this->b->folder . '/gambar-' . bin2hex(random_bytes(4)) . '.png';
        imagepng($g, $file);
        imagedestroy($g);

        return $file;
    }
}
