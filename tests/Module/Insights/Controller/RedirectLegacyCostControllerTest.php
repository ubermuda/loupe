<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Tests\Module\Bridge\BridgeScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class RedirectLegacyCostControllerTest extends WebTestCase
{
    use BridgeScenario;

    private const string METRICS = '/analytics/metrics?unit=card&metric=cost&statistic=mean';

    /** @return iterable<string, array{string, string}> */
    public static function queries(): iterable
    {
        yield 'no query' => ['', self::METRICS];
        yield 'thirty days' => ['?range=thirty-days', self::METRICS.'&range=thirty-days&bucket=day'];
        yield 'ninety days' => ['?range=ninety-days', self::METRICS.'&range=ninety-days'];
        yield 'all time' => ['?range=all-time', self::METRICS.'&range=all&bucket=month'];
        yield 'all' => ['?range=all', self::METRICS.'&range=all&bucket=month'];
        yield 'unknown range' => ['?range=forever', self::METRICS];
        yield 'each group' => ['?group=week', self::METRICS.'&bucket=week'];
        yield 'unknown group' => ['?group=year', self::METRICS];
        yield 'the dropped controls' => [
            '?split=rule&rule=plan&model=opus&group=month&range=thirty-days',
            self::METRICS.'&range=thirty-days&bucket=month',
        ];
    }

    #[DataProvider('queries')]
    public function test_the_old_cost_tab_moves_permanently_to_the_mean_cost_per_card(string $query, string $target): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'legacy-cost@example.com');
        $base = '/projects/'.$this->project($em, $owner, 'Legacy project')->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $base.'/worker-runs/cost'.$query);

        self::assertResponseStatusCodeSame(301);
        self::assertResponseHeaderSame('Location', $base.$target);
    }

    public function test_another_users_project_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'legacy-cost-theirs@example.com');
        $stranger = $this->user($em, 'legacy-cost-stranger@example.com');
        $base = '/projects/'.$this->project($em, $owner, 'Their project')->id;
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, $base.'/worker-runs/cost');

        self::assertResponseStatusCodeSame(403);
    }
}
