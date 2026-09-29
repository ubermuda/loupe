<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Mcp\InboxAskTool;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\Service\CardWaitReconciler;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Tests\Module\Inbox\InboxFixtures;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

/**
 * KernelTestCase helper for the inbox MCP tools. Requires an `$em`
 * EntityManagerInterface property on the using class.
 */
trait InboxToolScenario
{
    use InboxFixtures;

    /** The inbox ships off, so every test that calls a tool switches it on. */
    private function enableInbox(): void
    {
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[InboxInstallFlags::FLAG_INBOX_ENABLED]->value = true;
        $this->em->flush();
    }

    private function makeProject(string $label): Project
    {
        $project = $this->project($this->em, $this->owner($this->em, $label), $label);
        $this->em->flush();

        return $project;
    }

    private function askTool(): InboxAskTool
    {
        $tool = self::getContainer()->get(InboxAskTool::class);
        self::assertInstanceOf(InboxAskTool::class, $tool);

        return $tool;
    }

    /**
     * Asks one question through the tool, in a session of its own unless one is given.
     *
     * @param array<string, mixed> $item
     *
     * @return array{askId: string, extended: bool, closed: bool, items: list<array{itemId: string, number: int, kind: string, origin: string, title: string, state: string, blocking: bool, createdAt: string, updatedAt: string, closedAt: ?string}>}
     */
    private function askQuestion(string $title, array $item = [], ?string $sessionId = null): array
    {
        return ($this->askTool())(
            sessionId: $sessionId ?? (string) Uuid::v4(),
            items: [['kind' => 'question', 'title' => $title, 'options' => ['yes', 'no'], ...$item]],
        );
    }

    /** Links a document in review to the card, so Loupe opens a wait item for it. */
    private function documentInReview(Project $project, Card $card): Document
    {
        $document = new Document($project->owner, $project, 'The design');
        $document->addVersion('# One', '<h1>One</h1>');
        $this->em->persist($document);
        $card->documents->add(new CardDocument($card, $document));
        $this->em->flush();

        return $document;
    }

    private function reconcileWaits(Project $project, Card $card): InboxCardWatch
    {
        $reconciler = self::getContainer()->get(CardWaitReconciler::class);
        self::assertInstanceOf(CardWaitReconciler::class, $reconciler);
        $reconciler->reconcile($project, [(string) $card->id]);
        $watches = self::getContainer()->get(InboxCardWatchRepository::class);
        self::assertInstanceOf(InboxCardWatchRepository::class, $watches);
        $watch = $watches->findOneBy(['cardId' => $card->id]);
        self::assertInstanceOf(InboxCardWatch::class, $watch);

        return $watch;
    }
}
