<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Fact;

use App\Module\Board\Workflow\DocumentsFacts;
use App\Module\Board\Workflow\ParentFacts;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\PullRequestList;
use App\Module\Workflow\Contract\Unreadable;
use App\Module\Workflow\Contract\UnreadableKind;
use PHPUnit\Framework\TestCase;

final class FactsTest extends TestCase
{
    public function test_a_copy_with_provided_facts_replaces_only_the_classes_it_names(): void
    {
        $facts = FactsMother::facts(card: FactsMother::card(isChild: true), provided: [ProvidedFacts::class => new ProvidedFacts(ready: true)], fingerprints: [ProvidedFacts::class => 'before']);
        $documents = new DocumentsFacts([new DocumentFacts(['design'], 'approved', 'one')]);

        $copy = $facts->withProvided([DocumentsFacts::class => $documents], [DocumentsFacts::class => 'after']);

        self::assertSame($documents, $copy->get(DocumentsFacts::class));
        self::assertSame('after', $copy->fingerprints[DocumentsFacts::class]);
        self::assertTrue($copy->get(ParentFacts::class)->isChild);
        self::assertSame($facts->get(ProvidedFacts::class), $copy->get(ProvidedFacts::class));
        self::assertSame('before', $copy->fingerprints[ProvidedFacts::class]);
        self::assertSame([], $facts->get(DocumentsFacts::class)->documents, 'The original keeps what it had.');
        self::assertSame($facts->slot, $copy->slot);
    }

    public function test_a_copy_with_provided_facts_and_no_fingerprint_drops_the_fingerprint_of_the_replaced_class(): void
    {
        $facts = FactsMother::facts();

        $copy = $facts->withProvided([DocumentsFacts::class => new DocumentsFacts([])]);

        self::assertArrayNotHasKey(DocumentsFacts::class, $copy->fingerprints);
        self::assertArrayHasKey(ParentFacts::class, $copy->fingerprints);
    }

    public function test_a_copy_can_replace_a_class_with_an_unreadable_source(): void
    {
        $copy = FactsMother::facts()->withProvided([DocumentsFacts::class => new Unreadable(UnreadableKind::Failed, 'workflow.source.board')]);

        self::assertSame(UnreadableKind::Failed, $copy->unreadable(DocumentsFacts::class)?->kind);
    }

    public function test_the_pull_requests_are_empty_when_the_list_cannot_be_read(): void
    {
        $pullRequest = FactsMother::pullRequest();
        $facts = FactsMother::facts(pullRequest: $pullRequest, pullRequests: [$pullRequest]);

        self::assertSame([$pullRequest], $facts->pullRequests());
        self::assertSame([], $facts->withProvided([PullRequestList::class => new Unreadable(UnreadableKind::Off, 'workflow.source.board')])->pullRequests());
    }
}
