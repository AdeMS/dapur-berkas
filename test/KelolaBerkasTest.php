<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Berkas\Test;

use AdeMS\Dapur\Berkas\GagalBerkas;
use AdeMS\Dapur\Berkas\KelolaBerkas;
use PHPUnit\Framework\TestCase;

final class KelolaBerkasTest extends TestCase
{
    private Bantu $b;
    private int $ade;
    private int $budi;
    private int $cici;

    protected function setUp(): void
    {
        $this->b    = new Bantu();
        $this->ade  = $this->b->user['ade@contoh.test'];
        $this->budi = $this->b->user['budi@contoh.test'];
        $this->cici = $this->b->user['cici@contoh.test'];
    }

    protected function tearDown(): void
    {
        $this->b->hapus();
    }

    public function testFolderDanFileDenganNamaAsliDiDisk(): void
    {
        $k       = $this->b->kelola();
        $liburan = $k->buatFolder($this->ade, '', null, 'Liburan 2026');
        $foto    = $k->simpanFile($this->ade, '', $liburan, 'Pantai.jpg', $this->b->file('jpg'));

        $fisik = $this->b->folder . '/data/berkas/' . $this->b->kode($this->ade) . '/Liburan 2026/Pantai.jpg';
        self::assertFileExists($fisik);
        self::assertSame($fisik, $k->jalurFisik($foto));
        self::assertSame(22, strlen($foto->kode));
        self::assertSame('Berkas Saya / Liburan 2026 / Pantai.jpg', $k->jalurTampil($foto));

        // Nama sama: berkas baru tidak menimpa.
        $kedua = $k->simpanFile($this->ade, '', $liburan, 'Pantai.jpg', $this->b->file('lain'));
        self::assertSame('Pantai (2).jpg', $kedua->nama);
        self::assertSame('jpg', file_get_contents($fisik));

        // Karakter berbahaya dibersihkan, sisanya tetap.
        $aneh = $k->simpanFile($this->ade, '', null, 'a:b?.txt', $this->b->file());
        self::assertSame('a_b_.txt', $aneh->nama);

        $this->expectException(GagalBerkas::class);
        $k->simpanFile($this->ade, '', null, 'shell.php', $this->b->file('<?php'));
    }

    public function testFolderBertingkatGantiNamaPindah(): void
    {
        $k      = $this->b->kelola();
        $pantai = $k->folderBertingkat($this->ade, '', null, 'Liburan/Pantai');
        self::assertSame('Pantai', $pantai->nama);
        self::assertSame($pantai->id, $k->folderBertingkat($this->ade, '', null, 'liburan/pantai')->id, 'folder yang ada dipakai lagi');

        $f = $k->simpanFile($this->ade, '', $pantai, 'a.jpg', $this->b->file());
        $f = $k->gantiNama($this->ade, $f, 'Matahari Terbenam.jpg');
        self::assertFileExists($this->b->folder . '/data/berkas/' . $this->b->kode($this->ade) . '/Liburan/Pantai/Matahari Terbenam.jpg');

        $kerja = $k->buatFolder($this->ade, '', null, 'Kerja');
        $f     = $k->pindah($this->ade, $f, $kerja);
        self::assertFileExists($this->b->folder . '/data/berkas/' . $this->b->kode($this->ade) . '/Kerja/Matahari Terbenam.jpg');

        $liburan = $this->b->daftar->id($pantai->indukId);
        try {
            $k->pindah($this->ade, $liburan, $pantai);
            self::fail('Folder tidak boleh masuk ke dirinya sendiri');
        } catch (GagalBerkas) {
            self::assertTrue(true);
        }

        $this->expectException(GagalBerkas::class);
        $k->gantiNama($this->budi, $f, 'curi.jpg');
    }

