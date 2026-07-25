<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class DownloadDataExportControllerTest extends WebTestCase
{
    /** @param non-empty-string $email */
    private function createVerifiedUser(EntityManagerInterface $em, string $username, string $email): User
    {
        $user = new User(
            username: $username,
            fullName: ucfirst($username),
            email: $email,
            password: 'hashed-password-placeholder',
        );
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function writeArchive(string $path): void
    {
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0770, true);
        }
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('profile.json', '{}');
        $zip->close();
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

        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);
        $path = DataExport::computeArchivePath($projectDir, $exportId);
        $this->writeArchive($path);

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
            @unlink($path);
        }
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
