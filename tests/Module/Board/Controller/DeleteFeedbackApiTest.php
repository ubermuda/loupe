<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Install\BoardInstallFlags;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\OAuthScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class DeleteFeedbackApiTest extends WebTestCase
{
    use BoardColumnFixtures;

    public function test_deleting_the_note_that_created_its_card_deletes_both(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'delete-feedback-api@example.com');
        $commentId = $this->addNote($client, $raw);

        $data = $this->delete($client, $raw, $commentId);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['cardDeleted' => true], $data);
        self::assertCount(0, $this->service(CardRepository::class)->findBy(['project' => $project]));
        self::assertNull($this->service(SiteReviewCommentRepository::class)->find($commentId));
    }

    public function test_an_addressed_note_is_not_found(): void
    {
        $client = static::createClient();
        [$raw] = $this->projectWithToken($client, 'delete-feedback-api-addressed@example.com');
        $commentId = $this->addNote($client, $raw);
        $comment = $this->service(SiteReviewCommentRepository::class)->find($commentId);
        self::assertInstanceOf(SiteReviewComment::class, $comment);
        $comment->status = SiteReviewCommentStatus::Addressed;
        $this->em()->flush();

        $data = $this->delete($client, $raw, $commentId);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'not_found'], $data);
        $this->em()->clear();
        self::assertNotNull($this->service(SiteReviewCommentRepository::class)->find($commentId));
    }

    public function test_a_note_of_another_project_is_not_found(): void
    {
        $client = static::createClient();
        [$foreignRaw] = $this->projectWithToken($client, 'delete-feedback-api-foreign@example.com');
        $commentId = $this->addNote($client, $foreignRaw);
        [$raw] = $this->projectWithToken($client, 'delete-feedback-api-mine@example.com');

        $data = $this->delete($client, $raw, $commentId);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'not_found'], $data);
        self::assertNotNull($this->service(SiteReviewCommentRepository::class)->find($commentId));
    }

    public function test_a_malformed_id_is_not_found(): void
    {
        $client = static::createClient();
        [$raw] = $this->projectWithToken($client, 'delete-feedback-api-malformed@example.com');

        $data = $this->delete($client, $raw, 'nope');

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'not_found'], $data);
    }

    public function test_the_board_switched_off_is_a_conflict(): void
    {
        $client = static::createClient();
        [$raw] = $this->projectWithToken($client, 'delete-feedback-api-off@example.com');
        $commentId = $this->addNote($client, $raw);
        $this->setBoardEnabled(false);

        $data = $this->delete($client, $raw, $commentId);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'board_disabled'], $data);
        self::assertNotNull($this->service(SiteReviewCommentRepository::class)->find($commentId));
    }

    public function test_the_widget_can_call_it_from_another_origin(): void
    {
        $client = static::createClient();
        [$raw] = $this->projectWithToken($client, 'delete-feedback-api-cors@example.com');
        $commentId = $this->addNote($client, $raw);

        $client->request(Request::METHOD_OPTIONS, '/api/board/feedback/'.$commentId, server: [
            'HTTP_ORIGIN' => 'https://app.localhost',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'DELETE',
        ]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('https://app.localhost', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
        self::assertStringContainsString('DELETE', (string) $client->getResponse()->headers->get('Access-Control-Allow-Methods'));

        $this->delete($client, $raw, $commentId);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('https://app.localhost', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_an_account_token_is_refused(): void
    {
        $client = static::createClient();
        [$widgetRaw] = $this->projectWithToken($client, 'delete-feedback-api-mcp@example.com');
        $commentId = $this->addNote($client, $widgetRaw);
        $scenario = new OAuthScenario(static::getContainer());
        $user = $scenario->createUser('delete-feedback-api-mcp-owner@example.com');
        $project = $scenario->createProject($user, 'delete-feedback-api-mcp');
        $raw = $scenario->accessTokenFor($client, $user, 'mcp', $project);

        $this->delete($client, $raw, $commentId);

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->service(SiteReviewCommentRepository::class)->find($commentId));
    }

    private function addNote(KernelBrowser $client, string $raw): string
    {
        $client->request(Request::METHOD_POST, '/api/board/feedback',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw, 'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://app.localhost'],
            content: json_encode([
                'body' => 'Footer overlaps the launcher',
                'url' => 'https://app.localhost/checkout',
                'anchors' => [['selector' => '.footer', 'text' => 'Footer']],
                'target' => ['newCard' => new \stdClass()],
            ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertIsString($data['commentId']);

        return $data['commentId'];
    }

    /** @return array<string, mixed> */
    private function delete(KernelBrowser $client, string $raw, string $commentId): array
    {
        $client->request(Request::METHOD_DELETE, '/api/board/feedback/'.$commentId,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw, 'HTTP_ORIGIN' => 'https://app.localhost']);

        $data = json_decode((string) $client->getResponse()->getContent(), true);

        return \is_array($data) ? $data : [];
    }

    /**
     * @param non-empty-string $email
     *
     * @return array{0: string, 1: Project}
     */
    private function projectWithToken(KernelBrowser $client, string $email): array
    {
        $scenario = new OAuthScenario(static::getContainer());
        $scenario->createClient();
        $user = $scenario->createUser($email);
        $project = $scenario->createProject($user, 'delete-feedback-api');
        $this->seedColumns($project);
        $this->em()->flush();
        $raw = $scenario->accessTokenFor($client, $user, 'site-review', $project);
        $this->setBoardEnabled(true);

        return [$raw, AgentCredential::managed($this->em(), $project, $project->id)];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = static::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    private function em(): EntityManagerInterface
    {
        return $this->service(EntityManagerInterface::class);
    }

    private function setBoardEnabled(bool $enabled): void
    {
        $flags = $this->service(FeatureFlagRepository::class);
        $flags->findAllIndexed()[BoardInstallFlags::FLAG_BOARD_ENABLED]->value = $enabled;
        $this->em()->flush();
    }
}
