<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Form\SetCardLaneFormType;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class EpicCardPagesTest extends WebTestCase
{
    use BoardScenario;

    public function test_the_epic_page_lists_its_children_and_counts_the_done_ones(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'epic-page-children@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'The epic'), CardType::Epic);
        $open = $this->childOf($em, $epic, $this->card($em, $project, 'Open child', 'next'));
        $done = $this->childOf($em, $epic, $this->card($em, $project, 'Done child', 'done'));
        $this->card($em, $project, 'Not a child');
        [$epicId, $openId, $doneId, $openNumber] = [$epic->id, $open->id, $done->id, $open->number];
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $this->cardUrl($project, $epicId));

        self::assertResponseIsSuccessful();
        $children = $crawler->filter('[data-epic-children] [data-linked-card]');
        self::assertCount(2, $children);
        self::assertSame(
            [(string) $openId, (string) $doneId],
            $children->each(static fn ($row): ?string => $row->attr('data-linked-card')),
        );
        self::assertStringContainsString('#'.$openNumber.' Open child', $children->first()->text());
        self::assertSame('1/2 done', trim($crawler->filter('[data-epic-progress]')->text()));
        self::assertCount(1, $crawler->filter('form[name="'.SetCardLaneFormType::PREFIX.$epicId.'"]'));
    }

    public function test_an_epic_with_no_children_shows_zero_of_zero(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'epic-page-empty@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'Lonely epic'), CardType::Epic);
        $epicId = $epic->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $this->cardUrl($project, $epicId));

        self::assertResponseIsSuccessful();
        self::assertSame('0/0 done', trim($crawler->filter('[data-epic-progress]')->text()));
        self::assertCount(0, $crawler->filter('[data-epic-children] [data-linked-card]'));
    }

    public function test_a_child_page_names_its_parent_and_shows_no_epic_section(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'epic-page-parent@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'Parent epic'), CardType::Epic);
        $child = $this->childOf($em, $epic, $this->card($em, $project, 'A child'));
        [$epicId, $childId, $epicNumber] = [$epic->id, $child->id, $epic->number];
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $this->cardUrl($project, $childId));

        self::assertResponseIsSuccessful();
        $parent = $crawler->filter('[data-card-parent] [data-linked-card="'.$epicId.'"]');
        self::assertCount(1, $parent);
        self::assertStringContainsString('#'.$epicNumber.' Parent epic', $parent->text());
        self::assertSame($this->cardUrl($project, $epicId), $parent->filter('a')->attr('href'));
        self::assertCount(0, $crawler->filter('[data-epic-children]'));
        self::assertCount(0, $crawler->filter('form[name^="'.SetCardLaneFormType::PREFIX.'"]'));
    }

    public function test_the_lane_form_turns_the_lane_off_and_on(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'epic-lane-toggle@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'Toggled epic'), CardType::Epic);
        $epicId = $epic->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $this->cardUrl($project, $epicId));
        $client->submit($crawler->filter('form[name="'.SetCardLaneFormType::PREFIX.$epicId.'"]')->form());

        self::assertResponseRedirects($this->cardUrl($project, $epicId));
        self::assertFalse($this->reload($em, $epicId)->laneEnabled);

        $crawler = $client->request(Request::METHOD_GET, $this->cardUrl($project, $epicId));
        $client->submit($crawler->filter('form[name="'.SetCardLaneFormType::PREFIX.$epicId.'"]')->form());

        self::assertResponseRedirects($this->cardUrl($project, $epicId));
        self::assertTrue($this->reload($em, $epicId)->laneEnabled);
    }

    public function test_the_lane_form_returns_to_the_board_when_asked(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'epic-lane-board@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'Board epic'), CardType::Epic);
        $em->clear();

        $client->loginUser($owner);
        $this->postLane($client, $project, $epic, '0', 'board');

        self::assertResponseRedirects('/projects/'.$project->id.'/board');
        self::assertFalse($this->reload($em, $epic->id)->laneEnabled);
    }

    public function test_another_user_cannot_set_the_lane(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'epic-lane-owner@example.com');
        $stranger = $this->user($em, 'epic-lane-stranger@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'Guarded epic'), CardType::Epic);
        $em->clear();

        $client->loginUser($stranger);
        $this->postLane($client, $project, $epic, '0');

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->reload($em, $epic->id)->laneEnabled);
    }

    public function test_a_forged_token_changes_nothing(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'epic-lane-forged@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'Forged epic'), CardType::Epic);
        $em->clear();

        $client->loginUser($owner);
        $this->postLane($client, $project, $epic, '0', token: 'forged');

        self::assertResponseRedirects($this->cardUrl($project, $epic->id));
        self::assertTrue($this->reload($em, $epic->id)->laneEnabled);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'The lane setting was not saved');
    }

    public function test_a_card_that_is_not_an_epic_keeps_its_lane_setting(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'epic-lane-feature@example.com');
        $project = $this->project($em, $owner);
        $feature = $this->card($em, $project, 'Plain feature');
        $em->clear();

        $client->loginUser($owner);
        $this->postLane($client, $project, $feature, '0');

        self::assertResponseRedirects($this->cardUrl($project, $feature->id));
        self::assertTrue($this->reload($em, $feature->id)->laneEnabled);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Only an epic has a lane');
    }

    public function test_the_list_view_shows_the_parent_of_each_card(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'epic-list-parent@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'Listed epic'), CardType::Epic);
        $child = $this->childOf($em, $epic, $this->card($em, $project, 'Listed child', 'next'));
        [$epicId, $childId, $epicNumber] = [$epic->id, $child->id, $epic->number];
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        $childRow = $crawler->filter('.lp-board-list__row[data-list-card-id="'.$childId.'"] [data-card-parent]');
        self::assertSame('#'.$epicNumber, trim($childRow->text()));
        $epicRow = $crawler->filter('.lp-board-list__row[data-list-card-id="'.$epicId.'"] [data-card-parent]');
        self::assertSame('—', trim($epicRow->text()));
    }

    private function postLane(
        KernelBrowser $client,
        Project $project,
        Card $card,
        string $laneEnabled,
        string $returnTo = 'card',
        string $token = 'csrf-token',
    ): void {
        $url = '/projects/'.$project->id.'/board/cards/'.$card->id.'/lane';
        // 'csrf-token' is the SameOriginCsrfTokenManager sentinel, which a
        // same-origin Referer lets stand in for the signed token.
        $client->request(
            Request::METHOD_POST,
            $url,
            [SetCardLaneFormType::nameFor($card) => ['laneEnabled' => $laneEnabled, 'returnTo' => $returnTo, '_token' => $token]],
            server: ['HTTP_REFERER' => 'http://localhost'.$url],
        );
    }

    private function cardUrl(Project $project, ?Uuid $cardId): string
    {
        return '/projects/'.$project->id.'/board/cards/'.$cardId;
    }

    private function reload(EntityManagerInterface $em, ?Uuid $cardId): Card
    {
        $em->clear();

        return $em->find(Card::class, $cardId) ?? throw new \LogicException('The card must exist.');
    }
}
