<?php

declare(strict_types=1);

namespace Tests\Unit\MusicIdentity;

use App\Services\MusicIdentity\MusicIdentityConfiguration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** The version is written in one place and every fallback reads it; issue #1033 raised it last. */
final class MusicIdentityAlgorithmVersionTest extends TestCase
{
    #[Test]
    public function the_algorithm_version_is_written_once_and_the_configuration_reads_it(): void
    {
        $root = dirname(__DIR__, 3);
        /** @var array{algorithm_version: string} $config */
        $config = require $root.'/config/music-identity.php';
        $this->assertSame('music-identity-v4', MusicIdentityConfiguration::DEFAULT_ALGORITHM_VERSION);
        $this->assertSame(MusicIdentityConfiguration::DEFAULT_ALGORITHM_VERSION, $config['algorithm_version']);

        $literals = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app')) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php'
                && preg_match_all("/'music-identity-v\\d+'/", (string) file_get_contents($file->getPathname()), $matches) > 0) {
                $literals[substr($file->getPathname(), strlen($root) + 1)] = count($matches[0]);
            }
        }

        $this->assertSame(['app/Services/MusicIdentity/MusicIdentityConfiguration.php' => 1], $literals);
    }
}
