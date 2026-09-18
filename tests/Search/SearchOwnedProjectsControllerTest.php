<?php

declare(strict_types=1);

namespace App\Tests\Search;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class SearchOwnedProjectsControllerTest extends WebTestCase
{
    public function test_global_search_uses_the_authenticated_owner_in_pages_and_frames(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/search');
        self::assertResponseRedirects('/login');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $owner = new User(fullName: 'Owner', email: 'owned-route@example.com', password: 'x');
        $outsider = new User(fullName: 'Outsider', email: 'foreign-route@example.com', password: 'x');
        foreach ([$owner, $outsider] as $user) {
            $user->emailVerifiedAt = new \DateTimeImmutable();
            AcceptedTerms::stamp($user, self::getContainer());
            $em->persist($user);
        }
        $project = new Project($owner, 'Owned project');
        $foreign = new Project($outsider, 'Private project');
        $em->persist($project);
        $em->persist($foreign);
        $em->flush();
        $create = self::getContainer()->get(CreateDocumentHandler::class);
        $document = $create(new CreateDocumentCommand($project, 'Visible document', 'quartz'));
        $create(new CreateDocumentCommand($foreign, 'Private document', 'quartz'));
        $em->clear();
        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/search', ['query' => 'quartz', 'owner' => (string) $outsider->id]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorNotExists('[data-search-kind]');
        $client->request(Request::METHOD_GET, '/search', ['query' => 'quartz']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Search all projects');
        self::assertSelectorCount(1, '[data-search-kind="document"]');
        self::assertSelectorTextContains('[data-search-kind="document"]', 'Owned project · Visible document');
        self::assertSame('/projects/'.$project->id.'/documents/'.$document->id.'/review', $client->getCrawler()->filter('[data-search-kind="document"]')->attr('href'));
        $client->request(Request::METHOD_GET, '/search', ['query' => 'quartz'], server: ['HTTP_TURBO_FRAME' => 'project-search-results']);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '#project-search-results [data-search-kind="document"]');
        self::assertSelectorNotExists('.lp-sidebar');
        self::assertResponseHeaderSame('Vary', 'Turbo-Frame');
        $client->request(Request::METHOD_GET, '/search', ['query' => str_repeat('x', 201)]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorNotExists('[data-search-kind]');
        $client->request(Request::METHOD_GET, '/account/profile');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[data-global-search-target="trigger"][href="/search"]');
        $client->request(Request::METHOD_GET, '/projects');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[data-global-search-target="trigger"][href="/search"]');
    }
}
