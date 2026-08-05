<?php

declare(strict_types=1);

namespace App\Tests\Module\Admin\Controller;

use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

/**
 * The flags bundle stores tags in a PostgreSQL jsonb column and filters them
 * with the `@>` containment operator. The bundle's own suite does not run
 * against this database, so the read path is pinned here.
 */
final class FeatureFlagsAdminListTest extends WebTestCase
{
    public function test_list_renders_a_tagged_flag_from_postgres(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $admin = new User(fullName: 'Admin', email: 'flags-admin@admin-test.example.com');
        $admin->password = $hasher->hashPassword($admin, 'TestPass123!');
        $admin->emailVerifiedAt = new \DateTimeImmutable();
        $admin->roles = ['ROLE_ADMIN'];
        $em->persist($admin);

        $flag = new FeatureFlag(name: 'billing.enabled', type: FeatureFlagType::Bool, value: false);
        $flag->tags = ['billing'];
        $em->persist($flag);
        $em->flush();
        $em->clear();

        $client->loginUser($em->find(User::class, $admin->id) ?? throw new \LogicException('admin gone'));
        $crawler = $client->request(Request::METHOD_GET, '/admin/feature-flags');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('billing.enabled', $crawler->filter('body')->text());
        // The jsonb-stored tag renders in the Tags column.
        $this->assertStringContainsString('billing', $crawler->filter('body')->text());
    }

    public function test_tag_filter_uses_the_jsonb_containment_operator(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $admin = new User(fullName: 'Admin', email: 'flags-filter-admin@admin-test.example.com');
        $admin->password = $hasher->hashPassword($admin, 'TestPass123!');
        $admin->emailVerifiedAt = new \DateTimeImmutable();
        $admin->roles = ['ROLE_ADMIN'];
        $em->persist($admin);

        $tagged = new FeatureFlag(name: 'billing.tagged', type: FeatureFlagType::Bool, value: false);
        $tagged->tags = ['billing'];
        $em->persist($tagged);

        $untagged = new FeatureFlag(name: 'billing.untagged', type: FeatureFlagType::Bool, value: false);
        $em->persist($untagged);
        $em->flush();
        $em->clear();

        $client->loginUser($em->find(User::class, $admin->id) ?? throw new \LogicException('admin gone'));
        $crawler = $client->request(Request::METHOD_GET, '/admin/feature-flags?tag=billing');

        $this->assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        $this->assertStringContainsString('billing.tagged', $body);
        $this->assertStringNotContainsString('billing.untagged', $body);
    }
}
