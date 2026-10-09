<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Board\Command\SettleSiteReviewChecksCommand;
use App\Module\Board\Command\SettleSiteReviewChecksHandler;
use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardVerdict;
use App\Module\Board\Entity\CardVerdictDelivery;
use App\Module\Board\Entity\CardVerdictKind;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\SiteReviewCheckStateRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Board\Service\SiteReviewCheckPublisher;
use App\Module\Board\Workflow\SiteReviewFactProvider;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestCheckConclusion;
use App\Module\Forge\Service\PullRequestCheckWriters;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Tests\Module\Board\CardVerdictScenario;
use App\Tests\Module\Board\Fake\FakeCheckWriter;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SettleSiteReviewChecksHandlerTest extends KernelTestCase
{
    use BoardToolScenario;
    use CardVerdictScenario;

    private EntityManagerInterface $em;
    private Project $project;
    private FakeCheckWriter $writer;
    private BoardAutomationSettings $settings;
    private ForgePullRequest $pullRequest;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->project = $this->makeProject('check-settle');
        $this->writer = new FakeCheckWriter();
        $this->settings = new BoardAutomationSettings($this->project, siteReviewCheck: true);
        $this->em->persist($this->settings);
        $this->em->flush();

        $card = $this->card($this->project);
        $this->pullRequest = $this->linkedPullRequest($card, 7);
        $this->pullRequest->headSha = 'sha-1';
        $this->failedCheck($card);
        $this->writer->published = [];
    }

    public function test_it_turns_the_failed_check_neutral_once_the_check_is_off(): void
    {
        $this->switchCheck(false);

        $this->settle();

        self::assertCount(1, $this->writer->published);
        self::assertSame(PullRequestCheckConclusion::Neutral, $this->writer->published[0]['conclusion']);
    }

    public function test_it_writes_nothing_when_the_check_is_on_again(): void
    {
        $this->settle();

        self::assertSame([], $this->writer->published);
    }

    public function test_a_refusal_that_a_retry_can_fix_asks_the_transport_to_retry(): void
    {
        $this->switchCheck(false);
        $this->writer->failingNumbers = [7];
        $this->writer->failsForGood = false;

        $this->expectException(RecoverableMessageHandlingException::class);

        $this->settle();
    }

    public function test_a_refusal_for_good_ends_the_message(): void
    {
        $this->switchCheck(false);
        $this->writer->failingNumbers = [7];

        $this->settle();

        self::assertCount(1, $this->writer->published);
    }

    private function failedCheck(Card $card): void
    {
        $note = $this->note($card, 'Fix the header');
        $verdict = new CardVerdict($card, CardVerdictKind::Comment, $this->project->owner, 'Notes', [['id' => (string) $note->id, 'url' => 'https://app.example/page', 'body' => $note->body, 'anchorCount' => 1]]);
        $this->em->persist($verdict);
        $this->em->persist(new CardVerdictDelivery($verdict, $this->pullRequest));
        $this->em->flush();
        $this->publisher()->publish($card);
    }

    private function switchCheck(bool $on): void
    {
        $this->settings->siteReviewCheck = $on;
        $this->em->flush();
    }

    private function settle(): void
    {
        $handler = new SettleSiteReviewChecksHandler(
            $this->service(ProjectRepository::class),
            $this->service(BoardAutomation::class),
            $this->publisher(),
            new NullLogger(),
        );
        $handler(new SettleSiteReviewChecksCommand($this->project->id ?? throw new \LogicException('A stored project has an id.')));
    }

    private function publisher(): SiteReviewCheckPublisher
    {
        return new SiteReviewCheckPublisher(
            $this->service(CardPullRequestRepository::class),
            $this->service(SiteReviewFactProvider::class),
            $this->service(SiteReviewCheckStateRepository::class),
            $this->service(BoardAutomation::class),
            new PullRequestCheckWriters([$this->writer]),
            $this->service(TranslatorInterface::class),
            $this->em,
            new MockClock('2026-10-08 12:00:00'),
        );
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
