<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Repository\BridgeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One CLI bridge as its last heartbeat described it. The row belongs to an
 * account rather than a project, because one bridge follows several projects,
 * and each heartbeat replaces it.
 *
 * The key is the owner and the bridge id together, so two accounts that share
 * one config directory, and so one bridge id, each keep a row of their own.
 */
#[ORM\Entity(repositoryClass: BridgeRepository::class)]
#[ORM\Table(name: 'bridges')]
class Bridge
{
    public const int MAX_CLI_VERSION_LENGTH = 100;

    /**
     * @param list<string> $projects
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public readonly User $owner,

        /** The id the bridge generates on its first start, so the server never assigns it. */
        #[ORM\Column(type: UuidType::NAME)]
        #[ORM\Id]
        public readonly Uuid $id,

        /** The ids of the owner's projects the bridge follows. Plain values, so a deleted project leaves no dangling key. */
        #[ORM\Column(type: Types::JSON)]
        public array $projects,

        #[ORM\Column(name: 'cli_version', length: self::MAX_CLI_VERSION_LENGTH)]
        public string $cliVersion,

        /** The server clock at the last heartbeat. */
        #[ORM\Column(name: 'last_seen_at')]
        public \DateTimeImmutable $lastSeenAt,
    ) {
    }
}
