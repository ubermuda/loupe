<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Export;

use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\User;
use App\Module\Account\Export\DataExportArchiveBuilder;
use App\Module\Account\Export\UserDataExporterInterface;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
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

    public function test_the_move_states_visibility_instead_of_reading_the_source_acl(): void
    {
        // Without this, Flysystem reads the source object's ACL to reproduce it
        // on the destination. S3-compatible stores that implement no ACLs —
        // Garage answers GetObjectAcl with a 501 — then fail the whole export
        // with an unexplained "unable to move file". Only reproducible against
        // such a store, so this is the guard that keeps it fixed.
        $id = Uuid::v7();
        $key = DataExport::computeArchiveKey($id);

        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::once())
            ->method('move')
            ->with($key.'.tmp', $key, [
                Config::OPTION_RETAIN_VISIBILITY => false,
                Config::OPTION_VISIBILITY => Visibility::PRIVATE,
            ]);

        new DataExportArchiveBuilder([$this->emptyExporter()], $storage)->build($this->user(), $id);
    }

    public function test_a_failing_upload_deletes_the_temporary_object(): void
    {
        $id = Uuid::v7();
        $tmpKey = DataExport::computeArchiveKey($id).'.tmp';

        $storage = $this->storageExpectingTemporaryCleanup($id);
        $storage->expects(self::once())->method('writeStream')->with($tmpKey)
            ->willThrowException(UnableToWriteFile::atLocation($tmpKey));
        $storage->expects(self::never())->method('move');

        $this->expectException(FilesystemException::class);
        new DataExportArchiveBuilder([$this->emptyExporter()], $storage)->build($this->user(), $id);
    }

    public function test_a_failing_move_deletes_the_temporary_object(): void
    {
        $id = Uuid::v7();
        $key = DataExport::computeArchiveKey($id);

        $storage = $this->storageExpectingTemporaryCleanup($id);
        $storage->expects(self::once())->method('writeStream')->with($key.'.tmp');
        $storage->expects(self::once())->method('move')
            ->willThrowException(UnableToMoveFile::because('nope', $key.'.tmp', $key));

        $this->expectException(FilesystemException::class);
        new DataExportArchiveBuilder([$this->emptyExporter()], $storage)->build($this->user(), $id);
    }

    /**
     * Nothing else ever looks at a `.tmp` key — every purger only knows
     * `<id>.zip` — so an upload that fails without this cleanup orphans the
     * object forever. Both halves of the upload need it: a half-written object
     * is as orphaned as an unmoved one.
     *
     * @return FilesystemOperator&MockObject
     */
    private function storageExpectingTemporaryCleanup(Uuid $id): FilesystemOperator
    {
        /** @var FilesystemOperator&MockObject $storage */
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::once())->method('delete')->with(DataExport::computeArchiveKey($id).'.tmp');

        return $storage;
    }

    private function emptyExporter(): UserDataExporterInterface
    {
        return new class implements UserDataExporterInterface {
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
    }

    private function user(): User
    {
        return new User('Alice A', 'alice@example.com', 'x');
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
