<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Module\Bridge\Messenger\RecomputeBucketTimes;
use App\Module\Insights\Entity\InsightsBucketRule;
use App\Module\Insights\Repository\InsightsBucketRuleRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class BucketRuleControllersTest extends WebTestCase
{
    use InsightsScenario;

    private const array HEADERS = ['HTTP_REFERER' => 'http://localhost/'];

    public function test_the_page_explains_the_empty_state_and_the_fallback_bucket(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('rules-page-empty');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/time-buckets');

        self::assertResponseIsSuccessful();
        self::assertSame('Time buckets', trim($crawler->filter('nav.lp-analytics-tabs [aria-current="page"]')->text()));
        $empty = $crawler->filter('[data-bucket-rules-empty]');
        self::assertCount(1, $empty);
        self::assertStringContainsString('counts in the bucket "other"', $empty->text());
        self::assertCount(1, $crawler->filter('form[action="/projects/'.$projectId.'/analytics/time-buckets"]'));
    }

    public function test_the_page_lists_the_rules_in_order_with_edge_moves_disabled(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $project = $this->scenarioProject('rules-page-list');
        $em->persist(new InsightsBucketRule($project, 'Bash:just *', 'just', 7));
        $em->persist(new InsightsBucketRule($project, 'Bash:git *', 'git', 3));
        $em->persist(new InsightsBucketRule($this->scenarioProject('rules-page-list-other'), 'Bash:npm *', 'npm', 0));
        $em->flush();
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/time-buckets');

        self::assertResponseIsSuccessful();
        self::assertSame(['Bash:git *', 'Bash:just *'], $crawler->filter('[data-bucket-rule-pattern]')->each(static fn ($node): string => $node->text()));
        self::assertSame(['git', 'just'], $crawler->filter('[data-bucket-rule-bucket]')->each(static fn ($node): string => $node->text()));
        self::assertCount(0, $crawler->filter('[data-bucket-rules-empty]'));
        $rows = $crawler->filter('[data-bucket-rule]');
        self::assertCount(1, $rows->eq(0)->filter('[data-bucket-rule-move="up"][disabled]'));
        self::assertCount(0, $rows->eq(0)->filter('[data-bucket-rule-move="down"][disabled]'));
        self::assertCount(1, $rows->eq(1)->filter('[data-bucket-rule-move="down"][disabled]'));
    }

    public function test_the_form_adds_a_rule_and_asks_for_a_recompute(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $project = $this->scenarioProject('rules-create');
        $projectId = (string) $project->id;
        $this->em()->clear();
        $this->transport()->reset();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/time-buckets');
        $client->submitForm('Add the rule', [
            'bucket_rule_form[pattern]' => 'Bash:git *',
            'bucket_rule_form[bucket]' => 'git',
        ]);

        self::assertResponseRedirects('/projects/'.$projectId.'/analytics/time-buckets');
        self::assertEquals([new RecomputeBucketTimes($projectId)], array_map(static fn ($envelope): object => $envelope->getMessage(), $this->transport()->getSent()));
        $crawler = $client->followRedirect();
        self::assertStringContainsString('The rule is added.', $crawler->filter('body')->text());
        self::assertSame(['Bash:git *'], $crawler->filter('[data-bucket-rule-pattern]')->each(static fn ($node): string => $node->text()));
        self::assertSame(['git'], $this->storedBuckets($projectId));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function refusals(): iterable
    {
        yield 'a blank pattern' => ['', 'git', 'pattern'];
        yield 'a bucket with a space' => ['Bash:git *', 'my bucket', 'bucket'];
    }

    #[DataProvider('refusals')]
    public function test_an_invalid_rule_is_shown_on_its_field_and_nothing_is_stored(string $pattern, string $bucket, string $field): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('rules-create-invalid-'.uniqid());
        $projectId = (string) $project->id;
        $this->em()->clear();
        $this->transport()->reset();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/analytics/time-buckets', [
            'bucket_rule_form' => ['pattern' => $pattern, 'bucket' => $bucket, '_token' => 'csrf-token'],
        ], [], self::HEADERS);

        self::assertResponseStatusCodeSame(422);
        self::assertNotSame('', trim($crawler->filter('[data-bucket-rule-form] [data-field-errors="'.$field.'"]')->text()));
        self::assertSame([], $this->storedBuckets($projectId));
        self::assertCount(0, $this->transport()->getSent());
    }

    public function test_a_project_at_the_limit_shows_the_refusal_on_the_pattern(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $project = $this->scenarioProject('rules-create-limit');
        for ($position = 0; $position < InsightsBucketRule::MAX_PER_PROJECT; ++$position) {
            $em->persist(new InsightsBucketRule($project, 'Bash:tool'.$position, 'tool', $position));
        }
        $em->flush();
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/analytics/time-buckets', [
            'bucket_rule_form' => ['pattern' => 'Bash:git *', 'bucket' => 'git', '_token' => 'csrf-token'],
        ], [], self::HEADERS);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('A project holds at most 50 rules.', $crawler->filter('[data-field-errors="pattern"]')->text());
        self::assertCount(InsightsBucketRule::MAX_PER_PROJECT, $this->storedBuckets($projectId));
    }

    public function test_the_delete_button_removes_the_rule(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $project = $this->scenarioProject('rules-delete');
        $this->rule($project, 'a*', 'a', 0);
        $doomed = $this->rule($project, 'b*', 'b', 1);
        $projectId = (string) $project->id;
        $em->clear();
        $this->transport()->reset();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/analytics/time-buckets/'.$doomed->id.'/delete', ['_csrf_token' => 'csrf-token'], [], self::HEADERS);

        self::assertResponseRedirects('/projects/'.$projectId.'/analytics/time-buckets');
        self::assertSame(['a'], $this->storedBuckets($projectId));
        self::assertCount(1, $this->transport()->getSent());
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function moves(): iterable
    {
        yield 'up' => ['up', ['b', 'a']];
        yield 'down' => ['down', ['a', 'b']];
    }

    /** @param list<string> $expected */
    #[DataProvider('moves')]
    public function test_the_move_buttons_swap_the_rule_with_its_neighbour(string $direction, array $expected): void
    {
        $client = static::createClient();
        $em = $this->em();
        $project = $this->scenarioProject('rules-move-'.$direction);
        $this->rule($project, 'a*', 'a', 0);
        $second = $this->rule($project, 'b*', 'b', 1);
        $projectId = (string) $project->id;
        $em->clear();
        $this->transport()->reset();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/analytics/time-buckets/'.$second->id.'/move/'.$direction, ['_csrf_token' => 'csrf-token'], [], self::HEADERS);

        self::assertResponseRedirects('/projects/'.$projectId.'/analytics/time-buckets');
        self::assertSame($expected, $this->storedBuckets($projectId));
    }

    public function test_an_unknown_direction_is_not_found(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('rules-move-unknown');
        $rule = $this->rule($project, 'a*', 'a', 0);
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/analytics/time-buckets/'.$rule->id.'/move/sideways', ['_csrf_token' => 'csrf-token'], [], self::HEADERS);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_rule_of_another_project_is_not_found(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('rules-scope');
        $rule = $this->rule($project, 'a*', 'a', 0);
        $other = $this->project($this->em(), $project->owner, 'Other rules-scope');
        $otherId = (string) $other->id;
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, '/projects/'.$otherId.'/analytics/time-buckets/'.$rule->id.'/delete', ['_csrf_token' => 'csrf-token'], [], self::HEADERS);
        self::assertResponseStatusCodeSame(404);
        $client->request(Request::METHOD_POST, '/projects/'.$otherId.'/analytics/time-buckets/'.$rule->id.'/move/up', ['_csrf_token' => 'csrf-token'], [], self::HEADERS);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['a'], $this->storedBuckets($projectId));
    }

    /** @return iterable<string, array{string, string}> */
    public static function writes(): iterable
    {
        yield 'create' => ['POST', ''];
        yield 'delete' => ['POST', '/{rule}/delete'];
        yield 'move' => ['POST', '/{rule}/move/up'];
    }

    #[DataProvider('writes')]
    public function test_another_users_project_is_refused_every_write(string $method, string $suffix): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('rules-theirs-'.uniqid());
        $rule = $this->rule($project, 'a*', 'a', 0);
        $stranger = $this->user($this->em(), 'rules-stranger-'.uniqid().'@example.com');
        $projectId = (string) $project->id;
        $path = '/projects/'.$projectId.'/analytics/time-buckets'.str_replace('{rule}', (string) $rule->id, $suffix);
        $this->em()->clear();

        $client->loginUser($stranger);
        $client->request($method, $path, ['_csrf_token' => 'csrf-token', 'bucket_rule_form' => ['pattern' => 'x', 'bucket' => 'x', '_token' => 'csrf-token']], [], self::HEADERS);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['a'], $this->storedBuckets($projectId));
    }

    public function test_another_users_project_is_refused_the_page(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('rules-page-theirs');
        $stranger = $this->user($this->em(), 'rules-page-stranger-'.uniqid().'@example.com');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/time-buckets');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_a_signed_out_visitor_is_sent_to_the_login(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('rules-anonymous');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/time-buckets');

        self::assertResponseRedirects();
    }

    private function rule(Project $project, string $pattern, string $bucket, int $position): InsightsBucketRule
    {
        $rule = new InsightsBucketRule($project, $pattern, $bucket, $position);
        $this->em()->persist($rule);
        $this->em()->flush();

        return $rule;
    }

    /** @return list<string> the buckets in order of position */
    private function storedBuckets(string $projectId): array
    {
        $this->em()->clear();
        $project = $this->em()->find(Project::class, $projectId) ?? throw new \LogicException('The project exists.');
        $repository = static::getContainer()->get(InsightsBucketRuleRepository::class);
        self::assertInstanceOf(InsightsBucketRuleRepository::class, $repository);

        return array_map(static fn (InsightsBucketRule $rule): string => $rule->bucket, $repository->findOrdered($project));
    }

    private function transport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
