<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Contract;

use App\Module\Board\Workflow\ChildrenFacts;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Workflow\Contract\ChildFacts;
use App\Module\Workflow\Contract\DocumentStatuses;
use PHPUnit\Framework\TestCase;

final class ContractParityTest extends TestCase
{
    public function test_the_contract_lists_the_statuses_of_a_review_document_in_their_order(): void
    {
        self::assertSame(DocumentStatus::values(), DocumentStatuses::ALL);
    }

    public function test_the_facts_about_the_children_of_a_card_are_child_facts(): void
    {
        self::assertTrue(is_a(ChildrenFacts::class, ChildFacts::class, true));
    }
}
