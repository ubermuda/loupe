<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Entity\Document;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ShowNewTextAnchorsControllerTest extends WebTestCase
{
    public function test_a_viewer_gets_the_added_passages_of_a_version(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $this->path($document, 2));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertNull($body['reason']);
        self::assertCount(1, $body['anchors']);
        self::assertSame('careful', $body['anchors'][0]['quote']);
        self::assertSame(['quote', 'prefix', 'suffix'], array_keys($body['anchors'][0]));
        self::assertStringEndsWith('takes one ', $body['anchors'][0]['prefix']);
    }

    public function test_the_first_version_names_its_reason(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $this->path($document, 1));

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['anchors' => [], 'reason' => 'no-previous-version'], $body);
    }

    public function test_an_unknown_version_is_not_found(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $this->path($document, 99));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function test_a_stranger_is_refused(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);
        $stranger = $this->user('new-text-stranger');

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, $this->path($document, 2));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // Guard: the owner passes the same request, so the refusal is about who asks.
        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $this->path($document, 2));
        self::assertResponseIsSuccessful();
    }

    /** @return array{User, Document} */
    private function seed(KernelBrowser $client): array
    {
        $client->disableReboot();
        $owner = $this->user('new-text-owner');
        $project = new Project($owner, 'p-'.uniqid());
        $this->em()->persist($project);
        $this->em()->flush();

        $create = static::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $create);
        $document = $create(new CreateDocumentCommand($project, 'Plan', "The rollout takes one step.\n"));

        $revise = static::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);
        $revise(new ReviseDocumentCommand($document, "The rollout takes one careful step.\n", 'Added a word.'));

        return [$owner, $document];
    }

    private function user(string $prefix): User
    {
        $user = new User(fullName: 'Reader', email: $prefix.'-'.uniqid().'@example.com', password: 'hashed');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function path(Document $document, int $versionNumber): string
    {
        return '/projects/'.$document->project->id.'/documents/'.$document->id.'/review/new-text/'.$versionNumber;
    }
}
