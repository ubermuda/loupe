<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Entity;

use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\DataExportStatus;
use App\Module\Account\Entity\User;
use PHPUnit\Framework\TestCase;

final class DataExportTest extends TestCase
{
    private function makeExport(): DataExport
    {
        return new DataExport(new User('alice', 'Alice A', 'alice@example.com', 'irrelevant'));
    }

    public function test_complete_issues_a_valid_token_and_sets_expiry(): void
    {
        $export = $this->makeExport();

        $raw = $export->complete();

        self::assertSame(DataExportStatus::Ready, $export->status);
        self::assertNotNull($export->completedAt);
        self::assertNotNull($export->expiresAt);
        self::assertTrue($export->isDownloadTokenValid($raw));
    }

    public function test_wrong_token_is_rejected(): void
    {
        $export = $this->makeExport();
        $export->complete();

        self::assertFalse($export->isDownloadTokenValid('not-the-token'));
    }

    public function test_pending_export_rejects_any_token(): void
    {
        self::assertFalse($this->makeExport()->isDownloadTokenValid('anything'));
    }

    public function test_expired_export_rejects_the_correct_token(): void
    {
        $export = $this->makeExport();
        $raw = $export->complete();
        $export->expiresAt = new \DateTimeImmutable('-1 minute');

        self::assertTrue($export->isExpired());
        self::assertFalse($export->isDownloadTokenValid($raw));
    }

    public function test_ready_export_with_null_expiry_rejects_the_correct_token(): void
    {
        $export = $this->makeExport();
        $raw = $export->complete();
        $export->expiresAt = null; // invalid state must fail closed

        self::assertFalse($export->isDownloadTokenValid($raw));
    }
}
