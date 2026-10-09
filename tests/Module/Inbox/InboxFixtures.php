<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Tag;
use App\Module\Review\Repository\TagRepository;
use App\Module\Workflow\Entity\WorkflowBinding;
use App\Module\Workflow\Entity\WorkflowSlotLink;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;
use Ubermuda\FeatureFlagsBundle\Reader\FeatureFlagReaderInterface;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

/** Persists and does not flush, so a test decides when the rows commit. */
trait InboxFixtures
{
    use BoardColumnFixtures;

    /** @var array<string, Tag> project id and name => tag */
    private array $stageTags = [];

    /** @var array<string, true> project id, or project id and slug => bound */
    private array $stageLinks = [];

    /** Stores the flag and drops the reader's copy, which lasts for the whole test otherwise. */
    private function switchFlag(EntityManagerInterface $em, string $name, bool $enabled): void
    {
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[$name]->value = $enabled;
        $em->flush();

        $reader = self::getContainer()->get(FeatureFlagReaderInterface::class);
        self::assertInstanceOf(ResetInterface::class, $reader);
        $reader->reset();
    }

    private function owner(EntityManagerInterface $em, string $slug): User
    {
        $owner = new User(fullName: 'Riley', email: $slug.'-'.uniqid().'@example.com', password: 'hashed');
        $em->persist($owner);

        return $owner;
    }

    private function project(EntityManagerInterface $em, User $owner, string $slug): Project
    {
        $project = new Project($owner, $slug.'-'.uniqid());
        $em->persist($project);
        $this->seedColumns($project);

        return $project;
    }

    private function card(EntityManagerInterface $em, Project $project, int $number = 1): Card
    {
        $card = new Card(project: $project, column: $this->column($project, 'backlog'), title: 'Ship it', body: 'Body', number: $number);
        $em->persist($card);

        return $card;
    }

    private function document(EntityManagerInterface $em, Project $project): Document
    {
        $document = new Document($project->owner, $project, 'The design');
        $em->persist($document);

        return $document;
    }

    private function item(EntityManagerInterface $em, Project $project, int $number = 1, string $title = 'Which column?'): InboxItem
    {
        $item = new InboxItem(project: $project, number: $number, kind: InboxItemKind::Question, title: $title, blocking: true, options: ['next', 'done']);
        $em->persist($item);

        return $item;
    }

    private function ask(EntityManagerInterface $em, Project $project): InboxAsk
    {
        $ask = new InboxAsk(project: $project, sessionId: Uuid::v4(), bridgeId: Uuid::v4(), context: 'Two decisions first');
        $em->persist($ask);

        return $ask;
    }

    /**
     * Tags the document for the stage and moves the card into the column the
     * stage starts from, so the document in review makes the card wait.
     *
     * @param 'tech-design'|'product-design' $stage
     */
    private function stageDocument(EntityManagerInterface $em, Document $document, Card $card, string $stage = 'tech-design'): void
    {
        $this->tagDocument($em, $document, 'tech-design' === $stage ? ['tech-design', 'decisions'] : ['product-design']);
        $card->column = $this->stageColumn($em, $card->project, $stage);
    }

    /** @param list<string> $names */
    private function tagDocument(EntityManagerInterface $em, Document $document, array $names): void
    {
        $tags = self::getContainer()->get(TagRepository::class);
        self::assertInstanceOf(TagRepository::class, $tags);

        foreach ($names as $name) {
            $key = $document->project->id.'/'.$name;
            $tag = $tags->findOneByProjectAndName($document->project, $name) ?? $this->stageTags[$key] ?? new Tag($document->project, $name);
            $this->stageTags[$key] = $tag;
            $em->persist($tag);
            if (!$document->tags->contains($tag)) {
                $document->tags->add($tag);
            }
        }
    }

    /**
     * The project's column with that slug, created open when the seeded board lacks it.
     * The project gets the Lifecycle template, and the column the slot of its slug.
     */
    private function stageColumn(EntityManagerInterface $em, Project $project, string $slug): BoardColumn
    {
        $repository = self::getContainer()->get(BoardColumnRepository::class);
        self::assertInstanceOf(BoardColumnRepository::class, $repository);

        $key = $project->id.'/'.$slug;
        $column = $repository->findOneBy(['project' => $project->id, 'slug' => $slug]) ?? $this->seededColumns[$key] ?? null;
        if (null === $column) {
            $column = new BoardColumn(project: $project, label: $slug, slug: $slug, position: 10);
            $em->persist($column);
        }

        $bindings = self::getContainer()->get(WorkflowBindingRepository::class);
        self::assertInstanceOf(WorkflowBindingRepository::class, $bindings);
        $projectId = $project->id ?? throw new \LogicException('The project has no id.');
        if (!isset($this->stageLinks[(string) $projectId]) && null === $bindings->findOneByProjectId($projectId)) {
            $shipped = self::getContainer()->get(ShippedTemplates::class);
            self::assertInstanceOf(ShippedTemplates::class, $shipped);
            $em->persist(new WorkflowBinding($project, 'lifecycle', 1, $shipped->source('lifecycle')));
        }
        $this->stageLinks[(string) $projectId] = true;
        if (!isset($this->stageLinks[$key])) {
            $em->persist(new WorkflowSlotLink($project, $slug, $column->id));
            $this->stageLinks[$key] = true;
        }

        return $this->seededColumns[$key] = $column;
    }
}
