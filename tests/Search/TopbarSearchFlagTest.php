<?php

declare(strict_types=1);

namespace App\Tests\Search;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Search\Install\SearchInstallFlags;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class TopbarSearchFlagTest extends WebTestCase
{
    #[TestWith([true, 1])]
    #[TestWith([false, 0])]
    public function test_the_flag_decides_whether_the_topbar_offers_search_and_its_shortcut(bool $enabled, int $expected): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = new User(fullName: 'Owner', email: 'topbar-search@example.com', password: 'x');
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($owner, static::getContainer());
        $project = new Project($owner, 'Topbar');
        $em->persist($owner);
        $em->persist($project);
        static::getContainer()->get(FeatureFlagRepository::class)->findAllIndexed()[SearchInstallFlags::FLAG_TOPBAR_ENABLED]->value = $enabled;
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/documents');

        self::assertResponseIsSuccessful();
        self::assertCount($expected, $crawler->filter('.lp-topbar a[data-global-search-target="trigger"]'));
        self::assertCount($expected, $crawler->filter('[data-action*="keydown@window->global-search#shortcut"]'));
    }
}
