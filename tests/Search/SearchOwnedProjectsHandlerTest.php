<?php

declare(strict_types=1);

namespace App\Tests\Search;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Search\Command\SearchOwnedProjectsCommand;
use App\Search\Command\SearchOwnedProjectsHandler;
use App\Search\SearchResult;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SearchOwnedProjectsHandlerTest extends KernelTestCase
{
    public function test_search_includes_owned_projects_and_excludes_another_owners_matches(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $owner = new User(fullName: 'Owner', email: 'owned-search@example.com', password: 'x');
        $outsider = new User(fullName: 'Outsider', email: 'foreign-search@example.com', password: 'x');
        $first = new Project($owner, 'First project');
        $second = new Project($owner, 'Second project');
        $foreign = new Project($outsider, 'Secret project');
        foreach ([$owner, $outsider, $first, $second, $foreign] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $create = self::getContainer()->get(CreateDocumentHandler::class);
        for ($index = 0; $index < 11; ++$index) {
            ($create)(new CreateDocumentCommand($first, 'Document '.$index, 'quartz'));
        }
        ($create)(new CreateDocumentCommand($second, 'Second document', 'quartz'));
        ($create)(new CreateDocumentCommand($foreign, 'Secret document', 'quartz'));
        $search = self::getContainer()->get(SearchOwnedProjectsHandler::class);
        $page = $search(new SearchOwnedProjectsCommand($owner, ' quartz '));
        self::assertSame('quartz', $page->query);
        self::assertCount(11, $page->results->items);
        self::assertTrue($page->results->hasMore);
        $next = $search(new SearchOwnedProjectsCommand($owner, 'quartz', 2));
        self::assertCount(1, $next->results->items);
        self::assertFalse($next->results->hasMore);
        $items = [...$page->results->items, ...$next->results->items];
        $urls = array_map(static fn (SearchResult $result): string => $result->url, $items);
        self::assertCount(12, array_unique($urls));
        foreach ($items as $item) {
            self::assertStringNotContainsString('Secret', $item->title);
            self::assertStringNotContainsString((string) $foreign->id, $item->url);
        }
        self::assertCount(1, array_filter($items, static fn (SearchResult $result): bool => str_starts_with($result->title, 'Second project · ')));
        $account = $search(new SearchOwnedProjectsCommand($owner, 'Account'));
        self::assertCount(1, $account->results->items);
        self::assertSame('/account', $account->results->items[0]->url);
        $foreignResult = $search(new SearchOwnedProjectsCommand($outsider, 'quartz'));
        self::assertCount(1, $foreignResult->results->items);
        self::assertStringContainsString('Secret project', $foreignResult->results->items[0]->title);
    }
}
