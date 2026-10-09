<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Twig;

use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitReason;
use App\Module\Inbox\Entity\InboxCardWaitTrigger;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Twig\DocumentWaitLineExtension;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Twig\Environment;

final class DocumentWaitLineExtensionTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private DocumentWaitLineExtension $extension;
    private Environment $twig;
    private Project $project;
    private Document $document;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $extension = self::getContainer()->get(DocumentWaitLineExtension::class);
        self::assertInstanceOf(DocumentWaitLineExtension::class, $extension);
        $this->extension = $extension;
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $this->twig = $twig;

        $owner = $this->owner($em, 'wait-line');
        $this->project = $this->project($em, $owner, 'wait-line');
        $this->document = $this->document($em, $this->project);
        $em->flush();
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);

        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($owner, 'main', $owner->getRoles()));
    }

    public function test_it_names_the_card_that_waits_on_the_document(): void
    {
        $this->waitFor(12, 3);
        $this->em->flush();

        $line = $this->extension->waitLine($this->twig, $this->document);

        self::assertStringContainsString('Card <a', $line);
        self::assertStringContainsString('#12</a> waits on this review', $line);
        self::assertStringContainsString('inbox-item-3', $line);
    }

    public function test_it_joins_two_cards_with_and(): void
    {
        $this->waitFor(12, 3);
        $this->waitFor(14, 4);
        $this->em->flush();

        $line = strip_tags($this->extension->waitLine($this->twig, $this->document));

        self::assertStringContainsString('Cards #12 and #14 wait on this review', preg_replace('/\s+/', ' ', $line) ?? '');
    }

    public function test_it_is_empty_when_nothing_waits(): void
    {
        self::assertSame('', $this->extension->waitLine($this->twig, $this->document));
    }

    public function test_it_ignores_a_wait_that_ended_and_a_wait_on_another_document(): void
    {
        $this->waitFor(12, 3)->endedAt = new \DateTimeImmutable();
        $this->waitFor(14, 4, $this->document($this->em, $this->project));
        $this->em->flush();

        self::assertSame('', $this->extension->waitLine($this->twig, $this->document));
    }

    public function test_it_is_empty_while_the_inbox_is_off(): void
    {
        $this->waitFor(12, 3);
        $this->em->flush();
        $this->switchFlag($this->em, InboxInstallFlags::FLAG_INBOX_ENABLED, false);

        self::assertSame('', $this->extension->waitLine($this->twig, $this->document));
    }

    private function waitFor(int $cardNumber, int $itemNumber, ?Document $document = null): InboxCardWait
    {
        $document ??= $this->document;
        $card = $this->card($this->em, $this->project, $cardNumber);
        $item = new InboxItem(project: $this->project, number: $itemNumber, kind: InboxItemKind::Wait, title: 'Waiting', blocking: false);
        $this->em->persist($item);
        $watch = new InboxCardWatch($item, $card->id ?? \Symfony\Component\Uid\Uuid::v4(), $cardNumber);
        $wait = new InboxCardWait($watch, InboxCardWaitTrigger::DocumentInReview, InboxCardWaitType::Document, InboxCardWaitReason::WaitingForReview, $document->id, 1);
        $watch->waits->add($wait);
        $this->em->persist($watch);

        return $wait;
    }
}
