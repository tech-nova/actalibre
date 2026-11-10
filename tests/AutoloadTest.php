<?php
declare(strict_types=1);

namespace ActaLibre\Tests;

use PHPUnit\Framework\TestCase;

final class AutoloadTest extends TestCase
{
    public function testLAutoloaderDeProductionChargeLesClassesActaLibre(): void
    {
        $sonde = __DIR__ . '/fixtures/autoload_probe.php';
        $sortie = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($sonde));

        self::assertSame('okokok', $sortie);
    }
}
