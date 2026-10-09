<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardVerdict;
use App\Module\Board\Entity\CardVerdictDelivery;
use App\Module\Board\Entity\CardVerdictDeliveryState;
use App\Module\Board\Repository\CardVerdictDeliveryRepository;
use App\Module\Board\Repository\CardVerdictRepository;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\GitHub\Entity\GitHubUserConnection;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Command\BindWorkflowTemplateHandler;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Module\Board\CardVerdictScenario;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\OAuthScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class CardVerdictApiTest extends WebTestCase
{
    use BoardColumnFixtures;
    use CardVerdictScenario;

    private EntityManagerInterface $em;

    public function test_the_panel_lists_open_pull_requests_pending_notes_and_no_verdict_yet(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'verdict-api-panel@example.com');
        $card = $this->card($project);
        $open = $this->linkedPullRequest($card, 7);
        $this->linkedPullRequest($card, 8, PullRequestState::Merged);
        $note = $this->note($card, 'The footer overlaps the launcher.');
        $this->note($card, 'Fixed already.', SiteReviewCommentStatus::Resolved, 1);
        $this->em->flush();

        $data = $this->call($client, Request::METHOD_GET, $this->panelPath($card), $raw);

        self::assertResponseIsSuccessful();
        self::assertSame((string) $card->id, $data['cardId']);
        self::assertSame([['id' => (string) $open->id, 'label' => 'acme/widgets#7', 'ownPullRequest' => false]], $data['pullRequests']);
        self::assertSame([['id' => (string) $note->id, 'url' => 'https://app.example/page', 'body' => 'The footer overlaps the launcher.', 'anchorCount' => 1]], $data['notes']);
        self::assertSame(['state' => 'none'], $data['connection']);
        self::assertSame(['approve' => ['actions' => []], 'request-changes' => ['actions' => []], 'comment' => ['actions' => []]], $data['preview']);
        self::assertNull($data['latestVerdict']);
    }

    public function test_the_panel_previews_the_review_write_for_every_kind_while_the_workflow_is_on(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'verdict-api-preview@example.com', 'verdict-api-preview');
        $card = $this->card($project);
        $this->service(BindWorkflowTemplateHandler::class)(new BindWorkflowTemplateCommand($project, 'simple', []));
        $this->em->flush();

        $data = $this->call($client, Request::METHOD_GET, $this->panelPath($card), $raw);

        self::assertResponseIsSuccessful();
        $action = ['code' => 'post-review', 'label' => 'Posts your verdict as a review on the pull requests you pick.'];
        self::assertSame(
            ['approve' => ['actions' => [$action]], 'request-changes' => ['actions' => [$action]], 'comment' => ['actions' => [$action]]],
            $data['preview'],
        );
    }

    public function test_a_sent_verdict_is_stored_and_shows_in_the_panel_with_its_delivery_states(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'verdict-api-send@example.com');
        $card = $this->card($project);
        $pullRequest = $this->linkedPullRequest($card, 7);
        $this->note($card, 'The footer overlaps the launcher.');
        $this->em->flush();

        $sent = $this->call($client, Request::METHOD_POST, $this->sendPath($card), $raw, [
            'kind' => 'request-changes',
            'pullRequestIds' => [(string) $pullRequest->id],
            'message' => 'Please fix the footer.',
            'submissionId' => '0198a2c0-0000-7000-8000-0000000000aa',
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('request-changes', $sent['kind']);
        self::assertSame(1, $sent['noteCount']);

        $this->em->clear();
        $verdicts = $this->service(CardVerdictRepository::class)->findAll();
        self::assertCount(1, $verdicts);
        self::assertSame('Please fix the footer.', $verdicts[0]->message);
        $deliveries = $this->service(CardVerdictDeliveryRepository::class)->findAll();
        self::assertCount(1, $deliveries);
        self::assertSame(CardVerdictDeliveryState::Pending, $deliveries[0]->state);

        $delivery = $this->em->find(CardVerdictDelivery::class, $deliveries[0]->id);
        self::assertInstanceOf(CardVerdictDelivery::class, $delivery);
        $delivery->state = CardVerdictDeliveryState::Refused;
        $delivery->reason = CardVerdictDelivery::REASON_CONNECTION_EXPIRED;
        $this->em->flush();

        $panel = $this->call($client, Request::METHOD_GET, $this->panelPath($card), $raw);

        self::assertSame($sent['verdictId'], $panel['latestVerdict']['id']);
        self::assertSame('request-changes', $panel['latestVerdict']['kind']);
        self::assertSame('Please fix the footer.', $panel['latestVerdict']['message']);
        self::assertSame([[
            'pullRequestId' => (string) $pullRequest->id,
            'label' => 'acme/widgets#7',
            'state' => 'refused',
            'reason' => 'connection-expired',
            'reviewUrl' => null,
        ]], $panel['latestVerdict']['deliveries']);
    }

    public function test_the_panel_flags_a_pull_request_the_reviewer_opened_only_when_the_forge_account_is_known(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'verdict-api-own@example.com');
        $card = $this->card($project);
        $this->linkedPullRequest($card, 7, authorId: '4242');
        $this->linkedPullRequest($card, 8, authorId: '9999');
        $this->em->flush();

        $data = $this->call($client, Request::METHOD_GET, $this->panelPath($card), $raw);

        self::assertSame([false, false], array_column($data['pullRequests'], 'ownPullRequest'));

        $this->connectGitHub($project, 4242);

        $data = $this->call($client, Request::METHOD_GET, $this->panelPath($card), $raw);

        self::assertSame(['state' => 'connected'], $data['connection']);
        self::assertSame([true, false], array_column($data['pullRequests'], 'ownPullRequest'));
    }

    public function test_an_expired_connection_reads_expired_and_still_flags_the_own_pull_request(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'verdict-api-expired@example.com');
        $card = $this->card($project);
        $this->linkedPullRequest($card, 7, authorId: '4242');
        $this->connectGitHub($project, 4242, expired: true);

        $data = $this->call($client, Request::METHOD_GET, $this->panelPath($card), $raw);

        self::assertSame(['state' => 'expired'], $data['connection']);
        self::assertTrue($data['pullRequests'][0]['ownPullRequest']);
    }

    public function test_a_pending_note_lets_request_changes_and_comment_go_without_a_message(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'verdict-api-note-only@example.com');
        $card = $this->card($project);
        $this->note($card, 'The footer overlaps the launcher.');
        $this->em->flush();

        foreach (['request-changes' => '0198a2c0-0000-7000-8000-0000000000ab', 'comment' => '0198a2c0-0000-7000-8000-0000000000ac'] as $kind => $submissionId) {
            $sent = $this->call($client, Request::METHOD_POST, $this->sendPath($card), $raw, ['kind' => $kind, 'message' => '  ', 'submissionId' => $submissionId]);
            self::assertResponseStatusCodeSame(201);
            self::assertSame($kind, $sent['kind']);
            self::assertSame(1, $sent['noteCount']);
        }

        $this->em->clear();
        self::assertSame(['', ''], array_map(
            static fn (CardVerdict $verdict): string => $verdict->message,
            $this->service(CardVerdictRepository::class)->findAll(),
        ));
    }

    public function test_a_message_is_required_for_request_changes_and_comment_with_no_pending_note(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'verdict-api-empty@example.com');
        $card = $this->card($project);
        $this->em->flush();

        foreach (['request-changes', 'comment'] as $kind) {
            $data = $this->call($client, Request::METHOD_POST, $this->sendPath($card), $raw, ['kind' => $kind, 'message' => '  ', 'submissionId' => '0198a2c0-0000-7000-8000-0000000000aa']);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('message_required', $data['error']);
        }
        self::assertSame([], $this->service(CardVerdictRepository::class)->findAll());
    }

    public function test_a_pull_request_that_is_not_on_the_card_is_refused(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'verdict-api-foreign-pr@example.com');
        $card = $this->card($project);
        $other = $this->card($project, number: 2);
        $foreign = $this->linkedPullRequest($other, 9);
        $this->em->flush();

        $data = $this->call($client, Request::METHOD_POST, $this->sendPath($card), $raw, ['kind' => 'approve', 'pullRequestIds' => [(string) $foreign->id], 'submissionId' => '0198a2c0-0000-7000-8000-0000000000aa']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('pull_request_not_on_card', $data['error']);
        self::assertSame([], $this->service(CardVerdictRepository::class)->findAll());
    }

    public function test_a_retried_send_returns_the_saved_verdict_and_a_changed_one_is_refused(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'verdict-api-retry@example.com');
        $card = $this->card($project);
        $pullRequest = $this->linkedPullRequest($card, 7);
        $this->em->flush();
        $body = ['kind' => 'comment', 'pullRequestIds' => [(string) $pullRequest->id], 'message' => 'Looks odd.', 'submissionId' => '0198a2c0-0000-7000-8000-0000000000bb'];

        $first = $this->call($client, Request::METHOD_POST, $this->sendPath($card), $raw, $body);
        self::assertResponseStatusCodeSame(201);
        $again = $this->call($client, Request::METHOD_POST, $this->sendPath($card), $raw, $body);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($first['verdictId'], $again['verdictId']);

        $changed = $this->call($client, Request::METHOD_POST, $this->sendPath($card), $raw, [...$body, 'message' => 'Another thought.']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('submission_reused', $changed['error']);

        $this->em->clear();
        self::assertCount(1, $this->service(CardVerdictRepository::class)->findAll());
    }

    public function test_a_send_from_a_script_that_makes_no_submission_id_is_still_stored(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'verdict-api-legacy@example.com');
        $card = $this->card($project);
        $this->em->flush();

        $this->call($client, Request::METHOD_POST, $this->sendPath($card), $raw, ['kind' => 'approve']);

        self::assertResponseStatusCodeSame(201);
        $this->em->clear();
        self::assertNull($this->service(CardVerdictRepository::class)->findAll()[0]->submissionId);
    }

    public function test_an_unreadable_body_is_refused_before_the_handler_runs(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'verdict-api-invalid@example.com');
        $card = $this->card($project);
        $this->em->flush();

        foreach ([[], ['kind' => 'shrug', 'submissionId' => '0198a2c0-0000-7000-8000-0000000000aa'], ['kind' => 'approve', 'pullRequestIds' => ['nope'], 'submissionId' => '0198a2c0-0000-7000-8000-0000000000aa'], ['kind' => 'comment', 'message' => str_repeat('x', 10001), 'submissionId' => '0198a2c0-0000-7000-8000-0000000000aa'], ['kind' => 'approve', 'submissionId' => 'nope']] as $body) {
            $this->call($client, Request::METHOD_POST, $this->sendPath($card), $raw, $body);
            self::assertResponseStatusCodeSame(422);
        }
        self::assertSame([], $this->service(CardVerdictRepository::class)->findAll());
    }

    public function test_a_card_of_another_project_reads_as_absent(): void
    {
        $client = static::createClient();
        [$raw] = $this->projectWithToken($client, 'verdict-api-mine@example.com', 'verdict-api-mine');
        [, $foreignProject] = $this->projectWithToken($client, 'verdict-api-theirs@example.com', 'verdict-api-theirs');
        $foreign = $this->card($foreignProject);
        $this->em->flush();

        $this->call($client, Request::METHOD_GET, $this->panelPath($foreign), $raw);
        self::assertResponseStatusCodeSame(404);

        $this->call($client, Request::METHOD_POST, $this->sendPath($foreign), $raw, ['kind' => 'approve', 'submissionId' => '0198a2c0-0000-7000-8000-0000000000aa']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->service(CardVerdictRepository::class)->findAll());
    }

    public function test_a_card_in_a_done_column_takes_no_verdict(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'verdict-api-done@example.com');
        $card = $this->card($project, 'done');
        $this->em->flush();

        $data = $this->call($client, Request::METHOD_POST, $this->sendPath($card), $raw, ['kind' => 'approve', 'submissionId' => '0198a2c0-0000-7000-8000-0000000000aa']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('card_closed', $data['error']);
    }

    public function test_a_token_that_is_not_a_widget_token_is_refused(): void
    {
        $client = static::createClient();
        $scenario = new OAuthScenario(static::getContainer());
        $scenario->createClient();
        $user = $scenario->createUser('verdict-api-mcp@example.com');
        $project = $scenario->createProject($user, 'verdict-api-mcp');
        $this->seedColumns($project);
        $this->em()->flush();
        $card = $this->card($project);
        $this->em->flush();
        $raw = $scenario->accessTokenFor($client, $user, 'mcp', $project);

        $this->call($client, Request::METHOD_GET, $this->panelPath($card), $raw);
        self::assertResponseStatusCodeSame(403);
        $this->call($client, Request::METHOD_POST, $this->sendPath($card), $raw, ['kind' => 'approve', 'submissionId' => '0198a2c0-0000-7000-8000-0000000000aa']);
        self::assertResponseStatusCodeSame(403);
    }

    public function test_a_request_with_no_token_is_refused(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/api/board/cards/0198a2c0-0000-7000-8000-000000000001/verdict');
        self::assertResponseStatusCodeSame(401);

        $client->request(Request::METHOD_POST, '/api/board/cards/0198a2c0-0000-7000-8000-000000000001/verdicts', server: ['CONTENT_TYPE' => 'application/json'], content: '{"kind":"approve"}');
        self::assertResponseStatusCodeSame(401);
    }

    public function test_the_widget_origin_may_call_both_routes(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'verdict-api-cors@example.com');
        $card = $this->card($project);
        $this->em->flush();

        $client->request(Request::METHOD_OPTIONS, $this->sendPath($card), server: ['HTTP_ORIGIN' => 'https://app.localhost']);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('https://app.localhost', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));

        $this->call($client, Request::METHOD_GET, $this->panelPath($card), $raw);
        self::assertSame('https://app.localhost', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = static::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    private function panelPath(Card $card): string
    {
        return '/api/board/cards/'.$card->id.'/verdict';
    }

    private function sendPath(Card $card): string
    {
        return '/api/board/cards/'.$card->id.'/verdicts';
    }

    /**
     * @param non-empty-string $email
     *
     * @return array{0: string, 1: Project}
     */
    private function projectWithToken(KernelBrowser $client, string $email, string $name = 'verdict-api'): array
    {
        $scenario = new OAuthScenario(static::getContainer());
        $scenario->createClient();
        $user = $scenario->createUser($email);
        $project = $scenario->createProject($user, $name);
        $this->seedColumns($project);
        $this->em()->flush();
        $raw = $scenario->accessTokenFor($client, $user, 'site-review', $project);

        return [$raw, AgentCredential::managed($this->em(), $project, $project->id)];
    }

    private function connectGitHub(Project $project, int $githubUserId, bool $expired = false): void
    {
        $em = $this->em();
        $owner = $em->find(User::class, $project->owner->id);
        self::assertInstanceOf(User::class, $owner);
        $connection = new GitHubUserConnection($owner, $githubUserId, 'octocat', 'access', 'refresh', new \DateTimeImmutable('+8 hours'), new \DateTimeImmutable('+6 months'), new \DateTimeImmutable());
        if ($expired) {
            $connection->expiredAt = new \DateTimeImmutable();
        }
        $em->persist($connection);
        $em->flush();
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        return $em;
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @return array<string, mixed>
     */
    private function call(KernelBrowser $client, string $method, string $path, string $raw, ?array $json = null): array
    {
        $client->request($method, $path,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw, 'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://app.localhost'],
            content: null === $json ? null : json_encode($json, \JSON_THROW_ON_ERROR));
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        return \is_array($data) ? $data : [];
    }
}
