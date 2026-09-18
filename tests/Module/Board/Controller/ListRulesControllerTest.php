<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\BridgeRuleReport;
use App\Module\Project\Entity\Project;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class ListRulesControllerTest extends WebTestCase
{
    public function test_search_matches_names_and_distinguishes_no_matches_from_no_reports(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = new User(fullName: 'Owner', email: 'rules-search@example.com', password: 'x');
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($owner, static::getContainer());
        $project = new Project($owner, 'Search rules');
        $other = new Project($owner, 'Other rules');
        $em->persist($owner);
        $em->persist($project);
        $em->persist($other);
        foreach ([$project, $other] as $target) {
            $em->persist(new BridgeRuleReport($target, Uuid::v4(), [
                ['name' => 'Préparer', 'on' => 'board.card_moved', 'columns' => ['ready'], 'state' => BridgeRuleReport::STATE_LIVE, 'reason' => null],
                ['name' => 'Review', 'on' => 'board.card_moved', 'columns' => ['ready'], 'state' => BridgeRuleReport::STATE_DEAD, 'reason' => 'column_renamed'],
            ]));
        }
        $em->flush();
        $em->clear();
        $client->loginUser($owner);
        $path = '/projects/'.$project->id.'/rules';
        $crawler = $client->request(Request::METHOD_GET, $path);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-rule-name]'));

        $crawler = $client->submit($crawler->filter('form[name="search_rules_form"]')->form(['search_rules_form[search]' => ' PRÉP ']));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-rule-name="Préparer"]'));
        self::assertCount(1, $crawler->filter('[data-rule-name]'));
        self::assertSelectorTextContains('[data-rule-live-count]', '1 live rule');

        $crawler = $client->submit($crawler->filter('form[name="search_rules_form"]')->form(['search_rules_form[search]' => 'missing']));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-rule-name]'));
        self::assertSelectorTextContains('[data-rules-no-match]', 'No matching rules');
        self::assertSelectorTextContains('[data-rule-live-count]', '1 live rule');
        self::assertSelectorNotExists('[data-rules-empty]');

        $crawler = $client->click($crawler->filter('.lp-list-filters a.lp-filter-clear')->link());
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-rule-name]'));

        $client->request(Request::METHOD_GET, $path, ['search_rules_form' => ['search' => str_repeat('x', 201)]]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextContains('.lp-field-errors', '200');
        $client->request(Request::METHOD_GET, $path, ['search_rules_form' => ['search' => ['invalid']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_the_handoff_reads_as_labels_rather_than_identifiers(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = new User(fullName: 'Owner', email: 'rules-labels@example.com', password: 'x');
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($owner, static::getContainer());
        $project = new Project($owner, 'Labels');
        $em->persist($owner);
        $em->persist($project);
        $em->persist(new BoardColumn($project, 'Next up', 'next', 0));
        $em->persist(new BridgeRuleReport($project, Uuid::v4(), [
            ['name' => 'Known column', 'on' => 'board.card_moved', 'columns' => ['next'], 'state' => BridgeRuleReport::STATE_LIVE, 'reason' => null],
            ['name' => 'Lost column', 'on' => 'board.card_moved', 'columns' => ['gone'], 'state' => BridgeRuleReport::STATE_DEAD, 'reason' => null],
        ]));
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/rules');

        self::assertResponseIsSuccessful();
        $known = $crawler->filter('[data-rule-name="Known column"] .lp-rule-flow');
        self::assertStringContainsString('Card moved', $known->text());
        self::assertStringNotContainsString('board.card_moved', $known->text());
        self::assertStringContainsString('Next up', $known->text());
        self::assertStringNotContainsString('next,', $known->text());
        self::assertCount(1, $crawler->filter('[data-rule-name="Known column"] [data-rule-event="board.card_moved"] svg'));
        self::assertStringContainsString('gone', $crawler->filter('[data-rule-name="Lost column"] .lp-rule-flow')->text());
        self::assertCount(0, $crawler->filter('form[name="search_rules_form"] button[type="submit"]'));
        self::assertCount(1, $crawler->filter('form[name="search_rules_form"] [data-autosearch-target="clearButton"]'));
    }

    public function test_it_lists_reported_rule_health_without_edit_controls(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = new User(fullName: 'Owner', email: 'rules-owner@example.com', password: 'x');
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($owner, static::getContainer());
        $project = new Project($owner, 'Rules');
        $em->persist($owner);
        $em->persist($project);
        $em->persist(new BridgeRuleReport($project, Uuid::v4(), [[
            'name' => 'Send ready cards',
            'on' => 'board.card_moved',
            'columns' => ['ready'],
            'state' => BridgeRuleReport::STATE_DEAD,
            'reason' => 'Column ready is not configured.',
        ]]));
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/rules');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-rule-name="Send ready cards"]'));
        self::assertStringContainsString('Column ready is not configured.', $crawler->text());
        self::assertCount(0, $crawler->filter('[data-rule-name] form'));
    }
}
