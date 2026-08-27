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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Pins the exact bytes of an export payload. An archive a user already
 * downloaded must keep parsing the same way, so the expectations below are
 * literal JSON rather than a re-encoding of the same arrays: a writer that
 * changes indentation, key order or escaping fails here instead of shipping.
 */
final class DataExportPayloadFormatTest extends TestCase
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

    /** @return iterable<string, array{UserDataExporterInterface, string}> */
    public static function payloads(): iterable
    {
        yield 'a list of rows' => [self::listExporter(), <<<'JSON'
            [
                {
                    "id": "a/b",
                    "name": "Caf\u00e9 \ud83c\udf89",
                    "tags": [
                        "x",
                        "y"
                    ],
                    "nested": {
                        "k": null
                    },
                    "empty": []
                },
                {
                    "id": "c/d",
                    "name": "Second",
                    "tags": [],
                    "nested": {
                        "k": 2
                    },
                    "empty": []
                }
            ]
            JSON];

        yield 'an empty list' => [self::emptyExporter(), '[]'];

        yield 'an object-shaped payload' => [self::objectExporter(), <<<'JSON'
            {
                "fullName": "A/B",
                "when": null,
                "deep": {
                    "p": 1
                }
            }
            JSON];

        // The billing profile's shape when the user has none: an object-shaped
        // exporter with nothing to say still writes a JSON array.
        yield 'an absent object-shaped payload' => [self::emptyExporter(), '[]'];
    }

    #[DataProvider('payloads')]
    public function test_payload_bytes(UserDataExporterInterface $exporter, string $expected): void
    {
        self::assertSame($expected, $this->payloadFor($exporter));
    }

    public function test_a_payload_round_trips_through_json_decode(): void
    {
        self::assertSame(
            [
                ['id' => 'a/b', 'name' => 'Café 🎉', 'tags' => ['x', 'y'], 'nested' => ['k' => null], 'empty' => []],
                ['id' => 'c/d', 'name' => 'Second', 'tags' => [], 'nested' => ['k' => 2], 'empty' => []],
            ],
            json_decode($this->payloadFor(self::listExporter()), true, 512, \JSON_THROW_ON_ERROR),
        );
    }

    private static function listExporter(): UserDataExporterInterface
    {
        return new class implements UserDataExporterInterface {
            #[\Override]
            public function filename(): string
            {
                return 'things.json';
            }

            #[\Override]
            public function export(User $user): iterable
            {
                return [
                    ['id' => 'a/b', 'name' => 'Café 🎉', 'tags' => ['x', 'y'], 'nested' => ['k' => null], 'empty' => []],
                    ['id' => 'c/d', 'name' => 'Second', 'tags' => [], 'nested' => ['k' => 2], 'empty' => []],
                ];
            }
        };
    }

    private static function objectExporter(): UserDataExporterInterface
    {
        return new class implements UserDataExporterInterface {
            #[\Override]
            public function filename(): string
            {
                return 'things.json';
            }

            #[\Override]
            public function export(User $user): iterable
            {
                return ['fullName' => 'A/B', 'when' => null, 'deep' => ['p' => 1]];
            }
        };
    }

    private static function emptyExporter(): UserDataExporterInterface
    {
        return new class implements UserDataExporterInterface {
            #[\Override]
            public function filename(): string
            {
                return 'things.json';
            }

            #[\Override]
            public function export(User $user): iterable
            {
                return [];
            }
        };
    }

    private function payloadFor(UserDataExporterInterface $exporter): string
    {
        $storage = $this->localStorage();
        $id = Uuid::v7();

        $key = new DataExportArchiveBuilder([$exporter], $storage)
            ->build(new User('Alice A', 'alice@example.com', 'x'), $id);
        self::assertSame(DataExport::computeArchiveKey($id), $key);

        $localPath = tempnam(sys_get_temp_dir(), 'loupe-export-format-');
        self::assertIsString($localPath);

        try {
            file_put_contents($localPath, $storage->read($key));
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($localPath));
            $json = $zip->getFromName($exporter->filename());
            $zip->close();
        } finally {
            @unlink($localPath);
        }

        self::assertIsString($json);

        return $json;
    }

    private function localStorage(): FilesystemOperator
    {
        $root = sys_get_temp_dir().'/loupe-export-format-'.bin2hex(random_bytes(4));
        $this->rootsToRemove[] = $root;

        return new Filesystem(new LocalFilesystemAdapter($root));
    }
}
