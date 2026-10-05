<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Messenger;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Workflow\Command\EvaluateWorkflowCardHandler;
use App\Module\Workflow\Engine\Engine;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Messenger\EvaluateCardHandler;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class EvaluateCardHandlerTest extends KernelTestCase
{
    use ActionScenario;

    public function test_the_engine_moves_a_bound_lifecycle_card_whose_product_design_is_approved(): void
    {
        $card = $this->approvedProductDesignCard();

        $this->handler()(new EvaluateCard((string) $card->id));

        self::assertSame('tech-design', $this->columnOf($card));
    }

    private function approvedProductDesignCard(): Card
    {
        self::bootKernel();
        $project = $this->workflowProject('evaluate-card');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'product-design');

        $document = new Document($project->owner, $project, 'Product');
        $document->status = DocumentStatus::Approved;
        $tag = new Tag($project, 'product-design');
        $this->em()->persist($tag);
        $document->tags->add($tag);
        $this->em()->persist($document);
        $this->em()->persist(new CardDocument($card, $document));
        $this->em()->flush();

        return $card;
    }

    private function columnOf(Card $card): ?string
    {
        $this->em()->clear();

        return $this->em()->find(Card::class, $card->id)?->column->slug;
    }

    private function handler(): EvaluateCardHandler
    {
        return new EvaluateCardHandler(
            new EvaluateWorkflowCardHandler($this->service(Engine::class), new MockClock('2026-10-02 12:00:00')),
        );
    }
}