    public function testTempatSampah(): void
    {
        $k     = $this->b->kelola();
        $folder = $k->buatFolder($this->ade, '', null, 'Lama');
        $file   = $k->simpanFile($this->ade, '', $folder, 'catatan.txt', $this->b->file('isi'));
        $akar   = $this->b->folder . '/data/berkas/' . $this->b->kode($this->ade);

        $k->buang($this->ade, $folder);
        self::assertDirectoryDoesNotExist($akar . '/Lama');
        self::assertFileExists($akar . '/.sampah/' . $folder->id . '/catatan.txt');
        self::assertSame([], $this->b->daftar->isi($this->ade, '', null));
        self::assertCount(1, $this->b->daftar->sampah($this->ade));
        self::assertSame($akar . '/.sampah/' . $folder->id . '/catatan.txt', $k->jalurFisik($this->b->daftar->id($file->id)));
        self::assertSame([], $k->cari($this->ade, 'catatan'), 'yang dibuang tidak muncul di pencarian');

        // Nama asal sudah dipakai lagi: dipulihkan sebagai "Lama (2)".
        $k->buatFolder($this->ade, '', null, 'Lama');
        $pulih = $k->pulihkan($this->ade, $this->b->daftar->id($folder->id));
        self::assertSame('Lama (2)', $pulih->nama);
        self::assertFileExists($akar . '/Lama (2)/catatan.txt');
        self::assertNull($this->b->daftar->id($file->id)->dihapusPada);

        $k->buang($this->ade, $this->b->daftar->id($file->id));
        $k->hapusPermanen($this->ade, $this->b->daftar->id($file->id));
        self::assertNull($this->b->daftar->id($file->id));
        self::assertFileDoesNotExist($akar . '/.sampah/' . $file->id);
    }

    public function testSampahKedaluwarsaDibersihkan(): void
    {
        $k = $this->b->kelola();
        $f = $k->simpanFile($this->ade, '', null, 'a.txt', $this->b->file());
        $k->buang($this->ade, $f);
        $this->b->db->query("UPDATE uji_berkas SET dihapus_pada = '2020-01-01 00:00:00' WHERE id = ?", [$f->id]);

        self::assertSame(1, $k->bersihkanKedaluwarsa());
        self::assertNull($this->b->daftar->id($f->id));
    }

    public function testKuota(): void
    {
        $this->b->config = ['kuota_mb' => 1];
        $k = $this->b->kelola();
        $k->simpanFile($this->ade, '', null, 'a.txt', $this->b->file(str_repeat('x', 700_000)));

        try {
            $k->simpanFile($this->ade, '', null, 'b.txt', $this->b->file(str_repeat('x', 700_000)));
            self::fail('Kuota terlewati');
        } catch (GagalBerkas $e) {
            self::assertStringContainsString('Kuota', $e->getMessage());
        }

        $this->b->daftar->aturKuota($this->ade, 5);
        $k->simpanFile($this->ade, '', null, 'b.txt', $this->b->file(str_repeat('x', 700_000)));
        self::assertSame(1_400_000, $k->kuota($this->ade)['pakai']);
        self::assertSame(5 * 1024 * 1024, $k->kuota($this->ade)['batas']);
    }

    public function testBerbagiDanHak(): void
    {
        $k     = $this->b->kelola();
        $proyek = $k->buatFolder($this->ade, '', null, 'Proyek');
        $sub    = $k->buatFolder($this->ade, '', $proyek, 'Rancangan');
        $file   = $k->simpanFile($this->ade, '', $sub, 'denah.pdf', $this->b->file());

        self::assertNull($k->hak($this->budi, $file));

        $k->bagikanKeEmail($this->ade, $proyek, 'BUDI@contoh.test', KelolaBerkas::HAK_BACA);
        self::assertSame(KelolaBerkas::HAK_BACA, $k->hak($this->budi, $file), 'berbagi folder berlaku untuk isinya');
        self::assertSame('Proyek / Rancangan / denah.pdf', $k->jalurTampil($file, $this->budi));
        self::assertCount(1, $k->dibagikanKe($this->budi));
        self::assertCount(1, $k->cari($this->budi, 'denah'));

        try {
            $k->simpanFile($this->budi, '', $sub, 'b.txt', $this->b->file());
            self::fail('Hak baca tidak boleh menulis');
        } catch (GagalBerkas) {
            self::assertTrue(true);
        }

        // Hak tulis: berkas baru milik pemilik folder, masuk kuota pemilik.
        $k->bagikanKeEmail($this->ade, $proyek, 'budi@contoh.test', KelolaBerkas::HAK_TULIS);
        $baru = $k->simpanFile($this->budi, '', $sub, 'catatan budi.txt', $this->b->file('dari budi'));
        self::assertSame($this->ade, $baru->pemilikId);
        self::assertFileExists($this->b->folder . '/data/berkas/' . $this->b->kode($this->ade) . '/Proyek/Rancangan/catatan budi.txt');

        // Berbagi ke anggota website.
        $this->b->lingkup[$this->cici] = ['kantor.contoh.test'];
        $k2 = $this->b->kelola();
        $k2->bagikanKeLingkup($this->ade, $file, 'kantor.contoh.test', KelolaBerkas::HAK_BACA);
        self::assertSame(KelolaBerkas::HAK_BACA, $k2->hak($this->cici, $file));
        self::assertSame('denah.pdf', $k2->jalurTampil($file, $this->cici));

        // Hanya pemilik yang mengatur berbagi; cabut.
        try {
            $k->buatTautan($this->budi, $file);
            self::fail('Bukan pemilik');
        } catch (GagalBerkas) {
            self::assertTrue(true);
        }

        // Ubah hak tanpa mencabut: kembali hanya baca.
        $bagiBudi = array_values(array_filter($this->b->daftar->bagiBerkas($proyek->id), static fn (array $x): bool => $x['user_id'] !== null))[0];
        $k->ubahHakBagi($this->ade, $proyek, (int) $bagiBudi['id'], KelolaBerkas::HAK_BACA);
        self::assertSame(KelolaBerkas::HAK_BACA, $this->b->kelola()->hak($this->budi, $file));
        try {
            $k->ubahHakBagi($this->budi, $proyek, (int) $bagiBudi['id'], KelolaBerkas::HAK_TULIS);
            self::fail('Hanya pemilik yang bisa mengubah hak');
        } catch (GagalBerkas) {
            self::assertTrue(true);
        }

        foreach ($this->b->daftar->bagiBerkas($proyek->id) as $bagi) {
            $k->cabutBagi($this->ade, $proyek, (int) $bagi['id']);
        }
        self::assertNull($this->b->kelola()->hak($this->budi, $file));
    }

