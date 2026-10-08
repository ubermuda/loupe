<?php

declare(strict_types=1);

namespace App\Module\Insights\Entity;

use App\Module\Insights\Repository\InsightsBucketRuleRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** One time bucket rule of a project. The rule with the lowest position that matches a tool call takes it. */
#[ORM\Entity(repositoryClass: InsightsBucketRuleRepository::class)]
#[ORM\Index(name: 'idx_insights_bucket_rule_position', columns: ['project_id', 'position'])]
#[ORM\Table(name: 'insights_bucket_rules')]
class InsightsBucketRule
{
    public const int MAX_PER_PROJECT = 50;

    public const int MAX_PATTERN_LENGTH = 120;

    public const int MAX_BUCKET_LENGTH = 64;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        /** A glob: `*` matches any run of characters and `?` matches one. */
        #[ORM\Column(name: 'pattern', length: self::MAX_PATTERN_LENGTH)]
        public readonly string $pattern,

        #[ORM\Column(name: 'bucket', length: self::MAX_BUCKET_LENGTH)]
        public readonly string $bucket,

        #[ORM\Column(name: 'position')]
        public int $position,
    ) {
    }
}
