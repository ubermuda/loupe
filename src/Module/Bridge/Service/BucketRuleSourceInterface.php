<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Gives the bucket rules of a project. The first rule that matches a call takes it. */
#[AutoconfigureTag('app.bucket_rule_source')]
interface BucketRuleSourceInterface
{
    /** @return list<BucketRule> in order of position */
    public function rulesFor(Project $project): array;
}
