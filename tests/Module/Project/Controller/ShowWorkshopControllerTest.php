<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShowWorkshopControllerTest extends WebTestCase
{
    public function test_owner_sees_project_workshop_with_real_rollup_counts(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'workshop-owner@example.com');
        $project = new Project($owner, 'Workshop project');
        $em->persist($project);
        $em->persist(new Document($owner, $project, 'First document'));
        $em->persist(new Document($owner, $project, 'Second document'));
        $em->flush();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id);

        self::assertResponseIsSuccessful();
        self::assertSame('Your workshop', trim($crawler->filter('[data-workshop] h1')->text()));
        self::assertSame('2', trim($crawler->filter('.lp-workshop-stat__value')->eq(2)->text()));
        self::assertSelectorExists('a[href="/projects/'.$project->id.'/documents"]');
    }

    public function test_non_owner_cannot_open_workshop(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'workshop-first@example.com');
        $other = $this->user($em, 'workshop-other@example.com');
        $project = new Project($owner, 'Private workshop');
        $em->persist($project);
        $em->flush();

        $client->loginUser($other);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id);

        self::assertResponseStatusCodeSame(403);
    }

    /** @param non-empty-string $email */
    private function user(EntityManagerInterface $em, string $email): User
    {
        $user = new User(fullName: 'Workshop user', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $em->persist($user);

        return $user;
    }
}
