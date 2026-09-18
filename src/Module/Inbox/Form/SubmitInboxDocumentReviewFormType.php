<?php

declare(strict_types=1);

namespace App\Module\Inbox\Form;

use App\Module\Inbox\Entity\InboxItem;
use App\Module\Review\Form\SubmitReviewFormType;
use App\Module\Review\Form\SubmitReviewRequest;
use Symfony\Component\Form\AbstractType;

/** @extends AbstractType<SubmitReviewRequest> */
final class SubmitInboxDocumentReviewFormType extends AbstractType
{
    public static function nameFor(InboxItem $item): string
    {
        return 'inbox_document_review_'.$item->id;
    }

    #[\Override]
    public function getParent(): string
    {
        return SubmitReviewFormType::class;
    }
}
