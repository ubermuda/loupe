<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\EventListener;

use App\Exception\DomainErrors;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Command\SaveDecisionAnswerCommand;
use App\Module\Review\Command\SaveDecisionAnswerHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DecisionAnswerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

/**
 * Drives the save path and reads what reaches the hub, so the page of another
 * reviewer learns each change to a decision and nothing else.
 */
final class PublishDecisionChangedOnDecisionAnswerChangedTest extends KernelTestCase
{
    private const string MARKDOWN = "<!-- decision: features -->\n\n- [ ] Import\n- [ ] Export\n- [ ] Search\n\n<!-- /decision -->";

    private EntityManagerInterface $em;
    private User $owner;
    private Document $document;

    /** @var list<Update> */
    private array $published = [];

    protected function setUp(): void
    {
        self::bootKernel();

        // Before anything builds the hub: the container hands the replacement
        // only to what it builds afterwards.
        self::getContainer()->set('mercure.hub.default', new MockHub(
            'http://mercure/.well-known/mercure',
            new StaticTokenProvider('token'),
            function (Update $update): string {
                $this->published[] = $update;

                return 'id';
            },
        ));

        $this->em = $this->service(EntityManagerInterface::class);
        $this->owner = new User(fullName: 'Riley Chen', email: 'decision-changed-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($this->owner);
        $project = new Project($this->owner, 'decision-changed-'.uniqid());
        $this->em->persist($project);
        $this->em->flush();
        $this->document = $this->service(CreateDocumentHandler::class)(new CreateDocumentCommand($project, 'Decisions', self::MARKDOWN));
        $this->drain();
    }

    public function test_a_saved_answer_publishes_the_picks_the_note_and_who_answered(): void
    {
        $this->save([2, 0], 'Both, please.');

        self::assertSame([$this->message([0, 2], 'Both, please.', 'Riley Chen', 1)], $this->drain());
    }

    public function test_a_save_that_changes_nothing_publishes_nothing(): void
    {
        $this->save([1], null);
        // Guard: the first save reached the hub, so the second one is the test.
        self::assertCount(1, $this->drain());

        $this->save([1], null);

        self::assertSame([], $this->drain());
    }

    public function test_a_clear_publishes_no_picks_and_the_note_it_keeps(): void
    {
        $this->save([1], 'Keep this.');
        $this->drain();

        $this->save([], 'Keep this.', clear: true);

        self::assertSame([$this->message([], 'Keep this.', 'Riley Chen', 1)], $this->drain());
    }

    public function test_a_decision_left_unanswered_publishes_nobody(): void
    {
        $this->save([1], null);
        $this->drain();

        $this->save([], null, clear: true);

        $messages = $this->drain();
        self::assertCount(1, $messages);
        self::assertIsString($messages[0]['answeredAt'] ?? null);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $messages[0]['answeredAt']));
        unset($messages[0]['answeredAt']);
        self::assertSame([
            'type' => 'review.decision_changed',
            'decisionId' => 'features',
            'versionNumber' => 1,
            'optionIndexes' => [],
            'note' => null,
            'answeredBy' => null,
            'origin' => null,
        ], $messages[0]);
    }

    public function test_an_answer_from_an_older_version_publishes_the_latest_version(): void
    {
        $this->service(ReviseDocumentHandler::class)(new ReviseDocumentCommand(
            $this->document,
            "<!-- decision: features -->\n\n- [ ] Search\n- [ ] Import\n- [ ] Export\n\n<!-- /decision -->",
            'Reordered.',
        ));
        $this->drain();

        $this->save([0], null, version: 1);

        self::assertSame([$this->message([1], null, 'Riley Chen', 2)], $this->drain());
    }

    public function test_a_refused_answer_publishes_nothing(): void
    {
        try {
            $this->save([7], null);
            self::fail('The answer must be refused.');
        } catch (DomainErrors) {
        }

        self::assertSame([], $this->drain());
    }

    /** @param list<int> $indexes */
    private function save(array $indexes, ?string $note, bool $clear = false, int $version = 1): void
    {
        $this->service(SaveDecisionAnswerHandler::class)(new SaveDecisionAnswerCommand($this->document, 'features', $version, $indexes, $note, $clear, $this->owner));
    }

    /**
     * @param list<int> $indexes
     *
     * @return array<string, mixed>
     */
    private function message(array $indexes, ?string $note, ?string $answeredBy, int $versionNumber): array
    {
        $answer = $this->service(DecisionAnswerRepository::class)->findOneByDocumentAndDecisionId($this->document, 'features');
        self::assertNotNull($answer);

        return [
            'type' => 'review.decision_changed',
            'decisionId' => 'features',
            'versionNumber' => $versionNumber,
            'optionIndexes' => $indexes,
            'note' => $note,
            'answeredBy' => $answeredBy,
            'answeredAt' => $answer->updatedAt->format(\DATE_ATOM),
            'origin' => null,
        ];
    }

    /**
     * Ends the request the way the kernel does, and answers the decision
     * messages the document topic received since the last call.
     *
     * @return list<array<string, mixed>>
     */
    private function drain(): array
    {
        $dispatcher = $this->service(EventDispatcherInterface::class);
        self::assertNotNull(self::$kernel);
        $dispatcher->dispatch(new TerminateEvent(self::$kernel, Request::create('/'), new Response()), KernelEvents::TERMINATE);

        $published = $this->published;
        $this->published = [];
        $topic = $this->service(ProjectTopicBuilder::class)->forDocument(
            $this->document->project->id ?? throw new \LogicException('The project has no id.'),
            $this->document->id ?? throw new \LogicException('The document has no id.'),
        );

        $messages = [];
        foreach ($published as $update) {
            $data = json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR);
            if (\is_array($data) && 'review.decision_changed' === ($data['type'] ?? null)) {
                self::assertSame([$topic], $update->getTopics());
                self::assertTrue($update->isPrivate());
                $messages[] = $data;
            }
        }

        return $messages;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
