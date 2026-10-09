<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardSource;
use App\Module\Board\Entity\CardSourceKind;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Command\BindWorkflowTemplateHandler;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\OAuthScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class BoardCardsApiTest extends WebTestCase
{
    use BoardColumnFixtures;

    private const array GROUPING_TYPES = [
        ['key' => 'bug', 'label' => 'Bug', 'tone' => 'amber'],
        ['key' => 'initiative', 'label' => 'Initiative', 'tone' => 'blue', 'capabilities' => ['children', 'lane']],
        ['key' => 'epic', 'label' => 'Epic', 'tone' => 'lime', 'capabilities' => ['children']],
    ];

    private const array NO_GROUPING_TYPE = [
        ['key' => 'bug', 'label' => 'Bug', 'tone' => 'amber'],
    ];

    public function test_a_reviewer_creates_a_card_and_it_records_who_raised_it(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'cards-api-create@example.com');

        $this->api($client, Request::METHOD_POST, '/api/board/cards', $raw, [
            'title' => 'Footer overlaps the launcher',
            'body' => 'Seen at 1280px.',
            'type' => 'bug',
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame(1, $data['number']);
        self::assertSame('#1 Footer overlaps the launcher', $data['label']);
        self::assertStringContainsString($data['cardId'], $data['url']);

        $cards = static::getContainer()->get(CardRepository::class)->findBy(['project' => $project]);
        self::assertCount(1, $cards);
        // Not Human. Nobody authenticated the person who typed it.
        self::assertSame(Actor::Reviewer, $cards[0]->reporter);
        self::assertEquals(new CardSource(CardSourceKind::Widget), $cards[0]->source);
        // The endpoint accepts neither, so a reviewer cannot file into a column
        // or attach a URL of their choosing.
        self::assertSame('backlog', $cards[0]->column->slug);
        self::assertCount(0, $cards[0]->pullRequests);
    }

    public function test_the_picker_lists_open_cards_newest_first_and_can_narrow_them(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'cards-api-list@example.com');
        $em = $this->em();

        $em->persist(new Card($project, $this->column($project, 'backlog'), 'Footer overlaps the launcher', '', 1));
        $em->persist(new Card($project, $this->column($project, 'backlog'), 'Rotate the signing key', '', 2));
        $done = new Card($project, $this->column($project, 'done'), 'Footer was fixed once already', '', 3);
        $em->persist($done);
        $em->flush();

        $this->api($client, Request::METHOD_GET, '/api/board/cards', $raw);
        self::assertResponseIsSuccessful();
        $all = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($all);
        // Newest first, and Done is absent: a picker attaches feedback to work
        // in flight.
        self::assertSame([2, 1], array_column($all['cards'], 'number'));

        $this->api($client, Request::METHOD_GET, '/api/board/cards?q=footer', $raw);
        $narrowed = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($narrowed);
        self::assertSame([1], array_column($narrowed['cards'], 'number'));
    }

    public function test_the_picker_can_ask_for_open_epics_alone(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'cards-api-epics@example.com');
        $em = $this->em();

        $em->persist(new Card($project, $this->column($project, 'backlog'), 'Checkout review', '', 1, type: 'epic'));
        $em->persist(new Card($project, $this->column($project, 'backlog'), 'Footer overlaps', '', 2));
        $em->persist(new Card($project, $this->column($project, 'done'), 'Old review', '', 3, type: 'epic'));
        $em->flush();

        $this->api($client, Request::METHOD_GET, '/api/board/cards?type=epic', $raw);
        self::assertResponseIsSuccessful();
        self::assertSame([1], array_column(
            json_decode((string) $client->getResponse()->getContent(), true)['cards'],
            'number',
        ));

        // A type the board does not know filters nothing out rather than
        // failing, so the list stays usable for a newer widget.
        $this->api($client, Request::METHOD_GET, '/api/board/cards?type=nonsense', $raw);
        self::assertSame([2, 1], array_column(
            json_decode((string) $client->getResponse()->getContent(), true)['cards'],
            'number',
        ));
    }

    public function test_the_widget_creates_a_review_card_of_the_default_type_and_an_epic(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'cards-api-types@example.com');

        $this->api($client, Request::METHOD_POST, '/api/board/cards', $raw, ['title' => 'Review: /pricing']);
        self::assertResponseStatusCodeSame(201);
        $this->api($client, Request::METHOD_POST, '/api/board/cards', $raw, ['title' => 'Review: /checkout', 'type' => 'epic']);
        self::assertResponseStatusCodeSame(201);

        $cards = static::getContainer()->get(CardRepository::class)->findBy(['project' => $project], ['number' => 'ASC']);
        self::assertSame(['feature', 'epic'], array_map(static fn (Card $card) => $card->type, $cards));
        self::assertSame([Actor::Reviewer, Actor::Reviewer], array_map(static fn (Card $card) => $card->reporter, $cards));
    }

    public function test_the_widget_cannot_create_a_card_of_an_undeclared_type(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'cards-api-unknown-type@example.com');

        $this->api($client, Request::METHOD_POST, '/api/board/cards', $raw, ['title' => 'Review: /pricing', 'type' => 'site-review']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['feature', 'bug', 'security', 'tooling', 'docs', 'idea', 'epic'], json_decode((string) $client->getResponse()->getContent(), true)['types']);
        self::assertSame([], static::getContainer()->get(CardRepository::class)->findBy(['project' => $project]));
    }

    public function test_the_picker_lists_the_cards_of_every_type_that_can_have_children(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, $project] = $this->projectWithToken($client, 'cards-api-parent-list@example.com');
        $this->declareTypes($project, self::GROUPING_TYPES);
        $em = $this->em();

        $em->persist(new Card($project, $this->column($project, 'backlog'), 'Q4 initiative', '', 1, type: 'initiative'));
        $em->persist(new Card($project, $this->column($project, 'backlog'), 'Checkout review', '', 2, type: 'epic'));
        $em->persist(new Card($project, $this->column($project, 'backlog'), 'Footer overlaps', '', 3, type: 'bug'));
        $em->persist(new Card($project, $this->column($project, 'done'), 'Old review', '', 4, type: 'epic'));
        $em->flush();

        $this->api($client, Request::METHOD_GET, '/api/board/cards?parent=1', $raw);

        self::assertResponseIsSuccessful();
        self::assertSame([2, 1], array_column(json_decode((string) $client->getResponse()->getContent(), true)['cards'], 'number'));
    }

    public function test_the_picker_lists_nothing_when_no_type_can_have_children(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, $project] = $this->projectWithToken($client, 'cards-api-parent-none@example.com');
        $this->declareTypes($project, self::NO_GROUPING_TYPE);
        $this->em()->persist(new Card($project, $this->column($project, 'backlog'), 'Footer overlaps', '', 1, type: 'bug'));
        $this->em()->flush();

        $this->api($client, Request::METHOD_GET, '/api/board/cards?parent=1', $raw);

        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode((string) $client->getResponse()->getContent(), true)['cards']);
    }

    public function test_a_parent_card_takes_the_type_the_reviewer_chose(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, $project] = $this->projectWithToken($client, 'cards-api-parent-create@example.com');
        $this->declareTypes($project, self::GROUPING_TYPES);

        $this->api($client, Request::METHOD_POST, '/api/board/cards', $raw, ['title' => 'Q4', 'parent' => true, 'type' => 'epic']);
        self::assertResponseStatusCodeSame(201);
        $this->api($client, Request::METHOD_POST, '/api/board/cards', $raw, ['title' => 'Q5', 'parent' => true]);
        self::assertResponseStatusCodeSame(201);

        $cards = static::getContainer()->get(CardRepository::class)->findBy(['project' => $project], ['number' => 'ASC']);
        self::assertSame(['epic', 'initiative'], array_map(static fn (Card $card) => $card->type, $cards));
    }

    public function test_a_parent_card_refuses_a_type_that_cannot_have_children(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, $project] = $this->projectWithToken($client, 'cards-api-parent-refuse@example.com');
        $this->declareTypes($project, self::GROUPING_TYPES);

        $this->api($client, Request::METHOD_POST, '/api/board/cards', $raw, ['title' => 'Q4', 'parent' => true, 'type' => 'bug']);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('unknown_type', $data['error']);
        self::assertSame(['initiative', 'epic'], $data['types']);
        self::assertSame([], static::getContainer()->get(CardRepository::class)->findBy(['project' => $project]));
    }

    public function test_a_parent_card_is_refused_when_no_type_can_have_children(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, $project] = $this->projectWithToken($client, 'cards-api-parent-nogroup@example.com');
        $this->declareTypes($project, self::NO_GROUPING_TYPE);

        $this->api($client, Request::METHOD_POST, '/api/board/cards', $raw, ['title' => 'Q4', 'parent' => true]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Gives the project a template whose types are the given ones, as the
     * workflow template parser stores them.
     *
     * @param list<array<string, mixed>> $types
     */
    private function declareTypes(Project $project, array $types): void
    {
        $handler = static::getContainer()->get(BindWorkflowTemplateHandler::class);
        self::assertInstanceOf(BindWorkflowTemplateHandler::class, $handler);
        $binding = $handler(new BindWorkflowTemplateCommand($project, 'simple', []));
        $definition = $binding->definition;
        $definition['types'] = $types;
        $definition['defaultType'] = 'bug';
        $binding->definition = $definition;
        $this->em()->flush();
    }

    /**
     * The first version of this test asserted that searching `%` returned
     * nothing, and it passed against a broken escape: the pattern became a
     * search for a backslash, which matched nothing either. A test whose
     * assertion holds for the wrong reason is worse than none, so this one
     * asserts a positive match on a card whose title really contains the
     * wildcard, and that the other card is excluded.
     */
    public function test_a_wildcard_in_the_search_matches_only_a_literal_one(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'cards-api-wildcard@example.com');
        $em = $this->em();

        $backlog = $this->column($project, 'backlog');
        $em->persist(new Card($project, $backlog, 'Rotate the signing key', '', 1));
        $em->persist(new Card($project, $backlog, 'Progress bar sticks at 50% forever', '', 2));
        $em->persist(new Card($project, $backlog, 'Rename user_id across the export', '', 3));
        $em->flush();

        $this->api($client, Request::METHOD_GET, '/api/board/cards?q='.urlencode('%'), $raw);
        self::assertSame([2], array_column(
            json_decode((string) $client->getResponse()->getContent(), true)['cards'],
            'number',
        ));

        $this->api($client, Request::METHOD_GET, '/api/board/cards?q='.urlencode('_'), $raw);
        self::assertSame([3], array_column(
            json_decode((string) $client->getResponse()->getContent(), true)['cards'],
            'number',
        ));
    }

    public function test_an_account_token_cannot_reach_the_board(): void
    {
        $client = static::createClient();
        $scenario = new OAuthScenario(static::getContainer());
        $scenario->createClient();
        $user = $scenario->createUser('cards-api-mcp@example.com');
        $project = $scenario->createProject($user, 'cards-api-mcp');
        $raw = $scenario->accessTokenFor($client, $user, 'mcp', $project);

        $this->api($client, Request::METHOD_GET, '/api/board/cards', $raw);

        // The access_control line names ROLE_API_SITE_REVIEW, so an MCP token
        // is refused by the firewall rather than by the controller.
        self::assertResponseStatusCodeSame(403);
    }

    public function test_the_widget_can_call_it_from_another_origin(): void
    {
        $client = static::createClient();
        [$raw] = $this->projectWithToken($client, 'cards-api-cors@example.com');

        $this->api($client, Request::METHOD_GET, '/api/board/cards', $raw);

        // The CORS subscriber tested one path prefix before this endpoint
        // existed, so without widening it the widget's own call would be
        // blocked by the browser with nothing in the server log.
        self::assertSame(
            'https://app.localhost',
            $client->getResponse()->headers->get('Access-Control-Allow-Origin'),
        );
    }

    /**
     * A project with a site-review credential of its owner. The helper takes
     * the credential last, because the authorization flow detaches every
     * entity the test holds before it.
     *
     * @param non-empty-string $email
     *
     * @return array{0: string, 1: Project}
     */
    private function projectWithToken(KernelBrowser $client, string $email, string $name = 'cards-api'): array
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

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /** @param array<string, mixed>|null $json */
    private function api(KernelBrowser $client, string $method, string $path, string $raw, ?array $json = null): void
    {
        $client->request($method, $path,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw, 'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://app.localhost'],
            content: null === $json ? null : json_encode($json, \JSON_THROW_ON_ERROR));
    }
}
