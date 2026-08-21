<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class EditProjectControllerTest extends WebTestCase
{
    public function test_owner_edits_the_name_and_domain(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'edit-a@example.com');
        $project = new Project($owner, 'before-name', 'before.example');
        $em->persist($project);
        $em->flush();
        $projectId = $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/edit');
        self::assertResponseIsSuccessful();
        // The form is pre-filled with the current values.
        self::assertSame('before-name', $crawler->filter('#create_project_form_name')->attr('value'));

        $client->submitForm('Save changes', [
            'create_project_form[name]' => 'after-name',
            'create_project_form[domain]' => 'after.example',
        ]);

        self::assertResponseRedirects('/projects');
        $em->clear();
        $fresh = $em->find(Project::class, $projectId);
        self::assertInstanceOf(Project::class, $fresh);
        self::assertSame('after-name', $fresh->name);
        self::assertSame('after.example', $fresh->domain);
    }

    public function test_a_blank_domain_clears_it(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'edit-clear@example.com');
        $project = new Project($owner, 'clear-domain', 'has.example');
        $em->persist($project);
        $em->flush();
        $projectId = $project->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/edit');
        $client->submitForm('Save changes', [
            'create_project_form[name]' => 'clear-domain',
            'create_project_form[domain]' => '   ',
        ]);

        self::assertResponseRedirects('/projects');
        $em->clear();
        $fresh = $em->find(Project::class, $projectId);
        self::assertInstanceOf(Project::class, $fresh);
        self::assertNull($fresh->domain);
    }

    public function test_renaming_to_another_projects_name_is_rejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'edit-b@example.com');
        $alpha = new Project($owner, 'alpha');
        $beta = new Project($owner, 'beta');
        $em->persist($alpha);
        $em->persist($beta);
        $em->flush();
        $betaId = $beta->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$betaId.'/edit');
        $client->submitForm('Save changes', [
            'create_project_form[name]' => 'alpha',
            'create_project_form[domain]' => '',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.lp-field-errors', 'already have a project with this name');
        $em->clear();
        $fresh = $em->find(Project::class, $betaId);
        self::assertInstanceOf(Project::class, $fresh);
        self::assertSame('beta', $fresh->name, 'the name must be unchanged after a rejected rename');
    }

    public function test_keeping_the_same_name_is_allowed(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'edit-same@example.com');
        $project = new Project($owner, 'steady', 'old.example');
        $em->persist($project);
        $em->flush();
        $projectId = $project->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/edit');
        $client->submitForm('Save changes', [
            'create_project_form[name]' => 'steady',
            'create_project_form[domain]' => 'new.example',
        ]);

        self::assertResponseRedirects('/projects');
        $em->clear();
        $fresh = $em->find(Project::class, $projectId);
        self::assertInstanceOf(Project::class, $fresh);
        self::assertSame('new.example', $fresh->domain);
    }

    public function test_non_owner_cannot_edit(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'edit-owner@example.com');
        $other = $this->user($em, 'edit-other@example.com');
        $project = new Project($owner, 'not-yours', 'x.example');
        $em->persist($project);
        $em->flush();
        $projectId = $project->id;
        $em->clear();

        $client->loginUser($other);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/edit');

        self::assertResponseStatusCodeSame(403);
    }

    /** @param non-empty-string $email */
    private function user(EntityManagerInterface $em, string $email): User
    {
        $user = new User(fullName: 'U', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $em->persist($user);

        return $user;
    }
}
