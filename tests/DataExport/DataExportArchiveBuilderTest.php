<?php

declare(strict_types=1);

namespace App\Tests\DataExport;

use App\DataExport\DataExportArchiveBuilder;
use App\DataExport\UserDataExporterInterface;
use App\Module\Account\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class DataExportArchiveBuilderTest extends TestCase
{
    public function test_builds_a_zip_with_one_json_file_per_exporter(): void
    {
        $exporter = new class implements UserDataExporterInterface {
            #[\Override]
            public function filename(): string
            {
                return 'things.json';
            }

            #[\Override]
            public function export(User $user): array
            {
                return [['name' => 'thing-1']];
            }
        };

        $dir = sys_get_temp_dir().'/loupe-export-test-'.bin2hex(random_bytes(4));
        $builder = new DataExportArchiveBuilder([$exporter], $dir);
        $user = new User('alice', 'Alice A', 'alice@example.com', 'x');
        $id = Uuid::v7();

        $path = $builder->build($user, $id);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        self::assertSame(1, $zip->numFiles);
        $json = $zip->getFromName('things.json');
        self::assertIsString($json);
        self::assertSame([['name' => 'thing-1']], json_decode($json, true));
        $zip->close();
        unlink($path);
        rmdir($dir.'/var/exports');
    }

    public function test_a_failing_rebuild_leaves_the_previous_archive_untouched(): void
    {
        $throwingExporter = new class implements UserDataExporterInterface {
            #[\Override]
            public function filename(): string
            {
                return 'things.json';
            }

            #[\Override]
            public function export(User $user): array
            {
                throw new \RuntimeException('boom');
            }
        };

        $dir = sys_get_temp_dir().'/loupe-export-test-'.bin2hex(random_bytes(4));
        $builder = new DataExportArchiveBuilder([$throwingExporter], $dir);
        $user = new User('alice', 'Alice A', 'alice@example.com', 'x');
        $id = Uuid::v7();

        $path = \App\Module\Account\Entity\DataExport::computeArchivePath($dir, $id);
        mkdir(\dirname($path), 0770, true);
        file_put_contents($path, 'original bytes');

        try {
            $builder->build($user, $id);
            self::fail('Expected build() to rethrow.');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame('original bytes', file_get_contents($path));
        self::assertFileDoesNotExist($path.'.tmp');

        unlink($path);
        rmdir($dir.'/var/exports');
    }
}
