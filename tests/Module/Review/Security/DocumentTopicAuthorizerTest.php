<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Security;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Security\DocumentTopicAuthorizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

final class DocumentTopicAuthorizerTest extends KernelTestCase
{
    private User $owner;
    private Document $document;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->owner = $this->user('document-topic-owner@example.com');
        $this->document = $this->document($this->owner);
    }

    public function test_a_viewer_of_the_document_may_subscribe(): void
    {
        $this->signIn($this->owner);

        self::assertTrue($this->authorizer()->mayCurrentUserSubscribe($this->documentTopic($this->document)));
    }

    public function test_a_stranger_is_refused(): void
    {
        $stranger = $this->user('document-topic-stranger@example.com');
        $this->signIn($stranger);

        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->documentTopic($this->document)));
        // Guard: the stranger passes on a document of their own, so the refusal is about the document.
        self::assertTrue($this->authorizer()->mayCurrentUserSubscribe($this->documentTopic($this->document($stranger))));
    }

    public function test_nobody_signed_in_is_refused(): void
    {
        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->documentTopic($this->document)));
    }

    public function test_a_document_that_does_not_exist_is_refused(): void
    {
        $this->signIn($this->owner);

        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->topics()->forDocument($this->projectId($this->document), Uuid::v7())));
    }

    public function test_a_document_named_under_another_project_is_refused(): void
    {
        $this->signIn($this->owner);
        $other = $this->document($this->owner);

        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->topics()->forDocument(
            $this->projectId($other),
            $this->document->id ?? throw new \LogicException('The document has no id.'),
        )));
    }

    public function test_it_claims_no_other_topic(): void
    {
        $this->signIn($this->owner);
        $projectId = $this->projectId($this->document);

        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($this->topics()->forBoard($projectId)));
        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($this->topics()->forProject($projectId)));
        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($this->topics()->forActivity($projectId)));
        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($this->documentTopic($this->document).'/extra'));
    }

    private function signIn(User $user): void
    {
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function documentTopic(Document $document): string
    {
        return $this->topics()->forDocument(
            $this->projectId($document),
            $document->id ?? throw new \LogicException('The document has no id.'),
        );
    }

    private function projectId(Document $document): Uuid
    {
        return $document->project->id ?? throw new \LogicException('The project has no id.');
    }

    private function topics(): ProjectTopicBuilder
    {
        $topics = self::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);

        return $topics;
    }

    private function authorizer(): DocumentTopicAuthorizer
    {
        $authorizer = self::getContainer()->get(DocumentTopicAuthorizer::class);
        self::assertInstanceOf(DocumentTopicAuthorizer::class, $authorizer);

        return $authorizer;
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(fullName: 'U', email: $email, password: 'x');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function document(User $owner): Document
    {
        $project = new Project($owner, 'document-topic-'.bin2hex(random_bytes(4)));
        $this->em()->persist($project);
        $this->em()->flush();

        $create = self::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $create);

        return $create(new CreateDocumentCommand($project, 'Topic', '# Topic'));
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
