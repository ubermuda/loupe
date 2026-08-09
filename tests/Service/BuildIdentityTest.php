<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BuildIdentity;
use PHPUnit\Framework\TestCase;

final class BuildIdentityTest extends TestCase
{
    private string $projectDir;

    #[\Override]
    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/build-identity-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/var', 0o777, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        @unlink($this->projectDir.'/var/build-version');
        @rmdir($this->projectDir.'/var');
        @rmdir($this->projectDir);
    }

    public function test_it_reports_the_version_the_image_build_wrote(): void
    {
        file_put_contents($this->projectDir.'/var/build-version', "v1.4.0\n");

        self::assertSame('v1.4.0', new BuildIdentity($this->projectDir)->version);
    }

    public function test_no_file_means_no_version_rather_than_a_made_up_one(): void
    {
        self::assertNull(new BuildIdentity($this->projectDir)->version);
    }

    /**
     * A build arg that expanded to nothing would otherwise leave an empty file
     * and the page would print a blank version as if it were real.
     */
    public function test_an_empty_file_counts_as_no_version(): void
    {
        file_put_contents($this->projectDir.'/var/build-version', "  \n");

        self::assertNull(new BuildIdentity($this->projectDir)->version);
    }
}
