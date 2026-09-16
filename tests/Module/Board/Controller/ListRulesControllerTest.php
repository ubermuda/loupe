<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BridgeRuleReport;
use App\Module\Project\Entity\Project;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class ListRulesControllerTest extends WebTestCase
{
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
