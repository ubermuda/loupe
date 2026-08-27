<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\User;
use App\Module\Account\Export\DataExportArchiveBuilder;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class DownloadDataExportControllerTest extends WebTestCase
{
    /** @param non-empty-string $email */
    private function createVerifiedUser(EntityManagerInterface $em, string $username, string $email): User
    {
        $user = new User(
            fullName: ucfirst($username),
            email: $email,
            password: 'hashed-password-placeholder',
        );
        AcceptedTerms::stamp($user, static::getContainer());
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function exportStorage(): FilesystemOperator
    {
        $storage = static::getContainer()->get('test.export.storage');
        self::assertInstanceOf(FilesystemOperator::class, $storage);

        return $storage;
    }

    private function writeArchive(string $key): void
    {
        $localPath = tempnam(sys_get_temp_dir(), 'loupe-export-test-');
        self::assertIsString($localPath);
        $zip = new \ZipArchive();
        $zip->open($localPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('profile.json', '{}');
        $zip->close();

        $this->exportStorage()->write($key, (string) file_get_contents($localPath));
        @unlink($localPath);
    }

    public function test_owner_with_valid_token_downloads_the_archive(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->createVerifiedUser($em, 'alice', 'alice@example.com');

        $export = new DataExport($owner);
        $rawToken = $export->complete();
        $em->persist($export);
        $em->flush();
        $exportId = $export->id;
        self::assertNotNull($exportId);
        $em->clear();

        $key = DataExport::computeArchiveKey($exportId);
        $this->writeArchive($key);

        try {
            $client->loginUser($owner);
            $client->request(Request::METHOD_GET, sprintf('/account/exports/%s/download?token=%s', $exportId, $rawToken));

            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame(
                'content-disposition',
                sprintf('attachment; filename=loupe-export-%s.zip', $exportId),
            );
            self::assertStringContainsString('zip', (string) $client->getResponse()->headers->get('content-type'));
        } finally {
            $this->exportStorage()->delete($key);
        }
    }

    /**
     * The archive is written by the messenger worker and read by the web
     * process, which in the shipped production topology are separate containers
     * with no shared filesystem. Building through the real builder and then
     * downloading proves both sides resolve the same object through the same
     * storage — seeding the archive by hand cannot catch the two drifting apart.
     */
    public function test_an_archive_built_by_the_builder_is_served_by_the_download_route(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->createVerifiedUser($em, 'alice', 'alice@example.com');

        $export = new DataExport($owner);
        $rawToken = $export->complete();
        $em->persist($export);
        $em->flush();
        $exportId = $export->id;
        self::assertNotNull($exportId);

        $builder = static::getContainer()->get(DataExportArchiveBuilder::class);
        self::assertInstanceOf(DataExportArchiveBuilder::class, $builder);
        $key = $builder->build($owner, $exportId);
        $em->clear();

        try {
            $client->loginUser($owner);
            $client->request(Request::METHOD_GET, sprintf('/account/exports/%s/download?token=%s', $exportId, $rawToken));

            self::assertResponseIsSuccessful();
            // The response is streamed, so its body is only readable off the
            // BrowserKit response — StreamedResponse::getContent() is false.
            $body = $client->getInternalResponse()->getContent();
            self::assertNotSame('', $body);
            self::assertSame($this->exportStorage()->read($key), $body);
        } finally {
            $this->exportStorage()->delete($key);
        }
    }

    public function test_the_signed_in_owner_downloads_without_a_token(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->createVerifiedUser($em, 'alice', 'alice@example.com');

        $export = new DataExport($owner);
        $export->complete();
        $em->persist($export);
        $em->flush();
        $exportId = $export->id;
        self::assertNotNull($exportId);
        $em->clear();

        $key = DataExport::computeArchiveKey($exportId);
        $this->writeArchive($key);

        try {
            $client->loginUser($owner);
            $client->request(Request::METHOD_GET, sprintf('/account/exports/%s/download', $exportId));

            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame(
                'content-disposition',
                sprintf('attachment; filename=loupe-export-%s.zip', $exportId),
            );
        } finally {
            $this->exportStorage()->delete($key);
        }
    }

    /** Ownership authorises the download; the 48-hour window still gates it. */
    public function test_an_expired_export_gets_404_without_a_token(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->createVerifiedUser($em, 'alice', 'alice@example.com');

        $export = new DataExport($owner);
        $export->complete();
        $export->expiresAt = new \DateTimeImmutable('-1 minute');
        $em->persist($export);
        $em->flush();
        $exportId = $export->id;
        self::assertNotNull($exportId);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, sprintf('/account/exports/%s/download', $exportId));

        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_pending_export_gets_404_without_a_token(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->createVerifiedUser($em, 'alice', 'alice@example.com');

        $export = new DataExport($owner);
        $em->persist($export);
        $em->flush();
        $exportId = $export->id;
        self::assertNotNull($exportId);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, sprintf('/account/exports/%s/download', $exportId));

        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_different_user_gets_404(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->createVerifiedUser($em, 'alice', 'alice@example.com');
        $other = $this->createVerifiedUser($em, 'bob', 'bob@example.com');

        $export = new DataExport($owner);
        $rawToken = $export->complete();
        $em->persist($export);
        $em->flush();
        $exportId = $export->id;
        self::assertNotNull($exportId);
        $em->clear();

        $client->loginUser($other);
        $client->request(Request::METHOD_GET, sprintf('/account/exports/%s/download?token=%s', $exportId, $rawToken));

        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_wrong_token_gets_404(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->createVerifiedUser($em, 'alice', 'alice@example.com');

        $export = new DataExport($owner);
        $export->complete();
        $em->persist($export);
        $em->flush();
        $exportId = $export->id;
        self::assertNotNull($exportId);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, sprintf('/account/exports/%s/download?token=not-the-token', $exportId));

        self::assertResponseStatusCodeSame(404);
    }

    public function test_an_expired_export_gets_404(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->createVerifiedUser($em, 'alice', 'alice@example.com');

        $export = new DataExport($owner);
        $rawToken = $export->complete();
        $export->expiresAt = new \DateTimeImmutable('-1 minute');
        $em->persist($export);
        $em->flush();
        $exportId = $export->id;
        self::assertNotNull($exportId);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, sprintf('/account/exports/%s/download?token=%s', $exportId, $rawToken));

        self::assertResponseStatusCodeSame(404);
    }

    public function test_anonymous_request_is_redirected_to_login(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->createVerifiedUser($em, 'alice', 'alice@example.com');

        $export = new DataExport($owner);
        $rawToken = $export->complete();
        $em->persist($export);
        $em->flush();
        $exportId = $export->id;
        self::assertNotNull($exportId);

        $client->request(Request::METHOD_GET, sprintf('/account/exports/%s/download?token=%s', $exportId, $rawToken));

        self::assertResponseRedirects('/login');
    }
}
