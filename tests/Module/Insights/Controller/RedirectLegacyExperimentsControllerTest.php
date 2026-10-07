<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Tests\Module\Bridge\BridgeScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class RedirectLegacyExperimentsControllerTest extends WebTestCase
{
    use BridgeScenario;

    /** @return iterable<string, array{string, string}> */
    public static function paths(): iterable
    {
        yield 'list' => ['/worker-runs/experiments', '/analytics/experiments'];
        yield 'list with a query' => ['/worker-runs/experiments?page=2', '/analytics/experiments?page=2'];
        yield 'comparison' => ['/worker-runs/experiments/model-test', '/analytics/experiments/model-test'];
        yield 'an experiment named zero' => ['/worker-runs/experiments/0', '/analytics/experiments/0'];
        yield 'cards' => ['/worker-runs/experiments/model-test/cards', '/analytics/experiments/model-test/cards'];
        yield 'cards with the whole query' => [
            '/worker-runs/experiments/model-test/cards?variant=b&left-out=1&page=3',
            '/analytics/experiments/model-test/cards?left-out=1&page=3&variant=b',
        ];
    }

    #[DataProvider('paths')]
    public function test_an_old_experiments_address_moves_permanently_to_the_analytics_section(string $old, string $new): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'legacy-experiments@example.com');
        $base = '/projects/'.$this->project($em, $owner, 'Legacy project')->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $base.$old);

        self::assertResponseStatusCodeSame(301);
        self::assertResponseHeaderSame('Location', $base.$new);
    }

    #[DataProvider('paths')]
    public function test_another_users_project_is_refused(string $old, string $new): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'legacy-experiments-theirs@example.com');
        $stranger = $this->user($em, 'legacy-experiments-stranger@example.com');
        $base = '/projects/'.$this->project($em, $owner, 'Their project')->id;
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, $base.$old);

        self::assertResponseStatusCodeSame(403);
    }
}
