<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Contract;

use App\Module\Review\Entity\DocumentStatus;
use App\Module\Workflow\Contract\DocumentStatuses;
use PHPUnit\Framework\TestCase;

final class DocumentStatusesTest extends TestCase
{
    public function test_the_contract_lists_the_statuses_of_a_review_document_in_their_order(): void
    {
        self::assertSame(DocumentStatus::values(), DocumentStatuses::ALL);
    }
}
