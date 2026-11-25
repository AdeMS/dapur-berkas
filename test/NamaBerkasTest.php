<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Berkas\Test;

use AdeMS\Dapur\Berkas\JenisBerkas;
use AdeMS\Dapur\Berkas\NamaBerkas;
use PHPUnit\Framework\TestCase;

final class NamaBerkasTest extends TestCase
{
    public function testNamaAsliDipertahankan(): void
    {
        foreach (['Pantai.jpg', 'Laporan Bulan Juli (final).pdf', 'Café & Résumé.docx', 'foto-01_a.png', 'Ádé.txt'] as $nama) {
            self::assertSame($nama, NamaBerkas::bersihkan($nama));
        }
    }

    public function testKarakterBerbahayaDiganti(): void
    {
        self::assertSame('a_b_c_d_e_f_g_h_i.txt', NamaBerkas::bersihkan('a/b\\c:d*e?f"g<h>i.txt'));
        self::assertSame('foto_gpj.exe', NamaBerkas::bersihkan("foto\u{202E}gpj.exe"));
        self::assertSame('a_b.txt', NamaBerkas::bersihkan("a\x00b.txt"));
        self::assertSame('_.htaccess', NamaBerkas::bersihkan('.htaccess'));
        self::assertSame('_@eaDir', NamaBerkas::bersihkan('@eaDir'));
        self::assertSame('_-rf', NamaBerkas::bersihkan('-rf'));
        self::assertSame('_CON.txt', NamaBerkas::bersihkan('CON.txt'));
        self::assertSame('_nul', NamaBerkas::bersihkan('nul'));
        self::assertSame('nama', NamaBerkas::bersihkan('  nama. . '));
        self::assertSame('berkas', NamaBerkas::bersihkan(' ... '));
    }

    public function testPanjangDanUrutan(): void
    {
        $panjang = NamaBerkas::bersihkan(str_repeat('é', 200) . '.jpeg');
        self::assertLessThanOrEqual(255, strlen($panjang));
        self::assertStringEndsWith('.jpeg', $panjang);
        self::assertTrue(mb_check_encoding($panjang, 'UTF-8'));

        self::assertSame('Pantai (2).jpg', NamaBerkas::berikutnya('Pantai.jpg', 2, false));
        self::assertSame('Pantai (3).jpg', NamaBerkas::berikutnya('Pantai (2).jpg', 3, false));
        self::assertSame('Liburan 2026 (2)', NamaBerkas::berikutnya('Liburan 2026', 2, true));
    }

    public function testRapiDanKode(): void
    {
        self::assertSame('laporan-bulan-juli-final.pdf', NamaBerkas::rapi('Laporan Bulan Juli (final).pdf'));
        self::assertSame('cafe-resume.docx', NamaBerkas::rapi('Café Résumé.DOCX'));
        self::assertSame('berkas', NamaBerkas::rapi('!!!'));
        self::assertTrue(NamaBerkas::kodeSah(NamaBerkas::kode()));
        self::assertTrue(NamaBerkas::kodeSah(NamaBerkas::kode(32), 32));
        self::assertFalse(NamaBerkas::kodeSah('../../etc/passwd'));
    }

    public function testJenis(): void
    {
        self::assertTrue(JenisBerkas::diizinkan('a.docx'));
        self::assertTrue(JenisBerkas::diizinkan('a.JPG'));
        self::assertFalse(JenisBerkas::diizinkan('a.php'));
        self::assertFalse(JenisBerkas::diizinkan('a.html'));
        self::assertFalse(JenisBerkas::diizinkan('a.exe'));
        self::assertTrue(JenisBerkas::dibukaLangsung('a.pdf'));
        self::assertTrue(JenisBerkas::dibukaLangsung('a.mp4'));
        self::assertFalse(JenisBerkas::dibukaLangsung('a.docx'));
        self::assertFalse(JenisBerkas::dibukaLangsung('a.js'));
        self::assertSame('file-earmark-image', JenisBerkas::ikon('a.png'));
    }
}
