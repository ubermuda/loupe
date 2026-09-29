<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\DecisionAnswer;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DecisionAnswerRepository;
use App\Module\Review\Service\DecisionAnswerExporter;
use PHPUnit\Framework\TestCase;

final class DecisionAnswerExporterTest extends TestCase
{
    public function test_exports_the_answers_the_user_saved(): void
    {
        $user = new User('Alice A', 'alice@example.com', 'x');
        $document = new Document($user, new Project($user, 'My project'), 'My doc');
        $answer = new DecisionAnswer($document, 'deploy-target', 'Staging is down.', $user, 2, new \DateTimeImmutable('2026-09-28T10:00:00+00:00'));

        $repo = $this->createStub(DecisionAnswerRepository::class);
        $repo->method('streamByAnsweredBy')->willReturn([$answer]);
        $exporter = new DecisionAnswerExporter($repo);

        self::assertSame([[
            'document' => 'My doc',
            'decisionId' => 'deploy-target',
            'note' => 'Staging is down.',
            'answeredAtVersion' => 2,
            'updatedAt' => '2026-09-28T10:00:00+00:00',
        ]], iterator_to_array($exporter->export($user)));
        self::assertSame('decision_answers.json', $exporter->filename());
    }
}
