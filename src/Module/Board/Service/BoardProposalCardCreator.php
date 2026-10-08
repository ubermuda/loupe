<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\CardReporter;
use App\Module\Insights\Proposal\ProposalCard;
use App\Module\Insights\Proposal\ProposalCardCreatorInterface;
use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/** An agent reported the proposal and a person accepted it. */
#[AsAlias(ProposalCardCreatorInterface::class)]
final readonly class BoardProposalCardCreator implements ProposalCardCreatorInterface
{
    public function __construct(
        private CreateCardHandler $createCard,
    ) {
    }

    #[\Override]
    public function createBacklogCard(Project $project, ProposalCard $card): Uuid
    {
        $created = ($this->createCard)(new CreateCardCommand(
            project: $project,
            title: $card->title,
            body: $card->body,
            type: 'feature',
            reporter: CardReporter::Agent,
            documentIds: null === $card->reportDocumentId ? [] : [$card->reportDocumentId->toRfc4122()],
            actor: CardReporter::Human,
        ));

        return $created->id ?? throw new \LogicException('A stored card has an id.');
    }
}
