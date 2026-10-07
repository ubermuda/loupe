<?php

declare(strict_types=1);

namespace App\Tests\Search;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\CardType;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Search\Command\SearchProjectCommand;
use App\Search\Command\SearchProjectHandler;
use App\Search\SearchResult;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SearchProjectHandlerTest extends KernelTestCase
{
    use BoardColumnFixtures;

    public function test_search_combines_bounded_project_scoped_cards_and_documents(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $owner = new User(fullName: 'Search owner', email: 'global-search@example.com', password: 'x');
        $em->persist($owner);
        $project = new Project($owner, 'Search target');
        $foreign = new Project($owner, 'Other project');
        foreach ([$project, $foreign] as $entry) {
            $em->persist($entry);
            $this->seedColumns($entry);
        }
        $em->flush();
        $cards = self::getContainer()->get(CreateCardHandler::class);
        $documents = self::getContainer()->get(CreateDocumentHandler::class);
        for ($index = 0; $index < 11; ++$index) {
            ($cards)(new CreateCardCommand($project, 'Search card '.$index, 'quartz', CardType::Feature, column: $this->column($project, 'done')));
            $document = ($documents)(new CreateDocumentCommand($project, 'Search document '.$index, 'quartz'));
            $document->archivedAt = new \DateTimeImmutable();
        }
        $em->flush();
        ($cards)(new CreateCardCommand($foreign, 'Foreign card', 'quartz', CardType::Feature));
        ($documents)(new CreateDocumentCommand($foreign, 'Foreign document', 'quartz'));
        $search = self::getContainer()->get(SearchProjectHandler::class);
        $first = $search(new SearchProjectCommand($project, '  quartz  '));
        $numbered = $search(new SearchProjectCommand($project, '#1'));
        $numberedCards = array_values(array_filter($numbered->results->items, static fn (SearchResult $result): bool => 'card' === $result->kind));
        self::assertCount(1, $numberedCards);
        self::assertStringStartsWith('#1 Search card', $numberedCards[0]->title);
        self::assertFalse($numbered->results->hasMore);
        self::assertSame([], $search(new SearchProjectCommand($project, '#1', 2))->results->items);
        self::assertSame([], $search(new SearchProjectCommand($project, '#99999999999999999999999'))->results->items);
        self::assertSame('quartz', $first->query);
        self::assertCount(20, $first->results->items);
        self::assertTrue($first->results->hasMore);
        $second = $search(new SearchProjectCommand($project, 'quartz', 2));
        self::assertCount(2, $second->results->items);
        self::assertFalse($second->results->hasMore);
        $all = [...$first->results->items, ...$second->results->items];
        $urls = array_map(static fn (SearchResult $result): string => $result->url, $all);
        self::assertCount(22, array_unique($urls));
        foreach ($all as $result) {
            self::assertStringNotContainsString('Foreign', $result->title);
            self::assertStringContainsString('/projects/'.$project->id.'/', $result->url);
        }
        $pages = $search(new SearchProjectCommand($project, 'activity'));
        self::assertSame(['Activity · Runs', 'Activity · Events'], array_map(static fn (SearchResult $result): string => $result->title, $pages->results->items));
        self::assertSame(['page', 'page'], array_map(static fn (SearchResult $result): string => $result->kind, $pages->results->items));
        self::assertStringEndsWith('/worker-runs', $pages->results->items[0]->url);
        self::assertStringEndsWith('/activity', $pages->results->items[1]->url);
        $analytics = $search(new SearchProjectCommand($project, 'analytics'));
        self::assertSame(['Analytics · Metrics', 'Analytics · Experiments'], array_map(static fn (SearchResult $result): string => $result->title, $analytics->results->items));
        self::assertStringEndsWith('/analytics/metrics', $analytics->results->items[0]->url);
        self::assertStringEndsWith('/analytics/experiments', $analytics->results->items[1]->url);
        self::assertSame([], $search(new SearchProjectCommand($project, 'Run history'))->results->items);
        self::assertSame([], $search(new SearchProjectCommand($project, 'unfindablequartz'))->results->items);
        $board = $search(new SearchProjectCommand($project, 'Board'));
        self::assertSame(['page'], array_map(static fn (SearchResult $result): string => $result->kind, $board->results->items));
        self::assertSame('/projects/'.$project->id.'/board', $board->results->items[0]->url);
        self::assertSame([], $search(new SearchProjectCommand($project, 'Rules'))->results->items);
        $account = $search(new SearchProjectCommand($project, 'Account'));
        self::assertCount(1, $account->results->items);
        self::assertSame('/account/profile', $account->results->items[0]->url);
    }
}
