<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Entity;

/** The conclusion of the agent review check, as the forge names it. */
enum AgentReviewConclusion: string
{
    case Success = 'success';
    case Failure = 'failure';
}
