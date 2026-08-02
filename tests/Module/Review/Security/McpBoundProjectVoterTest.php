<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Security;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Security\McpBoundProjectVoter;
use App\Module\Review\ValueObject\Anchor;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class McpBoundProjectVoterTest extends KernelTestCase
{
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private AuthorizationCheckerInterface $authorization;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $authorization = self::getContainer()->get('security.authorization_checker');
        self::assertInstanceOf(AuthorizationCheckerInterface::class, $authorization);
        $this->authorization = $authorization;
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'hashed');
        $this->em->persist($user);

        return $user;
    }

    private function documentInNewProject(User $owner): Document
    {
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);

        $document = new Document(owner: $owner, project: $project, title: 'Doc');
        $document->addVersion('# Hello', '<h1>Hello</h1>');
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    private function commentOn(Document $document): Comment
    {
        $comment = new Comment($document->currentVersion(), $document->owner, 'Note', new Anchor('Hello', '# ', '', 2));
        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }

    public function test_grants_a_document_of_the_bound_project(): void
    {
        $document = $this->documentInNewProject($this->user('voter-grant@example.com'));
        $this->actAsMcpTokenBoundTo($document->project);

        self::assertTrue($this->authorization->isGranted(McpBoundProjectVoter::DOCUMENT_ACCESS, $document));
    }

    public function test_denies_a_document_in_another_project_of_the_same_owner(): void
    {
        $owner = $this->user('voter-cross@example.com');
        $document = $this->documentInNewProject($owner);

        $projectB = new Project($owner, 'p-'.uniqid());
        $this->em->persist($projectB);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($projectB);

        // Ownership is not the question the MCP surface asks: the owner is the
        // same person, and DocumentVoter would grant this.
        self::assertFalse($this->authorization->isGranted(McpBoundProjectVoter::DOCUMENT_ACCESS, $document));
    }

    public function test_denies_everything_for_a_token_bound_to_no_project(): void
    {
        $owner = $this->user('voter-unbound@example.com');
        $document = $this->documentInNewProject($owner);
        $this->actAsUnboundMcpToken($owner);

        self::assertFalse($this->authorization->isGranted(McpBoundProjectVoter::DOCUMENT_ACCESS, $document));
    }

    public function test_grants_a_comment_of_the_bound_project(): void
    {
        $document = $this->documentInNewProject($this->user('voter-comment@example.com'));
        $comment = $this->commentOn($document);
        $this->actAsMcpTokenBoundTo($document->project);

        self::assertTrue($this->authorization->isGranted(McpBoundProjectVoter::COMMENT_ACCESS, $comment));
    }

    public function test_denies_a_comment_in_another_project_of_the_same_owner(): void
    {
        $owner = $this->user('voter-cmt-cross@example.com');
        $comment = $this->commentOn($this->documentInNewProject($owner));

        $projectB = new Project($owner, 'p-'.uniqid());
        $this->em->persist($projectB);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($projectB);

        self::assertFalse($this->authorization->isGranted(McpBoundProjectVoter::COMMENT_ACCESS, $comment));
    }
}
