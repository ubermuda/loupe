<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Repository\CardLinkRepository;
use App\Module\Board\Repository\CardRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class CardLinkFormTest extends WebTestCase
{
    use BoardScenario;

    public function test_the_edit_form_writes_a_link_that_the_other_card_reads_back(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'link-form-edit@example.com');
        $project = $this->project($em, $owner);
        $a = $this->card($em, $project, 'Ship the schema');
        $b = $this->card($em, $project, 'Ship the page');
        $aId = $a->id;
        $bId = $b->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$aId.'/edit');
        self::assertResponseIsSuccessful();
        $this->submitRows($client, $crawler, 'Save card', [['card' => (string) $bId, 'kind' => CardLinkKind::Blocks->value]]);

        self::assertResponseRedirects();
        $em->clear();
        $b = $em->find(Card::class, $bId);
        self::assertInstanceOf(Card::class, $b);
        $links = static::getContainer()->get(CardLinkRepository::class)->findForCard($b);
        self::assertCount(1, $links);
        self::assertSame((string) $aId, (string) $links[0]->otherThan($b)->id);
        self::assertSame(CardLinkKind::BlockedBy, $links[0]->kindFor($b));
    }

    public function test_a_row_that_names_the_card_itself_is_refused(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'link-form-self@example.com');
        $project = $this->project($em, $owner);
        $a = $this->card($em, $project, 'Ship the schema');
        $aId = $a->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$aId.'/edit');
        $crawler = $this->submitRows($client, $crawler, 'Save card', [['card' => (string) $aId, 'kind' => CardLinkKind::RelatesTo->value]]);

        // The choice list leaves the card itself out, so the card field refuses it before the handler runs.
        self::assertResponseStatusCodeSame(422);
        self::assertNotSame('', trim($crawler->filter('[data-card-link-row] .lp-field-errors')->text()));
        self::assertSame(0, static::getContainer()->get(CardLinkRepository::class)->count([]));
    }

    public function test_the_same_card_twice_shows_the_domain_error_on_the_linked_cards_field(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'link-form-twice@example.com');
        $project = $this->project($em, $owner);
        $a = $this->card($em, $project, 'Ship the schema');
        $b = $this->card($em, $project, 'Ship the page');
        $aId = $a->id;
        $bId = $b->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$aId.'/edit');
        $crawler = $this->submitRows($client, $crawler, 'Save card', [
            ['card' => (string) $bId, 'kind' => CardLinkKind::Blocks->value],
            ['card' => (string) $bId, 'kind' => CardLinkKind::RelatesTo->value],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Each card can be linked only once.', $crawler->filter('[data-card-links] > .lp-field-errors')->text());
        self::assertSame(0, static::getContainer()->get(CardLinkRepository::class)->count([]));
    }

    public function test_saving_with_no_rows_removes_every_link(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'link-form-clear@example.com');
        $project = $this->project($em, $owner);
        $a = $this->card($em, $project, 'Ship the schema');
        $b = $this->card($em, $project, 'Ship the page');
        $c = $this->card($em, $project, 'Ship the docs');
        $em->persist(new CardLink($a, $b, CardLinkKind::Blocks));
        $em->persist(new CardLink($c, $a, CardLinkKind::RelatesTo));
        $em->flush();
        $aId = $a->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$aId.'/edit');
        // Guard: the form rendered both rows, so an empty submit means the person removed them.
        self::assertCount(2, $crawler->filter('[data-card-link-row]'));
        $this->submitRows($client, $crawler, 'Save card', []);

        self::assertResponseRedirects();
        self::assertSame(0, static::getContainer()->get(CardLinkRepository::class)->count([]));
    }

    public function test_the_create_form_creates_the_card_with_its_link(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'link-form-create@example.com');
        $project = $this->project($em, $owner);
        $b = $this->card($em, $project, 'Ship the page');
        $bId = $b->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/new');
        self::assertResponseIsSuccessful();
        $this->submitRows($client, $crawler, 'Create card', [['card' => (string) $bId, 'kind' => CardLinkKind::RelatesTo->value]], ['title' => 'Ship the schema']);

        self::assertResponseRedirects();
        $em->clear();
        $created = static::getContainer()->get(CardRepository::class)->findOneBy(['title' => 'Ship the schema']);
        self::assertInstanceOf(Card::class, $created);
        $links = static::getContainer()->get(CardLinkRepository::class)->findForCard($created);
        self::assertCount(1, $links);
        self::assertSame((string) $bId, (string) $links[0]->otherThan($created)->id);
        self::assertSame(CardLinkKind::RelatesTo, $links[0]->kindFor($created));
    }

    public function test_the_edit_form_shows_each_link_as_the_card_reads_it(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'link-form-prefill@example.com');
        $project = $this->project($em, $owner);
        $a = $this->card($em, $project, 'Ship the schema');
        $b = $this->card($em, $project, 'Ship the page');
        $em->persist(new CardLink($a, $b, CardLinkKind::Blocks));
        $em->flush();
        $bId = $b->id;
        $aId = $a->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$bId.'/edit');
        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('[data-card-link-row]');
        self::assertCount(1, $rows);
        self::assertSame((string) $aId, $rows->filter('select[name$="[card]"] option[selected]')->attr('value'));
        self::assertSame('#1 Ship the schema', trim($rows->filter('select[name$="[card]"] option[selected]')->text()));
        self::assertSame(CardLinkKind::BlockedBy->value, $rows->filter('select[name$="[kind]"] option[selected]')->attr('value'));
        // Each row carries a remove button, as a row the add button makes does.
        self::assertCount(1, $rows->filter('button[data-action="form-collection#removeRow"]'));
    }

    /**
     * Posts the form with these link rows. A browser adds rows through the
     * prototype, which the crawler cannot run, so the rows go into the values.
     *
     * @param list<array{card: string, kind: string}> $rows
     * @param array<string, string>                   $fields
     */
    private function submitRows(KernelBrowser $client, Crawler $crawler, string $button, array $rows, array $fields = []): Crawler
    {
        $form = $crawler->selectButton($button)->form();
        $values = $form->getPhpValues();
        self::assertIsArray($values['create_card_form']);
        unset($values['create_card_form']['relatedCards']);
        if ([] !== $rows) {
            $values['create_card_form']['relatedCards'] = $rows;
        }
        foreach ($fields as $name => $value) {
            $values['create_card_form'][$name] = $value;
        }

        return $client->request($form->getMethod(), $form->getUri(), $values);
    }
}
