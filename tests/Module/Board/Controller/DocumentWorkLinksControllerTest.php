<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Install\BoardInstallFlags;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class DocumentWorkLinksControllerTest extends WebTestCase
{
    use BoardScenario;

    public function test_creation_and_revision_edit_the_complete_link_set(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'document-links@example.test');
        $project = $this->project($em, $owner);
        $first = $this->card($em, $project, 'First card');
        $second = $this->card($em, $project, 'Second card');
        $projectId = (string) $project->id;
        $firstId = (string) $first->id;
        $secondId = (string) $second->id;
        $em->clear();
        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents');
        $form = $crawler->selectButton('Create document')->form([
            'create_document_form[title]' => 'Linked draft',
            'create_document_form[markdown]' => '# Linked draft',
        ]);
        $values = $form->getPhpValues();
        $values['create_document_form']['workLinkIds'] = [$firstId, $secondId];
        $client->request(Request::METHOD_POST, $form->getUri(), $values);
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        $document = static::getContainer()->get(DocumentRepository::class)->findOneBy(['title' => 'Linked draft']);
        self::assertInstanceOf(Document::class, $document);
        $documentId = (string) $document->id;
        $links = static::getContainer()->get(CardDocumentRepository::class)->findForDocument($document);
        self::assertCount(2, $links);
        $firstLink = array_first(array_filter($links, static fn (CardDocument $link): bool => (string) $link->card->id === $firstId));
        self::assertInstanceOf(CardDocument::class, $firstLink);
        $firstLinkId = (string) $firstLink->id;
        self::assertCount(2, $crawler->filter('input[name="revise_document_form[workLinkIds][]"]:checked'));

        $form = $crawler->selectButton('Save new version')->form([
            'revise_document_form[description]' => 'Keep the first link.',
        ]);
        $values = $form->getPhpValues();
        $values['revise_document_form']['workLinkIds'] = [$firstId];
        $client->request(Request::METHOD_POST, $form->getUri(), $values);
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->find(Document::class, $documentId);
        self::assertInstanceOf(Document::class, $fresh);
        $links = static::getContainer()->get(CardDocumentRepository::class)->findForDocument($fresh);
        self::assertCount(1, $links);
        self::assertSame($firstLinkId, (string) $links[0]->id);
        self::assertSame(2, $fresh->currentVersion()->versionNumber);

        $form = $crawler->selectButton('Save new version')->form([
            'revise_document_form[description]' => 'A stale attempt to remove links.',
            'revise_document_form[versionNumber]' => 1,
        ]);
        $values = $form->getPhpValues();
        $values['revise_document_form']['workLinkIds'] = [];
        $client->request(Request::METHOD_POST, $form->getUri(), $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, static::getContainer()->get(CardDocumentRepository::class)->count(['document' => $documentId]));
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$documentId.'/review');

        $form = $crawler->selectButton('Save new version')->form([
            'revise_document_form[description]' => 'Remove the card link.',
        ]);
        $values = $form->getPhpValues();
        $values['revise_document_form']['workLinkIds'] = [];
        $client->request(Request::METHOD_POST, $form->getUri(), $values);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSame(0, static::getContainer()->get(CardDocumentRepository::class)->count(['document' => $documentId]));
    }

    public function test_picker_keeps_a_linked_finished_card_but_hides_other_finished_cards(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'document-finished-links@example.test');
        $project = $this->project($em, $owner);
        $linked = $this->card($em, $project, 'Linked finished card', 'done');
        $unlinked = $this->card($em, $project, 'Unlinked finished card', 'done');
        $document = new Document($owner, $project, 'Existing document');
        $document->addVersion('# Existing', '<h1>Existing</h1>');
        $em->persist($document);
        $em->persist(new CardDocument($linked, $document));
        $em->flush();
        $linkedId = (string) $linked->id;
        $unlinkedId = (string) $unlinked->id;
        $url = '/projects/'.$project->id.'/documents/'.$document->id.'/review';
        $em->clear();
        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $url);
        self::assertSelectorExists('input[name="revise_document_form[workLinkIds][]"][value="'.$linkedId.'"]:checked');
        self::assertSelectorNotExists('input[name="revise_document_form[workLinkIds][]"][value="'.$unlinkedId.'"]');
        $client->submit($crawler->selectButton('Save new version')->form(['revise_document_form[description]' => 'Keep the finished link.']));
        self::assertResponseRedirects($url);
        $client->followRedirect();
        self::assertSame(1, static::getContainer()->get(CardDocumentRepository::class)->count(['card' => $linkedId]));

        static::getContainer()->get(FeatureFlagRepository::class)->findAllIndexed()[BoardInstallFlags::FLAG_BOARD_ENABLED]->value = false;
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $crawler = $client->request(Request::METHOD_GET, $url);
        self::assertSelectorNotExists('input[name="revise_document_form[workLinkIds][]"]');
        $client->submit($crawler->selectButton('Save new version')->form(['revise_document_form[description]' => 'Revise with the board disabled.']));
        self::assertResponseRedirects($url);
        $client->followRedirect();
        self::assertSame(1, static::getContainer()->get(CardDocumentRepository::class)->count(['card' => $linkedId]));
    }

    public function test_creation_handler_refuses_foreign_card_links_without_partial_writes(): void
    {
        static::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'document-foreign-links@example.test');
        $project = $this->project($em, $owner);
        $otherProject = $this->project($em, $owner, 'other-project');
        $ownCard = $this->card($em, $project, 'Own card');
        $foreignCard = $this->card($em, $otherProject, 'Foreign card');
        try {
            (static::getContainer()->get(CreateDocumentHandler::class))(new CreateDocumentCommand(
                $project, 'Rejected document', '# Rejected',
                workLinkIds: [(string) $ownCard->id, (string) $foreignCard->id],
            ));
            self::fail('A foreign card must be refused.');
        } catch (DomainErrors $errors) {
            self::assertSame(['workLinkIds' => 'review.work_links.error.unknown'], $errors->errors);
        }
        self::assertTrue($em->isOpen());
        $em->flush();
        self::assertSame(0, static::getContainer()->get(DocumentRepository::class)->count(['project' => $project->id]));
        self::assertSame(0, static::getContainer()->get(CardDocumentRepository::class)->count(['card' => $ownCard->id]));
    }
}
