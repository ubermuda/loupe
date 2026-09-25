<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Install\BoardInstallFlags;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\OAuthScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class AddFeedbackApiTest extends WebTestCase
{
    use BoardColumnFixtures;

    public function test_a_note_creates_its_card_and_the_answer_names_it(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'feedback-api-new@example.com');

        $data = $this->post($client, $raw, $this->note(['newCard' => new \stdClass()]));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $data['number']);
        self::assertSame('#1 Footer overlaps the launcher', $data['label']);
        self::assertStringContainsString($data['cardId'], $data['url']);
        self::assertIsString($data['commentId']);

        $cards = $this->service(CardRepository::class)->findBy(['project' => $project]);
        self::assertCount(1, $cards);
        self::assertSame(CardType::SiteReview, $cards[0]->type);
        $links = $this->service(CardSiteReviewCommentRepository::class)->findForCard($cards[0]);
        self::assertCount(1, $links);
        self::assertTrue($links[0]->createdCard);
        self::assertSame($data['commentId'], (string) $links[0]->comment->id);
    }

    public function test_a_note_goes_under_an_epic(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'feedback-api-epic@example.com');
        $epic = $this->card($project, 'backlog', 1, CardType::Epic);

        $data = $this->post($client, $raw, $this->note(['newCard' => ['parentCardId' => (string) $epic->id]]));

        self::assertResponseStatusCodeSame(201);
        $card = $this->service(CardRepository::class)->find($data['cardId']);
        self::assertInstanceOf(Card::class, $card);
        self::assertSame((string) $epic->id, (string) $card->parent?->id);
    }

    /**
     * The listener that links a comment by its `card:` context still runs on
     * the old route. Through this one the target decides, and a second link
     * would break the unique comment column.
     */
    public function test_a_card_context_on_the_page_adds_no_second_link(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'feedback-api-context@example.com');
        $target = $this->card($project, 'backlog', 1);
        $other = $this->card($project, 'backlog', 2);
        $links = $this->service(CardSiteReviewCommentRepository::class);

        $this->post($client, $raw, $this->note(['cardId' => (string) $target->id], ['context' => 'card:'.$other->id]));
        self::assertResponseStatusCodeSame(201);
        $this->post($client, $raw, $this->note(['cardId' => (string) $target->id], ['context' => 'card:'.$target->id, 'body' => 'Second note']));
        self::assertResponseStatusCodeSame(201);

        self::assertCount(2, $links->findForCard($target));
        self::assertSame([], $links->findForCard($other));
        foreach ($links->findForCard($target) as $link) {
            self::assertFalse($link->createdCard);
        }
    }

    public function test_a_retried_delivery_answers_with_the_same_card(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'feedback-api-retry@example.com');
        $note = $this->note(['newCard' => new \stdClass()], ['deliveryId' => '0199c0de-0000-4000-8000-000000000001']);

        $first = $this->post($client, $raw, $note);
        $second = $this->post($client, $raw, $note);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($first, $second);
        self::assertCount(1, $this->service(CardRepository::class)->findBy(['project' => $project]));
    }

    public function test_a_closed_card_is_refused_by_name(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'feedback-api-closed@example.com');
        $done = $this->card($project, 'done', 1);

        $data = $this->post($client, $raw, $this->note(['cardId' => (string) $done->id]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'target_closed'], $data);
    }

    public function test_the_board_switched_off_is_a_conflict(): void
    {
        $client = static::createClient();
        [$raw] = $this->projectWithToken($client, 'feedback-api-off@example.com');
        $this->setBoardEnabled(false);

        $data = $this->post($client, $raw, $this->note(['newCard' => new \stdClass()]));

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'board_disabled'], $data);
    }

    /** @return iterable<string, array{mixed}> */
    public static function badTargets(): iterable
    {
        yield 'absent' => [null];
        yield 'empty' => [new \stdClass()];
        yield 'both' => [['cardId' => '0199c0de-0000-7000-8000-0000000000ff', 'newCard' => new \stdClass()]];
        yield 'not a uuid' => [['cardId' => 'nope']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badTargets')]
    public function test_a_malformed_target_fails_validation(mixed $target): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'feedback-api-bad@example.com');

        $note = $this->note([]);
        if (null === $target) {
            unset($note['target']);
        } else {
            $note['target'] = $target;
        }
        $this->post($client, $raw, $note);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->service(CardRepository::class)->findBy(['project' => $project]));
    }

    public function test_the_widget_can_call_it_from_another_origin(): void
    {
        $client = static::createClient();
        [$raw] = $this->projectWithToken($client, 'feedback-api-cors@example.com');

        $client->request(Request::METHOD_OPTIONS, '/api/board/feedback', server: ['HTTP_ORIGIN' => 'https://app.localhost']);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('https://app.localhost', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));

        $this->post($client, $raw, $this->note(['newCard' => new \stdClass()]));
        self::assertSame('https://app.localhost', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_an_account_token_is_refused(): void
    {
        $client = static::createClient();
        $scenario = new OAuthScenario(static::getContainer());
        $scenario->createClient();
        $user = $scenario->createUser('feedback-api-mcp@example.com');
        $project = $scenario->createProject($user, 'feedback-api-mcp');
        $raw = $scenario->accessTokenFor($client, $user, 'mcp', $project);

        $this->post($client, $raw, $this->note(['newCard' => new \stdClass()]));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function note(array $target, array $extra = []): array
    {
        return $extra + [
            'body' => "Footer overlaps the launcher\nSeen at 1280px.",
            'url' => 'https://app.localhost/checkout',
            'anchors' => [['selector' => '.footer', 'text' => 'Footer']],
            'target' => $target,
        ];
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return array<string, mixed>
     */
    private function post(KernelBrowser $client, string $raw, array $json): array
    {
        $client->request(Request::METHOD_POST, '/api/board/feedback',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw, 'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://app.localhost'],
            content: json_encode($json, \JSON_THROW_ON_ERROR));

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
        $project = $scenario->createProject($user, 'feedback-api');
        $this->seedColumns($project);
        $this->em()->flush();
        $raw = $scenario->accessTokenFor($client, $user, 'site-review', $project);
        $this->setBoardEnabled(true);

        return [$raw, AgentCredential::managed($this->em(), $project, $project->id)];
    }

    private function card(Project $project, string $slug, int $number, CardType $type = CardType::Feature): Card
    {
        $card = new Card($project, $this->column($project, $slug), 'Existing card', '', $number, $type);
        $this->em()->persist($card);
        $this->em()->flush();

        return $card;
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
