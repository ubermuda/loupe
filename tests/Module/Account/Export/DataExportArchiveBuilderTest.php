<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Export;

use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\User;
use App\Module\Account\Export\DataExportArchiveBuilder;
use App\Module\Account\Export\UserDataExporterInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToMoveFile;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class DataExportArchiveBuilderTest extends TestCase
{
    /** @var list<string> */
    private array $rootsToRemove = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->rootsToRemove as $root) {
            foreach (glob($root.'/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }

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

        $storage = $this->localStorage();
        $builder = new DataExportArchiveBuilder([$exporter], $storage);
        $id = Uuid::v7();

        $key = $builder->build($this->user(), $id);

        self::assertSame(DataExport::computeArchiveKey($id), $key);
        $zip = $this->openStoredArchive($storage, $key);
        self::assertSame(1, $zip->numFiles);
        $json = $zip->getFromName('things.json');
        self::assertIsString($json);
        self::assertSame([['name' => 'thing-1']], json_decode($json, true));
        $zip->close();
    }

    public function test_a_failing_build_leaves_the_previous_archive_untouched(): void
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

        $storage = $this->localStorage();
        $builder = new DataExportArchiveBuilder([$throwingExporter], $storage);
        $id = Uuid::v7();

        $key = DataExport::computeArchiveKey($id);
        $storage->write($key, 'original bytes');

        try {
            $builder->build($this->user(), $id);
            self::fail('Expected build() to rethrow.');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame('original bytes', $storage->read($key));
        self::assertFalse($storage->fileExists($key.'.tmp'));
    }

    public function test_a_failing_move_deletes_the_temporary_object(): void
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
                return [];
            }
        };

        $id = Uuid::v7();
        $key = DataExport::computeArchiveKey($id);

        /** @var FilesystemOperator&MockObject $storage */
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::once())->method('writeStream')->with($key.'.tmp');
        $storage->expects(self::once())->method('move')->willThrowException(UnableToMoveFile::because('nope', $key.'.tmp', $key));
        // Nothing else ever looks at a `.tmp` key, so a move that fails
        // without this cleanup orphans the object in the bucket forever.
        $storage->expects(self::once())->method('delete')->with($key.'.tmp');

        $builder = new DataExportArchiveBuilder([$exporter], $storage);

        $this->expectException(UnableToMoveFile::class);
        $builder->build($this->user(), $id);
    }

    private function user(): User
    {
        return new User('alice', 'Alice A', 'alice@example.com', 'x');
    }

    private function localStorage(): Filesystem
    {
        $root = sys_get_temp_dir().'/loupe-export-test-'.bin2hex(random_bytes(4));
        $this->rootsToRemove[] = $root;

        return new Filesystem(new LocalFilesystemAdapter($root));
    }

    private function openStoredArchive(FilesystemOperator $storage, string $key): \ZipArchive
    {
        // ZipArchive reads local files only, so a stored archive has to come
        // back down before it can be inspected.
        $localPath = tempnam(sys_get_temp_dir(), 'loupe-export-assert-');
        self::assertIsString($localPath);
        file_put_contents($localPath, $storage->read($key));

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($localPath));

        return $zip;
    }
}
