<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Mcp\InboxGetTool;
use App\Module\Inbox\Mcp\InboxListTool;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxScenario;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** inbox_list and inbox_get record a read only for the reader session's own closed asks. */
final class InboxReadRecordingTest extends KernelTestCase
{
    use InboxScenario;
    use InboxToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private InboxListTool $list;
    private InboxGetTool $get;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $list = self::getContainer()->get(InboxListTool::class);
        self::assertInstanceOf(InboxListTool::class, $list);
        $this->list = $list;

        $get = self::getContainer()->get(InboxGetTool::class);
        self::assertInstanceOf(InboxGetTool::class, $get);
        $this->get = $get;

        $this->enableInbox();
    }

    public function test_a_list_by_the_reader_session_records_the_read_on_its_closed_ask(): void
    {
        [$project, $item, $ask] = $this->closedAsk('inbox-read-list');
        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->list)(readerSessionId: (string) $ask->sessionId);

        // The stamp says the session saw the answer, so the row it stamps must carry it.
        self::assertSame([(string) $item->id], array_column($result['items'], 'itemId'));
        self::assertSame(['JSON', 'CSV'], $result['items'][0]['options']);
        self::assertSame([0], $result['items'][0]['selectedOptions']);
        self::assertNull($result['items'][0]['answerText']);
        self::assertNull($result['items'][0]['closeNote']);
        self::assertNotNull($this->readAt($ask, $item));
    }

    public function test_a_get_by_the_reader_session_records_the_read_on_its_closed_ask(): void
    {
        [$project, $item, $ask] = $this->closedAsk('inbox-read-get');
        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->get)((string) $item->id, readerSessionId: (string) $ask->sessionId);

        self::assertSame('answered', $result['state']);
        self::assertNotNull($this->readAt($ask, $item));
    }

    public function test_a_read_on_an_open_ask_records_nothing(): void
    {
        $project = $this->makeProject('inbox-read-open');
        $item = $this->question($this->em, $project, 1);
        $ask = $this->askHolding($this->em, $project, [$item]);
        $this->actAsMcpTokenBoundTo($project);

        $listed = ($this->list)(readerSessionId: (string) $ask->sessionId);
        ($this->get)((string) $item->id, readerSessionId: (string) $ask->sessionId);

        self::assertSame(1, $listed['total']);
        self::assertNull($this->readAt($ask, $item));
    }

    public function test_another_session_id_records_nothing(): void
    {
        [$project, $item, $ask] = $this->closedAsk('inbox-read-other-session');
        $this->actAsMcpTokenBoundTo($project);
        $stranger = (string) Uuid::v4();

        $listed = ($this->list)(readerSessionId: $stranger);
        ($this->get)((string) $item->id, readerSessionId: $stranger);

        self::assertSame(1, $listed['total']);
        self::assertNull($this->readAt($ask, $item));
    }

    public function test_filtering_by_session_id_alone_records_nothing(): void
    {
        [$project, $item, $ask] = $this->closedAsk('inbox-read-filter-only');
        $this->actAsMcpTokenBoundTo($project);

        $listed = ($this->list)(sessionId: (string) $ask->sessionId);

        self::assertSame([(string) $item->id], array_column($listed['items'], 'itemId'));
        self::assertNull($this->readAt($ask, $item));
    }

    public function test_a_reader_that_filters_by_another_session_marks_nothing_of_that_session(): void
    {
        [$project, $theirItem, $theirAsk] = $this->closedAsk('inbox-read-filter-other');
        $mine = $this->answered($this->em, $this->question($this->em, $project, 2));
        $myAsk = $this->askHolding($this->em, $project, [$mine], closedAt: new \DateTimeImmutable());
        $this->actAsMcpTokenBoundTo($project);

        $listed = ($this->list)(sessionId: (string) $theirAsk->sessionId, readerSessionId: (string) $myAsk->sessionId);

        self::assertSame([(string) $theirItem->id], array_column($listed['items'], 'itemId'));
        self::assertNull($this->readAt($theirAsk, $theirItem));
        self::assertNull($this->readAt($myAsk, $mine));
    }

    public function test_only_the_items_a_page_returns_are_recorded(): void
    {
        $project = $this->makeProject('inbox-read-page');
        $older = $this->answered($this->em, $this->question($this->em, $project, 1));
        $newer = $this->answered($this->em, $this->question($this->em, $project, 2));
        $ask = $this->askHolding($this->em, $project, [$older, $newer], closedAt: new \DateTimeImmutable());
        $this->actAsMcpTokenBoundTo($project);

        $listed = ($this->list)(readerSessionId: (string) $ask->sessionId, perPage: 1);

        self::assertSame([(string) $newer->id], array_column($listed['items'], 'itemId'));
        self::assertNotNull($this->readAt($ask, $newer));
        self::assertNull($this->readAt($ask, $older));
    }

    public function test_a_second_read_keeps_the_first_timestamp(): void
    {
        [$project, $item, $ask] = $this->closedAsk('inbox-read-twice');
        $this->actAsMcpTokenBoundTo($project);
        ($this->get)((string) $item->id, readerSessionId: (string) $ask->sessionId);
        $this->em->getConnection()->executeStatement(
            "UPDATE inbox_ask_items SET read_at = '2026-01-02 03:04:05' WHERE ask_id = ? AND item_id = ?",
            [(string) $ask->id, (string) $item->id],
        );

        ($this->list)(readerSessionId: (string) $ask->sessionId);
        ($this->get)((string) $item->id, readerSessionId: (string) $ask->sessionId);

        self::assertSame('2026-01-02 03:04:05', $this->readAt($ask, $item));
    }

    /**
     * The tool loads the item before the handler takes the lock. An answer that
     * lands in between must reach the reader, or its stamp covers an answer it never saw.
     */
    public function test_a_recorded_read_returns_the_answer_as_stored_under_the_lock(): void
    {
        [$project, $item, $ask] = $this->closedAsk('inbox-read-fresh');
        $this->actAsMcpTokenBoundTo($project);
        $this->em->getConnection()->executeStatement(
            "UPDATE inbox_items SET answer_text = 'Use CSV' WHERE id = ?",
            [(string) $item->id],
        );

        $result = ($this->get)((string) $item->id, readerSessionId: (string) $ask->sessionId);

        self::assertSame('Use CSV', $result['answerText']);
        self::assertNotNull($this->readAt($ask, $item));
    }

    /** The item is managed from its creation, so the page query alone would return the old copy. */
    public function test_a_recorded_list_returns_the_answer_as_stored_under_the_lock(): void
    {
        [$project, $item, $ask] = $this->closedAsk('inbox-read-list-fresh');
        $this->actAsMcpTokenBoundTo($project);
        $this->em->getConnection()->executeStatement(
            "UPDATE inbox_items SET answer_text = 'Use CSV' WHERE id = ?",
            [(string) $item->id],
        );

        $result = ($this->list)(readerSessionId: (string) $ask->sessionId);

        self::assertSame('Use CSV', $result['items'][0]['answerText']);
        self::assertNotNull($this->readAt($ask, $item));
    }

    public function test_one_item_in_two_closed_asks_of_the_session_is_recorded_on_both(): void
    {
        $project = $this->makeProject('inbox-read-two-asks');
        $item = $this->answered($this->em, $this->question($this->em, $project, 1));
        $sessionId = Uuid::v4();
        $asks = [];
        foreach (['-2 hours', '-1 hour'] as $closedAt) {
            $ask = new InboxAsk(project: $project, sessionId: $sessionId, bridgeId: Uuid::v4());
            $ask->closedAt = new \DateTimeImmutable($closedAt);
            $ask->items->add(new InboxAskItem($ask, $item));
            $this->em->persist($ask);
            $asks[] = $ask;
        }
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        ($this->list)(readerSessionId: (string) $sessionId);

        self::assertNotNull($this->readAt($asks[0], $item));
        self::assertNotNull($this->readAt($asks[1], $item));
    }

    /** A read stamps the ask item and writes nothing to the item itself, whatever the reload copies onto it. */
    public function test_a_recorded_read_writes_only_the_stamp(): void
    {
        [$project, $item, $ask] = $this->closedAsk('inbox-read-no-write');
        $this->actAsMcpTokenBoundTo($project);
        $this->em->getConnection()->executeStatement(
            "UPDATE inbox_items SET answer_text = 'Use CSV' WHERE id = ?",
            [(string) $item->id],
        );
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $queries->reset();

        ($this->list)(readerSessionId: (string) $ask->sessionId);
        ($this->get)((string) $item->id, readerSessionId: (string) $ask->sessionId);

        $statements = [];
        foreach ($queries->getData() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $statements[] = (string) $query['sql'];
            }
        }
        self::assertNotNull($this->readAt($ask, $item));
        self::assertNotEmpty(array_filter($statements, static fn (string $sql): bool => str_starts_with($sql, 'UPDATE inbox_ask_items')));
        self::assertSame([], array_values(array_filter($statements, static fn (string $sql): bool => str_starts_with($sql, 'UPDATE inbox_items'))));
    }

    /** @return array{Project, InboxItem, InboxAsk} */
    private function closedAsk(string $label): array
    {
        $project = $this->makeProject($label);
        $item = $this->answered($this->em, $this->question($this->em, $project, 1));
        $ask = $this->askHolding($this->em, $project, [$item], closedAt: new \DateTimeImmutable());

        return [$project, $item, $ask];
    }

    /** Read through the connection, because the recorder writes past the identity map. */
    private function readAt(InboxAsk $ask, InboxItem $item): ?string
    {
        $value = $this->em->getConnection()->fetchOne(
            'SELECT read_at FROM inbox_ask_items WHERE ask_id = ? AND item_id = ?',
            [(string) $ask->id, (string) $item->id],
        );
        self::assertNotFalse($value, 'The ask does not hold the item.');

        return null === $value ? null : (string) $value;
    }
}
