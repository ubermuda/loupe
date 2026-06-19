<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Controller;

use App\Module\Account\Entity\User;
use App\Module\SiteReview\Entity\SiteReviewBatch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class BatchesPageTest extends WebTestCase
{
    public function test_list_shows_only_the_users_own_batches(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'owner@example.com');
        $other = $this->user($em, 'other@example.com');
        $this->batch($em, $owner, 'mine');
        $this->batch($em, $other, 'theirs');

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/site-review/batches');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bp-page-title', 'Site reviews');
        self::assertCount(1, $crawler->filter('tbody tr'));
    }

    public function test_show_renders_owned_batch_comments(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'owner2@example.com');
        $batch = $this->batch($em, $owner, 'make this bigger');

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/site-review/batches/'.$batch->id);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'make this bigger');
    }

    public function test_show_returns_404_for_another_users_batch(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'owner3@example.com');
        $other = $this->user($em, 'other3@example.com');
        $batch = $this->batch($em, $owner, 'secret');

        $client->loginUser($other);
        $client->request(Request::METHOD_GET, '/site-review/batches/'.$batch->id);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_show_returns_404_for_invalid_id(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'owner4@example.com');

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/site-review/batches/not-a-uuid');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_list_requires_authentication(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/site-review/batches');

        self::assertResponseStatusCodeSame(302);
    }

    /** @param non-empty-string $email */
    private function user(EntityManagerInterface $em, string $email): User
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function batch(EntityManagerInterface $em, User $owner, string $body): SiteReviewBatch
    {
        $batch = new SiteReviewBatch($owner);
        $batch->addComment($body, '.card', 'Save', 'https://app.localhost/x');
        $em->persist($batch);
        $em->flush();

        return $batch;
    }
}
