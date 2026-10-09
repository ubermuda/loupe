<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Service\NewTextAnchorsBuilder;
use App\Module\Review\ValueObject\NewTextAnchors;
use App\Module\Review\ValueObject\NewTextReason;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NewTextAnchorsBuilderTest extends KernelTestCase
{
    public function test_a_phrase_added_inside_a_paragraph_is_the_only_new_text(): void
    {
        $result = $this->between("The rollout takes one step.\n", "The rollout takes one careful step.\n");

        self::assertNull($result->reason);
        self::assertSame(['careful'], $this->quotes($result));
    }

    public function test_an_added_paragraph_is_one_anchor_with_its_context(): void
    {
        $result = $this->between("Intro.\n\nOutro.\n", "Intro.\n\nA brand new paragraph.\n\nOutro.\n");

        self::assertSame(['A brand new paragraph.'], $this->quotes($result));
        self::assertStringEndsWith('Intro.', trim($result->anchors[0]->prefix));
        self::assertStringStartsWith('Outro.', trim($result->anchors[0]->suffix));
    }

    public function test_two_added_paragraphs_in_a_row_join_into_one_anchor(): void
    {
        $result = $this->between("Intro.\n\nOutro.\n", "Intro.\n\nFirst new.\n\nSecond new.\n\nOutro.\n");

        self::assertCount(1, $result->anchors);
        self::assertStringContainsString('First new.', $result->anchors[0]->quote);
        self::assertStringContainsString('Second new.', $result->anchors[0]->quote);
    }

    public function test_a_change_that_only_deletes_has_no_new_text(): void
    {
        $result = $this->between("Intro.\n\nGone soon.\n\nOutro.\n", "Intro.\n\nOutro.\n");

        self::assertNull($result->reason);
        self::assertSame([], $result->anchors);
    }

    public function test_identical_versions_have_no_new_text(): void
    {
        $result = $this->between("Same.\n", "Same.\n");

        self::assertNull($result->reason);
        self::assertSame([], $result->anchors);
    }

    public function test_a_repeated_phrase_keeps_the_context_that_tells_the_copies_apart(): void
    {
        $result = $this->between("Ship it.\n\nShip it.\n", "Ship it.\n\nShip it.\n\nThen wait. Ship it.\n");

        self::assertCount(1, $result->anchors);
        self::assertSame('Then wait. Ship it.', $result->anchors[0]->quote);
        self::assertStringEndsWith('Ship it.', trim($result->anchors[0]->prefix));
    }

    public function test_an_anchor_after_an_emoji_counts_characters_not_bytes(): void
    {
        $result = $this->between("Ship it 🚀 today.\n", "Ship it 🚀 today, early.\n");

        self::assertSame([', early'], $this->quotes($result));
        self::assertSame('Ship it 🚀 today', $result->anchors[0]->prefix);
        self::assertSame('.', trim($result->anchors[0]->suffix));
    }

    public function test_the_first_version_has_no_earlier_version_to_compare_with(): void
    {
        [, $first] = $this->seed("Only version.\n");

        $result = self::getContainer()->get(NewTextAnchorsBuilder::class)->build($first);

        self::assertSame(NewTextReason::NoPreviousVersion, $result->reason);
        self::assertSame([], $result->anchors);
    }

    public function test_a_pair_the_differ_refuses_gives_a_reason_and_no_anchors(): void
    {
        $result = $this->between("One.\n", str_repeat("line\n", 2_100)."One more.\n");

        self::assertSame(NewTextReason::DiffRefused, $result->reason);
        self::assertSame([], $result->anchors);
    }

    public function test_every_quote_is_found_verbatim_in_the_plain_text(): void
    {
        [$document, $first] = $this->seed("Intro paragraph.\n\n- one\n- two\n");
        $this->revise($document, "Intro paragraph with more.\n\n- one\n- two\n- three\n\n## Added heading\n\nBody text.\n");
        $latest = self::getContainer()->get(DocumentVersionRepository::class)->findLatest($document);

        $result = self::getContainer()->get(NewTextAnchorsBuilder::class)->build($latest);

        self::assertNotSame([], $result->anchors);
        foreach ($result->anchors as $anchor) {
            self::assertStringContainsString($anchor->quote, $latest->plainText());
        }
        self::assertSame(1, $first->versionNumber);
    }

    private function between(string $old, string $new): NewTextAnchors
    {
        [$document] = $this->seed($old);
        $this->revise($document, $new);
        $latest = self::getContainer()->get(DocumentVersionRepository::class)->findLatest($document);
        self::assertSame(2, $latest->versionNumber);

        return self::getContainer()->get(NewTextAnchorsBuilder::class)->build($latest);
    }

    /** @return list<string> */
    private function quotes(NewTextAnchors $result): array
    {
        return array_map(static fn ($anchor): string => $anchor->quote, $result->anchors);
    }

    /** @return array{Document, DocumentVersion} */
    private function seed(string $markdown): array
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $owner = new User(fullName: 'Author', email: 'author-'.uniqid().'@example.com', password: 'hashed');
        $em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        $document = self::getContainer()->get(CreateDocumentHandler::class)(new CreateDocumentCommand($project, 'Doc', $markdown));
        $first = self::getContainer()->get(DocumentVersionRepository::class)->findLatest($document);

        return [$document, $first];
    }

    private function revise(Document $document, string $markdown): void
    {
        self::getContainer()->get(ReviseDocumentHandler::class)(new ReviseDocumentCommand($document, $markdown, 'Revised.'));
    }
}
