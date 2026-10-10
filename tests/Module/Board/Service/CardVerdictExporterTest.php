<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\CardVerdict;
use App\Module\Board\Entity\CardVerdictDelivery;
use App\Module\Board\Entity\CardVerdictDeliveryState;
use App\Module\Board\Entity\CardVerdictKind;
use App\Module\Board\Service\CardVerdictExporter;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\CardVerdictScenario;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardVerdictExporterTest extends KernelTestCase
{
    use BoardToolScenario;
    use CardVerdictScenario;

    private EntityManagerInterface $em;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->project = $this->makeProject('verdict-export');
    }

    public function test_it_exports_the_verdicts_a_user_sent_with_their_notes_and_deliveries(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linkedPullRequest($card, 7);
        $notes = [['id' => '0198a2c0-0000-7000-8000-000000000009', 'url' => 'https://app.example/page', 'body' => 'Footer.', 'anchorCount' => 1]];
        $verdict = new CardVerdict($card, CardVerdictKind::RequestChanges, $this->project->owner, 'Fix the footer.', $notes);
        $this->em->persist($verdict);
        $delivery = new CardVerdictDelivery($verdict, $pullRequest);
        $delivery->state = CardVerdictDeliveryState::Posted;
        $delivery->reviewUrl = 'https://github.com/Acme/Widgets/pull/7#pullrequestreview-1';
        $delivery->settledAt = new \DateTimeImmutable('2026-10-08T10:00:00+00:00');
        $this->em->persist($delivery);
        $this->em->flush();

        $rows = iterator_to_array($this->exporter()->export($this->project->owner), false);

        self::assertSame('card-verdicts.json', $this->exporter()->filename());
        self::assertCount(1, $rows);
        self::assertSame((string) $verdict->id, $rows[0]['id']);
        self::assertSame((string) $card->id, $rows[0]['cardId']);
        self::assertSame('request-changes', $rows[0]['kind']);
        self::assertSame('Fix the footer.', $rows[0]['message']);
        self::assertSame($notes, $rows[0]['notes']);
        self::assertSame([[
            'pullRequest' => 'acme/widgets#7',
            'state' => 'posted',
            'reason' => null,
            'reviewUrl' => 'https://github.com/Acme/Widgets/pull/7#pullrequestreview-1',
            'settledAt' => '2026-10-08T10:00:00+00:00',
        ]], $rows[0]['deliveries']);
    }

    public function test_it_leaves_out_the_verdicts_of_other_users_and_of_deleted_accounts(): void
    {
        $card = $this->card($this->project);
        $someoneElse = new User(fullName: 'Sam', email: 'sam-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($someoneElse);
        $this->em->persist(new CardVerdict($card, CardVerdictKind::Approve, $someoneElse, '', []));
        $this->em->persist(new CardVerdict($card, CardVerdictKind::Approve, null, '', []));
        $mine = new CardVerdict($card, CardVerdictKind::Comment, $this->project->owner, 'Mine.', []);
        $this->em->persist($mine);
        $this->em->flush();

        $rows = iterator_to_array($this->exporter()->export($this->project->owner), false);

        self::assertSame([(string) $mine->id], array_column($rows, 'id'));

        $newcomer = new User(fullName: 'New', email: 'new-'.uniqid().'@example.com', password: 'x');
        $this->em->persist($newcomer);
        $this->em->flush();
        self::assertSame([], iterator_to_array($this->exporter()->export($newcomer), false));
    }

    private function exporter(): CardVerdictExporter
    {
        $exporter = self::getContainer()->get(CardVerdictExporter::class);
        self::assertInstanceOf(CardVerdictExporter::class, $exporter);

        return $exporter;
    }
}
