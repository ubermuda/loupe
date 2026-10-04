<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Service\EventStreamGate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** Registers a bridge that the events endpoints admit, and builds the header that names it. */
final readonly class EventBridge
{
    /** @param list<string> $capabilities */
    public static function register(EntityManagerInterface $em, User $owner, array $capabilities = [Bridge::CAPABILITY_WORK_REQUESTS]): Bridge
    {
        $bridge = new Bridge($owner, Uuid::v4(), [], '1.0.0', new \DateTimeImmutable());
        $bridge->capabilities = $capabilities;
        $em->persist($bridge);
        $em->flush();

        return $bridge;
    }

    /** @return array<string, string> */
    public static function server(string $raw, Bridge|string $bridge): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
            'HTTP_'.strtoupper(str_replace('-', '_', EventStreamGate::HEADER)) => $bridge instanceof Bridge ? (string) $bridge->id : $bridge,
        ];
    }
}
