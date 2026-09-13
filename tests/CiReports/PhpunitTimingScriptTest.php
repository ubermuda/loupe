<?php

declare(strict_types=1);

namespace App\Tests\CiReports;

use PHPUnit\Framework\TestCase;

final class PhpunitTimingScriptTest extends TestCase
{
    // The shape PHPUnit 13 writes: a data provider nests its cases one suite
    // deeper, and every case still carries the real class on `class`.
    private const string JUNIT = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <testsuites>
          <testsuite name="Project Test Suite" tests="4" time="6.000000">
            <testsuite name="App\Tests\FastTest" tests="1" time="0.500000">
              <testcase name="test_quick" class="App\Tests\FastTest" classname="App.Tests.FastTest" time="0.500000"/>
            </testsuite>
            <testsuite name="App\Tests\SlowControllerTest" tests="3" time="5.500000">
              <testcase name="test_one" class="App\Tests\SlowControllerTest" time="3.000000">
                <failure type="PHPUnit\Framework\ExpectationFailedException">it failed</failure>
              </testcase>
              <testsuite name="App\Tests\SlowControllerTest::test_many" tests="2" time="2.500000">
                <testcase name="test_many with data set &quot;a&quot;" class="App\Tests\SlowControllerTest" time="2.000000"/>
                <testcase name="test_many with data set &quot;b&quot;" class="App\Tests\SlowControllerTest" time="0.500000"/>
              </testsuite>
            </testsuite>
          </testsuite>
        </testsuites>
        XML;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/loupe-phpunit-timing-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $path) {
            unlink($path);
        }

        rmdir($this->dir);
    }

    public function test_it_counts_every_test_and_reports_the_mean(): void
    {
        $result = $this->runScript($this->write(self::JUNIT));

        self::assertSame(0, $result['status'], $result['output']);
        self::assertStringContainsString('4 tests in 2 classes, total 6.000s, mean 1.5000s per test', $result['output']);
    }

    public function test_it_groups_the_cases_of_a_data_provider_under_their_class(): void
    {
        $output = $this->runScript($this->write(self::JUNIT))['output'];

        self::assertStringContainsString('4 tests in 2 classes', $output);
        self::assertStringContainsString(
            'App\Tests\SlowControllerTest::test_many with data set "a"',
            $output,
        );
    }

    public function test_it_orders_classes_and_tests_by_time(): void
    {
        $output = $this->runScript($this->write(self::JUNIT))['output'];

        self::assertSame(
            '   5.500s      3   1.8333s  App\Tests\SlowControllerTest',
            $this->rowAfter($output, 'slowest 2 classes'),
        );
        self::assertSame(
            '   3.000s  App\Tests\SlowControllerTest::test_one',
            $this->rowAfter($output, 'slowest 4 tests'),
        );
    }

    public function test_it_reads_a_log_a_failed_run_wrote(): void
    {
        // The failing case above carries a <failure> child, and its time still
        // counts. A red run is when the timing is wanted most.
        $result = $this->runScript($this->write(self::JUNIT));

        self::assertSame(0, $result['status']);
        self::assertStringContainsString('App\Tests\SlowControllerTest::test_one', $result['output']);
    }

    public function test_top_limits_both_lists(): void
    {
        $output = $this->runScript($this->write(self::JUNIT), '--top=1')['output'];

        self::assertStringContainsString('slowest 1 classes', $output);
        self::assertStringNotContainsString('App\Tests\FastTest', $output);
    }

    public function test_it_reports_a_time_over_a_minute_in_minutes(): void
    {
        $xml = str_replace('3.000000', '90.500000', self::JUNIT);

        self::assertStringContainsString('1m30.50s', $this->runScript($this->write($xml))['output']);
    }

    public function test_it_refuses_a_file_that_is_not_well_formed(): void
    {
        $result = $this->runScript($this->write('<testsuites><testcase'));

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('not well-formed XML', $result['output']);
    }

    public function test_it_refuses_a_log_that_holds_no_test(): void
    {
        $result = $this->runScript($this->write('<testsuites/>'));

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('holds no <testcase> element', $result['output']);
    }

    public function test_it_refuses_a_file_that_is_not_there(): void
    {
        self::assertSame(1, $this->runScript($this->dir.'/absent.xml')['status']);
    }

    public function test_it_reports_its_usage_when_given_no_file(): void
    {
        $result = $this->runScript();

        self::assertSame(2, $result['status']);
        self::assertStringContainsString('Usage: php bin/phpunit-timing.php', $result['output']);
    }

    /**
     * The first data row of a table, which is the line after its column heads.
     */
    private function rowAfter(string $output, string $heading): string
    {
        $lines = explode("\n", substr($output, (int) strpos($output, $heading)));

        return $lines[2] ?? '';
    }

    private function write(string $xml): string
    {
        $path = $this->dir.'/junit.xml';
        file_put_contents($path, $xml);

        return $path;
    }

    /**
     * @return array{status: int, output: string}
     */
    private function runScript(string ...$arguments): array
    {
        $command = sprintf(
            '%s %s %s 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/bin/phpunit-timing.php'),
            implode(' ', array_map(escapeshellarg(...), $arguments)),
        );

        exec($command, $lines, $status);

        return ['status' => $status, 'output' => implode("\n", $lines)];
    }
}
