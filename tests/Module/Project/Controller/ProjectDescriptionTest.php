<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class ProjectDescriptionTest extends WebTestCase
{
    public function test_description_survives_create_edit_validation_and_clear(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $owner = new User('Project owner', 'project-description@example.com', 'x');
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($owner, self::getContainer());
        $em->persist($owner);
        $em->flush();
        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects');
        self::assertResponseIsSuccessful();
        $description = "<script>alert(1)</script>\nReview the portal.";
        $client->submitForm('Add project', [
            'create_project_form[name]' => 'Described project',
            'create_project_form[description]' => '  '.$description.'  ',
        ]);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $project = self::getContainer()->get(ProjectRepository::class)->findOneBy(['name' => 'Described project']);
        self::assertInstanceOf(Project::class, $project);
        self::assertSame($description, $project->description);
        $id = $project->id;
        self::assertResponseRedirects('/projects/'.$id);
        $client->followRedirect();
        self::assertSelectorExists('[data-workshop]');
        $client->request(Request::METHOD_GET, '/projects');
        self::assertSame($description, $client->getCrawler()->filter('.lp-project-row__description')->text('', false));
        self::assertSelectorNotExists('.lp-project-row__description script');

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$id.'/edit');
        self::assertSame($description, $crawler->filter('#create_project_form_description')->text('', false));
        $tooLong = str_repeat('a', 501);
        $client->submitForm('Save changes', ['create_project_form[description]' => $tooLong]);
        self::assertResponseStatusCodeSame(422);
        $descriptionField = $client->getCrawler()->filter('#create_project_form_description')->closest('.lp-form-field');
        self::assertInstanceOf(Crawler::class, $descriptionField);
        self::assertStringContainsString('500', $descriptionField->filter('.lp-field-errors')->text());
        self::assertSelectorTextSame('#create_project_form_description', $tooLong);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame($description, $em->find(Project::class, $id)?->description);

        $client->submitForm('Save changes', ['create_project_form[description]' => '0']);
        self::assertResponseRedirects('/projects');
        $client->followRedirect();
        self::assertSelectorTextSame('.lp-project-row__description', '0');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame('0', $em->find(Project::class, $id)?->description);

        $client->request(Request::METHOD_GET, '/projects/'.$id.'/edit');
        $client->submitForm('Save changes', ['create_project_form[description]' => '   ']);
        self::assertResponseRedirects('/projects');
        $client->followRedirect();
        self::assertSelectorNotExists('.lp-project-row__description');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->find(Project::class, $id)?->description);
    }
}
