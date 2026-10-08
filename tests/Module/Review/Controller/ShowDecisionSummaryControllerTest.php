<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\SaveDecisionAnswerCommand;
use App\Module\Review\Command\SaveDecisionAnswerHandler;
use App\Module\Review\Entity\Document;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ShowDecisionSummaryControllerTest extends WebTestCase
{
    private const string MARKDOWN = "<!-- decision: target -->\n\n- ( ) Staging\n- ( ) Production\n\n<!-- /decision -->\n\n<!-- decision: region -->\n\n- ( ) Europe\n- ( ) America\n\n<!-- /decision -->";

    public function test_a_viewer_gets_the_summary_streams_for_the_version_it_names(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);
        $save = static::getContainer()->get(SaveDecisionAnswerHandler::class);
        self::assertInstanceOf(SaveDecisionAnswerHandler::class, $save);
        $save(new SaveDecisionAnswerCommand($document, 'target', 1, [1], null, false, $owner));

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $this->summaryPath($document).'?versionNumber=1');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/vnd.turbo-stream.html; charset=UTF-8');
        $streams = new Crawler((string) $client->getResponse()->getContent())->filter('turbo-stream');
        self::assertSame(
            ['decision-summary-count', 'decision-summary-list', 'review-menu-decisions-count', 'review-menu-decisions-head-count', 'review-menu-decisions-list'],
            $streams->each(static fn (Crawler $stream): ?string => $stream->attr('target')),
        );
        self::assertSame(
            array_fill(0, 5, $document->id.'/1'),
            $streams->each(static fn (Crawler $stream): ?string => $stream->attr('data-decision-page')),
        );
        self::assertStringContainsString('1 of 2 answered', $streams->eq(0)->html());
        self::assertStringContainsString('1/2', $streams->eq(2)->html());
    }

    public function test_the_summary_carries_the_stored_answer_of_every_block(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);
        $save = static::getContainer()->get(SaveDecisionAnswerHandler::class);
        self::assertInstanceOf(SaveDecisionAnswerHandler::class, $save);
        $save(new SaveDecisionAnswerCommand($document, 'target', 1, [1], null, false, $owner));
        $save(new SaveDecisionAnswerCommand($document, 'region', 1, [], 'Either works.', false, $owner));

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $this->summaryPath($document).'?versionNumber=1');

        $answers = new Crawler((string) $client->getResponse()->getContent())
            ->filter('turbo-stream[data-decision-answers]')
            ->attr('data-decision-answers');
        self::assertSame(
            ['target' => ['indexes' => [1], 'note' => null], 'region' => ['indexes' => [], 'note' => 'Either works.']],
            json_decode((string) $answers, true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    public function test_an_unknown_version_falls_back_to_the_latest_and_keeps_the_page_it_names(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $this->summaryPath($document).'?versionNumber=99');

        self::assertResponseIsSuccessful();
        $streams = new Crawler((string) $client->getResponse()->getContent())->filter('turbo-stream');
        self::assertCount(5, $streams);
        self::assertSame($document->id.'/99', $streams->attr('data-decision-page'));
    }

    public function test_a_request_without_a_valid_version_is_not_found(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);
        $client->loginUser($owner);

        $client->request(Request::METHOD_GET, $this->summaryPath($document));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $client->request(Request::METHOD_GET, $this->summaryPath($document).'?versionNumber=latest');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function test_a_stranger_is_refused(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);
        $stranger = $this->user('summary-stranger');

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, $this->summaryPath($document).'?versionNumber=1');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // Guard: the owner passes the same request, so the refusal is about who asks.
        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $this->summaryPath($document).'?versionNumber=1');
        self::assertResponseIsSuccessful();
    }

    /** @return array{User, Document} */
    private function seed(KernelBrowser $client): array
    {
        $client->disableReboot();
        $owner = $this->user('summary-owner');
        $project = new Project($owner, 'p-'.uniqid());
        $this->em()->persist($project);
        $this->em()->flush();

        $create = static::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $create);

        return [$owner, $create(new CreateDocumentCommand($project, 'Deploy plan', self::MARKDOWN))];
    }

    private function user(string $prefix): User
    {
        $user = new User(fullName: 'Decider', email: $prefix.'-'.uniqid().'@example.com', password: 'hashed');
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

    private function summaryPath(Document $document): string
    {
        return '/projects/'.$document->project->id.'/documents/'.$document->id.'/decisions/summary';
    }
}
