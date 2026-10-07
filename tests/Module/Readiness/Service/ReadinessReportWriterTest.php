<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Service;

use App\Module\Board\Entity\CardType;
use App\Module\Readiness\Command\ReportFinding;
use App\Module\Readiness\Entity\DiscoveryProposal;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Service\ReadinessReportWriter;
use App\Module\Review\Service\DecisionBlockService;
use App\Module\Review\Service\MarkdownRenderer;
use App\Tests\Module\Readiness\DiscoveryScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReadinessReportWriterTest extends KernelTestCase
{
    use DiscoveryScenario;

    private DiscoveryRun $run;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->run = $this->discoveryRun($this->discoveryCard($this->workflowProject('report-writer')));
    }

    public function test_the_report_groups_findings_and_opens_with_a_second_level_heading(): void
    {
        $markdown = $this->write('Use the lifecycle workflow.', [
            new ReportFinding('Tests run', 'ready', 'CI runs PHPUnit.'),
            new ReportFinding('Docs exist', 'gap', 'No README.'),
            new ReportFinding('Linter', 'ready', 'php-cs-fixer.'),
        ], []);

        self::assertStringStartsWith('## Summary', $markdown);
        self::assertDoesNotMatchRegularExpression('~^# ~m', $markdown);
        self::assertStringContainsString('Use the lifecycle workflow.', $markdown);
        self::assertMatchesRegularExpression('~### Ready\n\n1\. \*\*Tests run\*\*: CI runs PHPUnit\.\n2\. \*\*Linter\*\*: php-cs-fixer\.\n~', $markdown);
        self::assertMatchesRegularExpression('~### Gaps\n\n1\. \*\*Docs exist\*\*: No README\.\n~', $markdown);
    }

    public function test_a_report_with_no_tick_box_writes_no_decision_block(): void
    {
        $markdown = $this->write('', [new ReportFinding('Tests run', 'ready', 'Yes.')], [
            $this->proposal('docs', 'Write a README', null, 7),
        ]);

        self::assertStringNotContainsString('<!-- decision', $markdown);
        self::assertStringContainsString('### Write a README', $markdown);
        self::assertStringContainsString('Card 7 covers this.', $markdown);
    }

    public function test_a_report_with_no_findings_says_so(): void
    {
        self::assertSame(2, substr_count($this->write('', [], []), "None.\n"));
    }

    public function test_the_tick_boxes_are_one_multi_choice_block_that_leaves_out_a_covered_proposal(): void
    {
        $markdown = $this->write('', [], [
            $this->proposal('first', 'Add tests', 0),
            $this->proposal('covered', 'Add CI', null, 12),
            $this->proposal('second', 'Write *docs* [now]', 1),
        ]);

        self::assertSame(1, substr_count($markdown, '<!-- decision: proposals -->'));
        self::assertSame(1, substr_count($markdown, '<!-- /decision -->'));
        self::assertStringContainsString("- [ ] Add tests\n- [ ] Write \\*docs\\* \\[now\\]\n", $markdown);
        self::assertStringNotContainsString('- [ ] Add CI', $markdown);
    }

    public function test_loupe_reads_the_block_back_as_one_multi_choice_decision_whose_labels_are_the_titles(): void
    {
        $titles = ['Add tests', 'Write *docs* [now] (recommended: high)', 'Fix `build` & <deploy>'];
        $markdown = $this->write('', [], array_map(fn (string $title, int $position): DiscoveryProposal => $this->proposal('p'.$position, $title, $position), $titles, array_keys($titles)));

        $html = self::getContainer()->get(MarkdownRenderer::class);
        self::assertInstanceOf(MarkdownRenderer::class, $html);
        $blocks = self::getContainer()->get(DecisionBlockService::class);
        self::assertInstanceOf(DecisionBlockService::class, $blocks);
        $decisions = $blocks->extract($html->render($markdown));

        self::assertCount(1, $decisions);
        self::assertSame(ReadinessReportWriter::DECISION_ID, $decisions[0]->id);
        self::assertSame('multiple', $decisions[0]->type->value);
        self::assertSame(array_map(ReadinessReportWriter::pickLabel(...), $titles), $decisions[0]->options);
        self::assertSame('Write *docs* [now]', $decisions[0]->options[1]);
    }

    public function test_an_option_label_is_the_title_on_one_line(): void
    {
        self::assertSame('Add tests now', ReadinessReportWriter::optionLabel("  Add\ttests \n now "));
    }

    /**
     * @param list<ReportFinding>     $findings
     * @param list<DiscoveryProposal> $proposals
     */
    private function write(string $summary, array $findings, array $proposals): string
    {
        $writer = self::getContainer()->get(ReadinessReportWriter::class);
        self::assertInstanceOf(ReadinessReportWriter::class, $writer);

        return $writer->write('lifecycle', $summary, $findings, $proposals);
    }

    private function proposal(string $key, string $title, ?int $position, ?int $openCardNumber = null): DiscoveryProposal
    {
        return new DiscoveryProposal($this->run, $position, $key, $title, CardType::Docs, 'Body of '.$key.'.', $openCardNumber);
    }
}
