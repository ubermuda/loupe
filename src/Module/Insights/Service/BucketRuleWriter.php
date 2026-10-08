<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Exception\DomainErrors;
use App\Module\Bridge\Service\BucketRule;
use App\Module\Insights\Entity\InsightsBucketRule;
use App\Module\Insights\Repository\InsightsBucketRuleRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;

/** Validates a bucket rule and appends it to the rules of a project. */
final readonly class BucketRuleWriter
{
    public const string PATTERN_BLANK = 'insights.bucket_rule.error.pattern_blank';
    public const string PATTERN_TOO_LONG = 'insights.bucket_rule.error.pattern_too_long';
    public const string BUCKET_INVALID = 'insights.bucket_rule.error.bucket_invalid';
    public const string LIMIT_REACHED = 'insights.bucket_rule.error.limit_reached';

    public function __construct(
        private InsightsBucketRuleRepository $insightsBucketRules,
        private EntityManagerInterface $em,
    ) {
    }

    /** @return array<string, string> the refusal for each field, empty for a valid rule */
    public static function errors(string $pattern, string $bucket): array
    {
        $errors = [];
        $pattern = trim($pattern);
        if ('' === $pattern) {
            $errors['pattern'] = self::PATTERN_BLANK;
        } elseif (mb_strlen($pattern) > InsightsBucketRule::MAX_PATTERN_LENGTH) {
            $errors['pattern'] = self::PATTERN_TOO_LONG;
        }
        if (1 !== preg_match(BucketRule::NAME_PATTERN, $bucket)) {
            $errors['bucket'] = self::BUCKET_INVALID;
        }

        return $errors;
    }

    /**
     * The caller holds the lock on the project, inside a transaction. A refusal
     * leaves as a value, because a throw closes the EntityManager.
     */
    public function append(Project $project, string $pattern, string $bucket): InsightsBucketRule|DomainErrors
    {
        $errors = self::errors($pattern, $bucket);
        if ([] !== $errors) {
            return new DomainErrors($errors);
        }
        if ($this->insightsBucketRules->countForProject($project) >= InsightsBucketRule::MAX_PER_PROJECT) {
            return new DomainErrors(['pattern' => self::LIMIT_REACHED]);
        }

        $rule = new InsightsBucketRule($project, trim($pattern), $bucket, $this->insightsBucketRules->nextPosition($project));
        $this->em->persist($rule);
        $this->em->flush();

        return $rule;
    }
}