    public function testTautanPublik(): void
    {
        $k     = $this->b->kelola();
        $file  = $k->simpanFile($this->ade, '', null, 'brosur.pdf', $this->b->file());
        $token = $k->buatTautan($this->ade, $file, 7);
        self::assertSame(32, strlen($token));
        self::assertSame($file->id, (int) $this->b->daftar->token($token)['berkas_id']);

        try {
            $k->ubahHakBagi($this->ade, $file, (int) $this->b->daftar->token($token)['id'], KelolaBerkas::HAK_TULIS);
            self::fail('Tautan publik selalu hanya baca');
        } catch (GagalBerkas) {
            self::assertTrue(true);
        }

        $this->b->db->query("UPDATE uji_berkas_bagi SET sampai = '2020-01-01 00:00:00' WHERE token = ?", [$token]);
        self::assertNull($this->b->daftar->token($token), 'tautan kedaluwarsa');
    }

    public function testLokasiAlias(): void
    {
        $nas = $this->b->folder . '/nas/Foto';
        mkdir($nas, 0777, true);
        $this->b->config = ['lokasi' => [
            'foto'  => ['path' => $nas, 'pemilik' => 'ade@contoh.test'],
            'saya'  => ['path' => $nas, 'pemilik' => 'ade@contoh.test'], // nama terpakai: diabaikan
        ]];
        $k = $this->b->kelola();

        self::assertSame(['' => 'Berkas Saya', 'foto' => 'Foto'], $k->lokasiMilik($this->ade));
        self::assertSame(['' => 'Berkas Saya'], $k->lokasiMilik($this->budi));

        $f = $k->simpanFile($this->ade, 'foto', $k->folderBertingkat($this->ade, 'foto', null, 'Liburan 2026'), 'Pantai.jpg', $this->b->file(str_repeat('x', 5000)));
        self::assertFileExists($nas . '/Liburan 2026/Pantai.jpg');
        self::assertSame('Foto / Liburan 2026 / Pantai.jpg', $k->jalurTampil($f));
        self::assertSame(0, $k->kuota($this->ade)['pakai'], 'lokasi alias tidak masuk kuota bawaan');

        $this->expectException(GagalBerkas::class);
        $k->buatFolder($this->budi, 'foto', null, 'Curi');
    }

    public function testFileTakTercatatDiDiskTidakDitimpa(): void
    {
        $k = $this->b->kelola();
        mkdir($this->b->folder . '/data/berkas/' . $this->b->kode($this->ade), 0777, true);
        file_put_contents($this->b->folder . '/data/berkas/' . $this->b->kode($this->ade) . '/ada.txt', 'dari NAS');

        try {
            $k->simpanFile($this->ade, '', null, 'ada.txt', $this->b->file('baru'));
            self::fail('Seharusnya ditolak');
        } catch (GagalBerkas) {
            self::assertSame('dari NAS', file_get_contents($this->b->folder . '/data/berkas/' . $this->b->kode($this->ade) . '/ada.txt'));
            self::assertSame([], $this->b->daftar->isi($this->ade, '', null));
        }
    }
}
