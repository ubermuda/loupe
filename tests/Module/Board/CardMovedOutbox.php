<?php

declare(strict_types=1);

namespace App\Tests\Module\Board;

use App\Module\Board\BoardEventType;
use App\Module\Project\Entity\Project;
use App\Outbox\Repository\OutboxEventRepository;
use PHPUnit\Framework\Assert;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class CardMovedOutbox
{
    /**
     * The decoded payload of the one board.card_moved row the project holds.
     *
     * @return array<mixed>
     */
    public static function onlyPayload(ContainerInterface $container, Project $project): array
    {
        $outbox = $container->get(OutboxEventRepository::class);
        Assert::assertInstanceOf(OutboxEventRepository::class, $outbox);

        $rows = $outbox->findBy(['project' => $project->id, 'type' => BoardEventType::CARD_MOVED]);
        Assert::assertCount(1, $rows);

        $payload = json_decode($rows[0]->payload, true, 512, \JSON_THROW_ON_ERROR);
        Assert::assertIsArray($payload);

        return $payload;
    }
}
