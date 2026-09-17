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

final class SearchProjectControllerTest extends WebTestCase
{
    public function test_search_validates_input_and_denies_other_project_owners(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $owner = new User(fullName: 'Owner', email: 'search-controller@example.com', password: 'x');
        $outsider = new User(fullName: 'Outsider', email: 'search-outsider@example.com', password: 'x');
        foreach ([$owner, $outsider] as $user) {
            $user->emailVerifiedAt = new \DateTimeImmutable();
            AcceptedTerms::stamp($user, self::getContainer());
            $em->persist($user);
        }
        $project = new Project($owner, 'Search project');
        $em->persist($project);
        $em->flush();
        $create = self::getContainer()->get(CreateDocumentHandler::class);
        $document = $create(new CreateDocumentCommand($project, 'Visible document', 'quartz'));
        $em->clear();
        $client->loginUser($owner);
        $url = '/projects/'.$project->id.'/search';
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-search-kind="page"]');
        $client->submit($crawler->filter('form.lp-form')->form(['query' => 'quartz']));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-search-kind="document"]', 'Visible document');
        self::assertSame('/projects/'.$project->id.'/documents/'.$document->id.'/review', $client->getCrawler()->filter('[data-search-kind="document"]')->attr('href'));
        self::assertResponseHeaderSame('Vary', 'Turbo-Frame');
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $url, ['query' => 'quartz'], server: ['HTTP_TURBO_FRAME' => 'project-search-results']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#project-search-results [data-search-kind="document"]');
        self::assertSelectorNotExists('.lp-sidebar');
        self::assertResponseHeaderSame('Vary', 'Turbo-Frame');
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $url, ['query' => str_repeat('x', 201)]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorNotExists('[data-search-kind]');
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $url, ['query' => ['invalid']]);
        self::assertResponseStatusCodeSame(422);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $url, ['query' => 'quartz', 'page' => -1]);
        self::assertResponseStatusCodeSame(422);
        $client->loginUser($outsider);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $url, ['query' => 'quartz']);
        self::assertResponseStatusCodeSame(403);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $url, ['query' => 'quartz'], server: ['HTTP_TURBO_FRAME' => 'project-search-results']);
        self::assertResponseStatusCodeSame(403);
    }
}
