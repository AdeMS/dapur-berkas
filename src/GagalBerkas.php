<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Berkas;

use RuntimeException;

/** Kesalahan yang pesannya aman dan jelas untuk ditampilkan ke user (mis. "Kuota penyimpanan penuh"). */
final class GagalBerkas extends RuntimeException
{
}
