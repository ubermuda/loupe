<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Install\BoardInstallFlags;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class BoardCardsApiTest extends WebTestCase
{
    public function test_a_reviewer_creates_a_card_and_it_records_who_raised_it(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw, $project] = $this->projectWithToken($em, 'cards-api-create@example.com');
        $this->enableBoard($em);

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
        self::assertSame(CardReporter::Reviewer, $cards[0]->reporter);
        // The endpoint accepts neither, so a reviewer cannot file into a column
        // or attach a URL of their choosing.
        self::assertSame(CardStatus::Backlog, $cards[0]->status);
        self::assertCount(0, $cards[0]->pullRequests);
    }

    public function test_the_picker_lists_open_cards_newest_first_and_can_narrow_them(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw, $project] = $this->projectWithToken($em, 'cards-api-list@example.com');
        $this->enableBoard($em);

        $em->persist(new Card($project, 'Footer overlaps the launcher', '', 1));
        $em->persist(new Card($project, 'Rotate the signing key', '', 2));
        $done = new Card($project, 'Footer was fixed once already', '', 3, status: CardStatus::Done);
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
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw, $project] = $this->projectWithToken($em, 'cards-api-wildcard@example.com');
        $this->enableBoard($em);

        $em->persist(new Card($project, 'Rotate the signing key', '', 1));
        $em->persist(new Card($project, 'Progress bar sticks at 50% forever', '', 2));
        $em->persist(new Card($project, 'Rename user_id across the export', '', 3));
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

    public function test_both_endpoints_are_absent_while_the_board_is_switched_off(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw] = $this->projectWithToken($em, 'cards-api-flag@example.com');

        $this->api($client, Request::METHOD_GET, '/api/board/cards', $raw);
        self::assertResponseStatusCodeSame(404);

        $this->api($client, Request::METHOD_POST, '/api/board/cards', $raw, ['title' => 'Nope']);
        self::assertResponseStatusCodeSame(404);
    }

    public function test_an_account_token_cannot_reach_the_board(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User(fullName: 'U', email: 'cards-api-mcp@example.com', password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        [$token, $raw] = ApiToken::issue($user, 'mcp', ApiTokenScope::Mcp);
        $em->persist($token);
        $em->flush();

        $this->api($client, Request::METHOD_GET, '/api/board/cards', $raw);

        // The access_control line names ROLE_API_SITE_REVIEW, so an MCP token
        // is refused by the firewall rather than by the controller.
        self::assertResponseStatusCodeSame(403);
    }

    public function test_the_widget_can_call_it_from_another_origin(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw] = $this->projectWithToken($em, 'cards-api-cors@example.com');
        $this->enableBoard($em);

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
     * @param non-empty-string $email
     *
     * @return array{0: string, 1: Project}
     */
    private function projectWithToken(EntityManagerInterface $em, string $email, string $name = 'cards-api'): array
    {
        $user = new User(fullName: 'U', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        [$token, $raw] = ApiToken::issue($user, 'widget', ApiTokenScope::SiteReview);
        $em->persist($token);
        $project = new Project($user, $name);
        $project->widgetToken = $token;
        $em->persist($project);
        $em->flush();

        return [$raw, $project];
    }

    private function enableBoard(EntityManagerInterface $em): void
    {
        $flags = static::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[BoardInstallFlags::FLAG_BOARD_ENABLED]->value = true;
        $em->flush();
    }

    /** @param array<string, mixed>|null $json */
    private function api(KernelBrowser $client, string $method, string $path, string $raw, ?array $json = null): void
    {
        $client->request($method, $path,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw, 'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://app.localhost'],
            content: null === $json ? null : json_encode($json, \JSON_THROW_ON_ERROR));
    }
}
