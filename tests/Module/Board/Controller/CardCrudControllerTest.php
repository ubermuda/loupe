<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardOrigin;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\CardRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class CardCrudControllerTest extends WebTestCase
{
    use BoardScenario;

    public function test_the_owner_creates_a_card_with_pull_request_links(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'card-create@example.com');
        $project = $this->project($em, $owner);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/new');
        self::assertResponseIsSuccessful();

        $client->submitForm('Create card', [
            'create_card_form[title]' => 'Ship the board',
            'create_card_form[body]' => "It needs **columns**.\n",
            'create_card_form[type]' => CardType::Tooling->value,
            'create_card_form[priority]' => (string) CardPriority::High->value,
            'create_card_form[status]' => CardStatus::Next->value,
            'create_card_form[pullRequestUrls]' => "https://github.com/loupe/loupe/pull/12\n\nnot-a-known-forge\n",
        ]);

        self::assertResponseRedirects();
        $em->clear();
        $cards = static::getContainer()->get(CardRepository::class);
        $created = $cards->findOneBy(['title' => 'Ship the board']);
        self::assertInstanceOf(Card::class, $created);
        self::assertSame(CardStatus::Next, $created->status);
        self::assertSame(CardPriority::High, $created->priority);
        self::assertSame(CardType::Tooling, $created->type);
        // A form is a person writing the card down, whatever an agent does later.
        self::assertSame(CardOrigin::Human, $created->origin);
        self::assertCount(2, $created->pullRequests);
    }

    public function test_a_blank_title_re_renders_the_create_form_with_422(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'card-create-invalid@example.com');
        $project = $this->project($em, $owner);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/new');
        $client->submitForm('Create card', ['create_card_form[title]' => '   ']);

        self::assertResponseStatusCodeSame(422);
    }

    public function test_the_owner_edits_a_card_and_replaces_its_links(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'card-edit@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Before', CardStatus::Backlog, CardPriority::Low);
        $cardId = $card->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(
            Request::METHOD_GET,
            '/projects/'.$project->id.'/board/cards/'.$cardId.'/edit',
        );
        self::assertResponseIsSuccessful();
        self::assertSame('Before', $crawler->filter('#create_card_form_title')->attr('value'));

        $client->submitForm('Save card', [
            'create_card_form[title]' => 'After',
            'create_card_form[body]' => 'Rewritten.',
            'create_card_form[type]' => CardType::Bug->value,
            'create_card_form[priority]' => (string) CardPriority::High->value,
            'create_card_form[status]' => CardStatus::InProgress->value,
            'create_card_form[pullRequestUrls]' => 'https://github.com/loupe/loupe/pull/99',
        ]);

        self::assertResponseRedirects();
        $em->clear();
        $fresh = $em->find(Card::class, $cardId);
        self::assertInstanceOf(Card::class, $fresh);
        self::assertSame('After', $fresh->title);
        self::assertSame(CardStatus::InProgress, $fresh->status);
        self::assertSame(CardPriority::High, $fresh->priority);
        self::assertCount(1, $fresh->pullRequests);
        $link = $fresh->pullRequests->first();
        self::assertInstanceOf(CardPullRequest::class, $link);
        self::assertSame('https://github.com/loupe/loupe/pull/99', $link->url);
    }

    public function test_an_emptied_url_box_clears_every_link(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'card-clear-links@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Linked');
        $card->replacePullRequests(new CardPullRequest(
            card: $card,
            url: 'https://github.com/loupe/loupe/pull/1',
        ));
        $em->flush();
        $cardId = $card->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$cardId.'/edit');
        $client->submitForm('Save card', [
            'create_card_form[title]' => 'Linked',
            'create_card_form[pullRequestUrls]' => '',
        ]);

        self::assertResponseRedirects();
        $em->clear();
        $fresh = $em->find(Card::class, $cardId);
        self::assertInstanceOf(Card::class, $fresh);
        self::assertCount(0, $fresh->pullRequests);
    }

    public function test_the_owner_deletes_a_card_through_the_confirmation(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'card-delete@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Doomed');
        $cardId = $card->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(
            Request::METHOD_GET,
            '/projects/'.$project->id.'/board/cards/'.$cardId,
        );
        self::assertResponseIsSuccessful();

        $client->submit($crawler->filter('form[action$="/delete"]')->form());

        self::assertResponseRedirects('/projects/'.$project->id.'/board');
        $em->clear();
        self::assertNull($em->find(Card::class, $cardId));
    }

    public function test_deleting_without_a_token_is_forbidden(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'card-delete-untokened@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Still here');
        $cardId = $card->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(
            Request::METHOD_POST,
            '/projects/'.$project->id.'/board/cards/'.$cardId.'/delete',
            ['_csrf_token' => 'invalid-token'],
        );

        self::assertResponseStatusCodeSame(403);
        $em->clear();
        self::assertInstanceOf(Card::class, $em->find(Card::class, $cardId));
    }

    public function test_a_stranger_cannot_reach_a_card(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'card-owner@example.com');
        $stranger = $this->user($em, 'card-stranger@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Private');
        $cardId = $card->id;
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$cardId);
        self::assertResponseStatusCodeSame(403);

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$cardId.'/edit');
        self::assertResponseStatusCodeSame(403);
    }

    public function test_a_card_from_another_project_is_not_found(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'card-crossed@example.com');
        $mine = $this->project($em, $owner, 'mine');
        $other = $this->project($em, $owner, 'other');
        $card = $this->card($em, $other, 'Elsewhere');
        $cardId = $card->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$mine->id.'/board/cards/'.$cardId);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_creating_is_not_found_while_the_flag_is_off(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'card-create-flag-off@example.com');
        $project = $this->project($em, $owner);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/new');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_link_with_an_unsafe_scheme_is_shown_but_never_href(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'card-unsafe-link@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Carries a hostile link');
        // A link is stored as given by whoever wrote the card, so the render is
        // the last place that can refuse the scheme.
        $card->replacePullRequests(
            new CardPullRequest(card: $card, url: 'javascript:alert(1)'),
            new CardPullRequest(card: $card, url: 'https://github.com/loupe/loupe/pull/4'),
        );
        $em->flush();
        $cardId = $card->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$cardId);

        self::assertResponseIsSuccessful();
        $hrefs = $crawler->filter('.lp-card-pulls__item a')->each(
            static fn (Crawler $node): string => (string) $node->attr('href'),
        );
        // Guard: the safe link proves the list rendered at all.
        self::assertSame(['https://github.com/loupe/loupe/pull/4'], $hrefs);
        // Shown, not hidden: the reader still sees what the card carries.
        self::assertStringContainsString('javascript:alert(1)', $crawler->filter('.lp-card-pulls')->text());
    }
}
