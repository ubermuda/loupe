<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Insights\Proposal\ProposalCard;
use App\Module\Insights\Proposal\ProposalCardCreatorInterface;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardTypeCatalog;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/** An agent reported the proposal and a person accepted it. */
#[AsAlias(ProposalCardCreatorInterface::class)]
final readonly class BoardProposalCardCreator implements ProposalCardCreatorInterface
{
    public function __construct(
        private CreateCardHandler $createCard,
        private CardTypeCatalog $catalog,
    ) {
    }

    #[\Override]
    public function createBacklogCard(Project $project, ProposalCard $card): Uuid
    {
        $created = ($this->createCard)(new CreateCardCommand(
            project: $project,
            title: $card->title,
            body: $card->body,
            type: $this->catalog->forProject($project->requireId())->defaultKey,
            reporter: Actor::Agent,
            documentIds: null === $card->reportDocumentId ? [] : [$card->reportDocumentId->toRfc4122()],
            actor: Actor::Human,
        ));

        return $created->id ?? throw new \LogicException('A stored card has an id.');
    }
}
