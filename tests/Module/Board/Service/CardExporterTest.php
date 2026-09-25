<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Service\CardExporter;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardExporterTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private CardExporter $exporter;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $exporter = self::getContainer()->get(CardExporter::class);
        self::assertInstanceOf(CardExporter::class, $exporter);
        $this->exporter = $exporter;
    }

    public function test_it_writes_one_file(): void
    {
        self::assertSame('cards.json', $this->exporter->filename());
    }

    public function test_a_card_carries_every_field_and_its_pull_requests(): void
    {
        $owner = $this->user('card-export');
        $project = new Project($owner, 'export-'.uniqid());
        $this->em->persist($project);
        $this->seedColumns($project);

        $completedAt = new \DateTimeImmutable('2026-03-04 10:11:12');
        $createdAt = new \DateTimeImmutable('2026-03-01 09:00:00');
        // Distinct from $createdAt, which the constructor copies it from, so the
        // assertion cannot pass on an export that reads the wrong field.
        $updatedAt = new \DateTimeImmutable('2026-03-05 14:00:00');
        $firstAddedAt = new \DateTimeImmutable('2026-03-02 08:00:00');
        $secondAddedAt = new \DateTimeImmutable('2026-03-03 08:00:00');

        $card = new Card(
            project: $project,
            column: $this->column($project, 'done'),
            title: 'Rotate the signing key',
            body: 'The key is a year old.',
            number: 1,
            type: CardType::Bug,
            origin: CardReporter::Human,
            position: 7,
            createdAt: $createdAt,
        );
        $card->completedAt = $completedAt;
        $card->updatedAt = $updatedAt;
        $card->pullRequests->add(new CardPullRequest($card, 'https://github.com/ubermuda/loupe/pull/42', Forge::GitHub, 'ubermuda/loupe', 42, $firstAddedAt));
        $card->pullRequests->add(new CardPullRequest($card, 'https://git.example.test/patch', Forge::Other, addedAt: $secondAddedAt));
        $this->em->persist($card);
        $this->em->flush();
        $this->em->clear();

        $rows = iterator_to_array($this->exporter->export($owner), false);

        self::assertCount(1, $rows);
        self::assertSame([
            'id' => (string) $card->id,
            'project' => $project->name,
            'title' => 'Rotate the signing key',
            'body' => 'The key is a year old.',
            'status' => 'done',
            // The label a reader sees on the board, translated.
            'column' => 'Done',
            'type' => 'bug',
            'reporter' => 'human',
            'position' => 7,
            'completedAt' => $completedAt->format(\DateTimeInterface::ATOM),
            'createdAt' => $createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt' => $updatedAt->format(\DateTimeInterface::ATOM),
            'pullRequests' => [
                [
                    'url' => 'https://github.com/ubermuda/loupe/pull/42',
                    'forge' => 'github',
                    'repository' => 'ubermuda/loupe',
                    'number' => 42,
                    'addedAt' => $firstAddedAt->format(\DateTimeInterface::ATOM),
                ],
                [
                    'url' => 'https://git.example.test/patch',
                    'forge' => 'other',
                    'repository' => null,
                    'number' => null,
                    'addedAt' => $secondAddedAt->format(\DateTimeInterface::ATOM),
                ],
            ],
            'documents' => [],
            'feedback' => [],
            'relatedCards' => [],
            'parentCardId' => null,
            'parentNumber' => null,
            'laneEnabled' => true,
        ], $rows[0]);
    }

    /**
     * Feedback lives on its card, so the card's file carries each note in
     * full: the export is the user's own copy, strokes included.
     */
    public function test_a_card_carries_its_feedback_in_full(): void
    {
        $owner = $this->user('card-export-feedback');
        $project = new Project($owner, 'export-'.uniqid());
        $this->em->persist($project);
        $this->seedColumns($project);
        $card = new Card(
            project: $project,
            column: $this->column($project, 'backlog'),
            title: 'Checkout feedback',
            body: '',
            number: 1,
            type: CardType::SiteReview,
            origin: CardReporter::Reviewer,
            position: 0,
        );
        $this->em->persist($card);

        $createdAt = new \DateTimeImmutable('2026-03-01 09:00:00');
        $drawn = new SiteReviewComment($project, 0, 'Point here', 'https://example.com/checkout', 'card:preview', $createdAt)
            ->addAnchor('.hero h1', 'Hello world', 'Hello', 'Say ', ' world');
        $drawn->strokes = [['space' => 'page', 'points' => [[0.1, 0.2], [0.3, 0.4]]]];
        $drawn->status = SiteReviewCommentStatus::Addressed;
        $plain = new SiteReviewComment($project, 1, 'A page note', 'https://example.com/', createdAt: $createdAt->modify('+1 hour'));
        foreach ([$drawn, $plain] as $comment) {
            $this->em->persist($comment);
            $this->em->persist(new CardSiteReviewComment($card, $comment, true));
        }
        $this->em->flush();
        $this->em->clear();

        $rows = iterator_to_array($this->exporter->export($owner), false);

        self::assertSame([
            [
                'id' => (string) $drawn->id,
                'url' => 'https://example.com/checkout',
                'context' => 'card:preview',
                'anchors' => [
                    ['selector' => '.hero h1', 'text' => 'Hello world', 'quote' => 'Hello', 'quotePrefix' => 'Say ', 'quoteSuffix' => ' world'],
                ],
                'body' => 'Point here',
                'strokes' => [['space' => 'page', 'points' => [[0.1, 0.2], [0.3, 0.4]]]],
                'status' => 'addressed',
                'createdAt' => $createdAt->format(\DateTimeInterface::ATOM),
            ],
            [
                'id' => (string) $plain->id,
                'url' => 'https://example.com/',
                'context' => null,
                'anchors' => [],
                'body' => 'A page note',
                'strokes' => [],
                'status' => 'pending',
                'createdAt' => $createdAt->modify('+1 hour')->format(\DateTimeInterface::ATOM),
            ],
        ], $rows[0]['feedback']);
    }

    public function test_a_child_exports_its_epic_and_an_epic_its_lane_setting(): void
    {
        $owner = $this->user('card-export-parent');
        $project = new Project($owner, 'parent-'.uniqid());
        $this->em->persist($project);
        $this->seedColumns($project);

        $epic = new Card(project: $project, column: $this->column($project, 'backlog'), title: 'Epic', body: '', number: 1, type: CardType::Epic, createdAt: new \DateTimeImmutable('2026-03-01 09:00:00'));
        $epic->laneEnabled = false;
        $child = new Card(project: $project, column: $this->column($project, 'backlog'), title: 'Child', body: '', number: 2, createdAt: new \DateTimeImmutable('2026-03-02 09:00:00'));
        $child->parent = $epic;
        $this->em->persist($epic);
        $this->em->persist($child);
        $this->em->flush();
        $this->em->clear();

        $rows = iterator_to_array($this->exporter->export($owner), false);

        self::assertCount(2, $rows);
        self::assertIsArray($rows[0]);
        self::assertIsArray($rows[1]);
        self::assertSame(['epic', null, null, false], [$rows[0]['type'], $rows[0]['parentCardId'], $rows[0]['parentNumber'], $rows[0]['laneEnabled']]);
        self::assertSame([(string) $epic->id, 1], [$rows[1]['parentCardId'], $rows[1]['parentNumber']]);
    }

    public function test_each_card_exports_its_links_as_it_reads_them(): void
    {
        $owner = $this->user('card-export-links');
        $project = new Project($owner, 'links-'.uniqid());
        $this->em->persist($project);
        $this->seedColumns($project);

        $blocker = new Card(project: $project, column: $this->column($project, 'backlog'), title: 'First', body: '', number: 1, createdAt: new \DateTimeImmutable('2026-03-01 09:00:00'));
        $blocked = new Card(project: $project, column: $this->column($project, 'backlog'), title: 'Second', body: '', number: 2, createdAt: new \DateTimeImmutable('2026-03-02 09:00:00'));
        $this->em->persist($blocker);
        $this->em->persist($blocked);
        $this->em->persist(new CardLink($blocker, $blocked, CardLinkKind::Blocks));
        $this->em->flush();
        $this->em->clear();

        $rows = iterator_to_array($this->exporter->export($owner), false);

        self::assertCount(2, $rows);
        self::assertIsArray($rows[0]);
        self::assertIsArray($rows[1]);
        self::assertSame('First', $rows[0]['title']);
        self::assertSame([['cardId' => (string) $blocked->id, 'number' => 2, 'kind' => 'blocks']], $rows[0]['relatedCards']);
        self::assertSame([['cardId' => (string) $blocker->id, 'number' => 1, 'kind' => 'blocked-by']], $rows[1]['relatedCards']);
    }

    public function test_it_exports_the_owner_cards_only(): void
    {
        $owner = $this->user('card-export-mine');
        $stranger = $this->user('card-export-theirs');

        $mine = new Project($owner, 'mine-'.uniqid());
        $theirs = new Project($stranger, 'theirs-'.uniqid());
        $this->em->persist($mine);
        $this->em->persist($theirs);
        $this->seedColumns($mine);
        $this->seedColumns($theirs);
        $this->em->persist(new Card(project: $mine, column: $this->column($mine, 'backlog'), title: 'Mine', body: '', number: 1));
        $this->em->persist(new Card(project: $theirs, column: $this->column($theirs, 'backlog'), title: 'Theirs', body: '', number: 1));
        $this->em->flush();
        $this->em->clear();

        $rows = iterator_to_array($this->exporter->export($owner), false);

        self::assertCount(1, $rows);
        self::assertIsArray($rows[0]);
        self::assertSame('Mine', $rows[0]['title']);
    }

    /** @param non-empty-string $label */
    private function user(string $label): User
    {
        $user = new User(fullName: 'Riley', email: $label.'-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($user);

        return $user;
    }
}
