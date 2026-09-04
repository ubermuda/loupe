<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Security;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Series;
use App\Module\Review\Security\McpBoundProjectVoter;
use App\Module\Review\ValueObject\Anchor;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\McpTokenScenario;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

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
        $user = new User(fullName: 'U', email: $email, password: 'hashed');
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

        self::assertTrue($this->authorization->isGranted(McpBoundProjectVoter::DOCUMENT_READ, $document));
        self::assertTrue($this->authorization->isGranted(McpBoundProjectVoter::DOCUMENT_WRITE, $document));
    }

    public function test_denies_when_the_request_carries_no_token_at_all(): void
    {
        $document = $this->documentInNewProject($this->user('voter-anon@example.com'));

        $tokenStorage = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokenStorage);
        $tokenStorage->setToken(null);

        self::assertFalse($this->authorization->isGranted(McpBoundProjectVoter::DOCUMENT_READ, $document));
        self::assertFalse($this->authorization->isGranted(McpBoundProjectVoter::DOCUMENT_WRITE, $document));
    }

    public function test_denies_a_token_that_did_not_authenticate_through_an_api_token(): void
    {
        $owner = $this->user('voter-session@example.com');
        $document = $this->documentInNewProject($owner);

        // A form-login session carries no API token id, so there is no binding
        // to compare against — the owner's own session must not reach the MCP
        // surface's subjects through this voter.
        $tokenStorage = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokenStorage);
        $tokenStorage->setToken(new UsernamePasswordToken($owner, 'main', $owner->getRoles()));

        self::assertFalse($this->authorization->isGranted(McpBoundProjectVoter::DOCUMENT_READ, $document));
        self::assertFalse($this->authorization->isGranted(McpBoundProjectVoter::DOCUMENT_WRITE, $document));
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
        self::assertFalse($this->authorization->isGranted(McpBoundProjectVoter::DOCUMENT_READ, $document));
    }

    public function test_denies_everything_for_a_token_bound_to_no_project(): void
    {
        $owner = $this->user('voter-unbound@example.com');
        $document = $this->documentInNewProject($owner);
        $this->actAsUnboundMcpToken($owner);

        self::assertFalse($this->authorization->isGranted(McpBoundProjectVoter::DOCUMENT_READ, $document));
    }

    public function test_grants_a_comment_of_the_bound_project(): void
    {
        $document = $this->documentInNewProject($this->user('voter-comment@example.com'));
        $comment = $this->commentOn($document);
        $this->actAsMcpTokenBoundTo($document->project);

        self::assertTrue($this->authorization->isGranted(McpBoundProjectVoter::COMMENT_READ, $comment));
    }

    public function test_denies_a_comment_in_another_project_of_the_same_owner(): void
    {
        $owner = $this->user('voter-cmt-cross@example.com');
        $comment = $this->commentOn($this->documentInNewProject($owner));

        $projectB = new Project($owner, 'p-'.uniqid());
        $this->em->persist($projectB);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($projectB);

        self::assertFalse($this->authorization->isGranted(McpBoundProjectVoter::COMMENT_READ, $comment));
    }

    private function seriesInNewProject(User $owner): Series
    {
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);

        $series = new Series($project, 'Blog Series');
        $this->em->persist($series);
        $this->em->flush();

        return $series;
    }

    public function test_grants_a_series_of_the_bound_project(): void
    {
        $series = $this->seriesInNewProject($this->user('voter-series@example.com'));
        $this->actAsMcpTokenBoundTo($series->project);

        self::assertTrue($this->authorization->isGranted(McpBoundProjectVoter::SERIES_WRITE, $series));
    }

    /**
     * The rename tool resolves a series inside the bound project, so this vote
     * is defence in depth rather than the only gate. It has to deny anyway, or
     * a later read-only token policy would pass straight through it.
     */
    public function test_denies_a_series_in_another_project_of_the_same_owner(): void
    {
        $owner = $this->user('voter-series-cross@example.com');
        $series = $this->seriesInNewProject($owner);

        $projectB = new Project($owner, 'p-'.uniqid());
        $this->em->persist($projectB);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($projectB);

        self::assertFalse($this->authorization->isGranted(McpBoundProjectVoter::SERIES_WRITE, $series));
    }

    public function test_a_denied_series_is_recorded_against_the_series_it_voted_on(): void
    {
        $owner = $this->user('voter-audit-series@example.com');
        $series = $this->seriesInNewProject($owner);
        $projectB = new Project($owner, 'p-'.uniqid());
        $this->em->persist($projectB);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($projectB);

        $audit = $this->auditedVote(McpBoundProjectVoter::SERIES_WRITE, $series);

        $record = $audit->record('review.mcp_access_denied');
        self::assertSame(Auditor::CATEGORY_SECURITY, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('series', $record->subject->type);
        self::assertSame((string) $series->id, $record->subject->id);
        self::assertSame([
            'attribute' => McpBoundProjectVoter::SERIES_WRITE,
            'subjectId' => (string) $series->id,
            'subjectProjectId' => (string) $series->project->id,
            'boundProjectId' => (string) $projectB->id,
        ], $record->context);
    }

    public function test_a_denied_document_is_recorded_on_the_security_channel(): void
    {
        $owner = $this->user('voter-audit-doc@example.com');
        $document = $this->documentInNewProject($owner);
        $projectB = new Project($owner, 'p-'.uniqid());
        $this->em->persist($projectB);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($projectB);

        $audit = $this->auditedVote(McpBoundProjectVoter::DOCUMENT_READ, $document);

        $record = $audit->record('review.mcp_access_denied');
        self::assertSame(AuditOutcome::Refused, $record->outcome);
        self::assertSame(Auditor::CATEGORY_SECURITY, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('document', $record->subject->type);
        self::assertSame((string) $document->id, $record->subject->id);
        self::assertSame([
            'attribute' => McpBoundProjectVoter::DOCUMENT_READ,
            'subjectId' => (string) $document->id,
            'subjectProjectId' => (string) $document->project->id,
            'boundProjectId' => (string) $projectB->id,
        ], $record->context);

        self::assertSame(['review.mcp_access_denied'], $audit->securityLogLines());
        self::assertSame([], $audit->domainLogLines());
    }

    public function test_a_denied_comment_is_recorded_against_the_comment_it_voted_on(): void
    {
        $owner = $this->user('voter-audit-cmt@example.com');
        $comment = $this->commentOn($this->documentInNewProject($owner));
        $projectB = new Project($owner, 'p-'.uniqid());
        $this->em->persist($projectB);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($projectB);

        $audit = $this->auditedVote(McpBoundProjectVoter::COMMENT_WRITE, $comment);

        $record = $audit->record('review.mcp_access_denied');
        self::assertNotNull($record->subject);
        self::assertSame('comment', $record->subject->type);
        self::assertSame((string) $comment->id, $record->subject->id);
        self::assertSame([
            'attribute' => McpBoundProjectVoter::COMMENT_WRITE,
            'subjectId' => (string) $comment->id,
            'subjectProjectId' => (string) $comment->version->document->project->id,
            'boundProjectId' => (string) $projectB->id,
        ], $record->context);
    }

    public function test_an_unbound_token_records_a_null_bound_project(): void
    {
        $owner = $this->user('voter-audit-unbound@example.com');
        $document = $this->documentInNewProject($owner);
        $this->actAsUnboundMcpToken($owner);

        $audit = $this->auditedVote(McpBoundProjectVoter::DOCUMENT_READ, $document);

        self::assertNull($audit->record('review.mcp_access_denied')->context['boundProjectId']);
    }

    public function test_a_granted_vote_records_nothing(): void
    {
        $document = $this->documentInNewProject($this->user('voter-audit-grant@example.com'));
        $this->actAsMcpTokenBoundTo($document->project);

        $audit = $this->auditedVote(McpBoundProjectVoter::DOCUMENT_READ, $document, VoterInterface::ACCESS_GRANTED);

        self::assertSame([], $audit->operations());
    }

    public function test_the_voter_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(McpBoundProjectVoter::class);
    }

    /**
     * Votes through a voter built on a recording Auditor. The container's own
     * voter is behind the authorization checker, which offers no seam for one.
     */
    private function auditedVote(string $attribute, Comment|Document|Series $subject, int $expected = VoterInterface::ACCESS_DENIED): RecordingAuditor
    {
        $resolver = self::getContainer()->get(AuthenticatedProjectResolver::class);
        self::assertInstanceOf(AuthenticatedProjectResolver::class, $resolver);
        $tokenStorage = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokenStorage);
        $securityToken = $tokenStorage->getToken();
        self::assertNotNull($securityToken);

        $audit = RecordingAuditor::installedIn(self::getContainer());
        $voter = new McpBoundProjectVoter($resolver, $audit->auditor);

        self::assertSame($expected, $voter->vote($securityToken, $subject, [$attribute]));

        return $audit;
    }
}
