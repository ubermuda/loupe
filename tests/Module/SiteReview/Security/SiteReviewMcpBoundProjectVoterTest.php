<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Security;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Security\McpBoundProjectVoter;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\McpTokenScenario;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Ubermuda\AuditBundle\AuditActorProviderInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final class SiteReviewMcpBoundProjectVoterTest extends KernelTestCase
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

    private function project(User $owner): Project
    {
        $project = new Project($owner, 'sr-'.uniqid());
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    private function commentOn(Project $project): SiteReviewComment
    {
        $comment = new SiteReviewComment($project, 0, 'Fix this', 'https://app/x')->addAnchor('.a', 'A');
        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }

    public function test_grants_the_bound_project_and_its_comments(): void
    {
        $project = $this->project($this->user('sr-voter-grant@example.com'));
        $comment = $this->commentOn($project);
        $this->actAsMcpTokenBoundTo($project);

        self::assertTrue($this->authorization->isGranted(McpBoundProjectVoter::SITE_REVIEW_READ, $project));
        self::assertTrue($this->authorization->isGranted(McpBoundProjectVoter::SITE_REVIEW_WRITE, $project));
        self::assertTrue($this->authorization->isGranted(McpBoundProjectVoter::SITE_REVIEW_READ, $comment));
        self::assertTrue($this->authorization->isGranted(McpBoundProjectVoter::SITE_REVIEW_WRITE, $comment));
    }

    public function test_denies_another_project_of_the_same_owner(): void
    {
        $owner = $this->user('sr-voter-cross@example.com');
        $projectA = $this->project($owner);
        $projectB = $this->project($owner);
        $commentInB = $this->commentOn($projectB);

        $this->actAsMcpTokenBoundTo($projectA);

        // Ownership is not the question the MCP surface asks: the owner is the
        // same person, and SiteReviewCommentVoter would grant this.
        self::assertFalse($this->authorization->isGranted(McpBoundProjectVoter::SITE_REVIEW_READ, $projectB));
        self::assertFalse($this->authorization->isGranted(McpBoundProjectVoter::SITE_REVIEW_WRITE, $projectB));
        self::assertFalse($this->authorization->isGranted(McpBoundProjectVoter::SITE_REVIEW_READ, $commentInB));
        self::assertFalse($this->authorization->isGranted(McpBoundProjectVoter::SITE_REVIEW_WRITE, $commentInB));
    }

    public function test_denies_when_the_request_carries_no_token_at_all(): void
    {
        $project = $this->project($this->user('sr-voter-anon@example.com'));
        $comment = $this->commentOn($project);

        $tokenStorage = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokenStorage);
        $tokenStorage->setToken(null);

        self::assertFalse($this->authorization->isGranted(McpBoundProjectVoter::SITE_REVIEW_READ, $project));
        self::assertFalse($this->authorization->isGranted(McpBoundProjectVoter::SITE_REVIEW_WRITE, $comment));
    }

    public function test_denies_a_token_that_did_not_authenticate_through_an_api_token(): void
    {
        $owner = $this->user('sr-voter-session@example.com');
        $project = $this->project($owner);
        $comment = $this->commentOn($project);

        // A form-login session carries no API token id, so there is no binding
        // to compare against.
        $tokenStorage = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokenStorage);
        $tokenStorage->setToken(new UsernamePasswordToken($owner, 'main', $owner->getRoles()));

        self::assertFalse($this->authorization->isGranted(McpBoundProjectVoter::SITE_REVIEW_READ, $project));
        self::assertFalse($this->authorization->isGranted(McpBoundProjectVoter::SITE_REVIEW_WRITE, $comment));
    }

    public function test_denies_everything_for_a_token_bound_to_no_project(): void
    {
        $owner = $this->user('sr-voter-unbound@example.com');
        $project = $this->project($owner);
        $comment = $this->commentOn($project);
        $this->actAsUnboundMcpToken($owner);

        self::assertFalse($this->authorization->isGranted(McpBoundProjectVoter::SITE_REVIEW_READ, $project));
        self::assertFalse($this->authorization->isGranted(McpBoundProjectVoter::SITE_REVIEW_WRITE, $comment));
    }

    public function test_a_denied_project_is_recorded_on_the_security_channel(): void
    {
        $owner = $this->user('sr-voter-audit-project@example.com');
        $projectA = $this->project($owner);
        $projectB = $this->project($owner);
        $this->actAsMcpTokenBoundTo($projectA);

        $audit = $this->auditedVote(McpBoundProjectVoter::SITE_REVIEW_READ, $projectB);

        $record = $audit->record('site_review.mcp_access_denied');
        self::assertSame(AuditOutcome::Refused, $record->outcome);
        self::assertSame(Auditor::CATEGORY_SECURITY, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('project', $record->subject->type);
        self::assertSame((string) $projectB->id, $record->subject->id);
        self::assertSame([
            'attribute' => McpBoundProjectVoter::SITE_REVIEW_READ,
            'subjectId' => (string) $projectB->id,
            'subjectProjectId' => (string) $projectB->id,
            'boundProjectId' => (string) $projectA->id,
        ], $record->context);

        self::assertSame(['site_review.mcp_access_denied'], $audit->securityLogLines());
        self::assertSame([], $audit->domainLogLines());
    }

    public function test_a_denied_comment_is_recorded_against_the_comment_it_voted_on(): void
    {
        $owner = $this->user('sr-voter-audit-comment@example.com');
        $projectA = $this->project($owner);
        $projectB = $this->project($owner);
        $commentInB = $this->commentOn($projectB);
        $this->actAsMcpTokenBoundTo($projectA);

        $audit = $this->auditedVote(McpBoundProjectVoter::SITE_REVIEW_WRITE, $commentInB);

        $record = $audit->record('site_review.mcp_access_denied');
        self::assertNotNull($record->subject);
        self::assertSame('site_review_comment', $record->subject->type);
        self::assertSame((string) $commentInB->id, $record->subject->id);
        self::assertSame([
            'attribute' => McpBoundProjectVoter::SITE_REVIEW_WRITE,
            'subjectId' => (string) $commentInB->id,
            'subjectProjectId' => (string) $projectB->id,
            'boundProjectId' => (string) $projectA->id,
        ], $record->context);
    }

    public function test_an_unbound_token_records_a_null_bound_project(): void
    {
        $owner = $this->user('sr-voter-audit-unbound@example.com');
        $project = $this->project($owner);
        $this->actAsUnboundMcpToken($owner);

        $audit = $this->auditedVote(McpBoundProjectVoter::SITE_REVIEW_READ, $project);

        self::assertNull($audit->record('site_review.mcp_access_denied')->context['boundProjectId']);
    }

    public function test_a_granted_vote_records_nothing(): void
    {
        $project = $this->project($this->user('sr-voter-audit-grant@example.com'));
        $this->actAsMcpTokenBoundTo($project);

        $audit = $this->auditedVote(McpBoundProjectVoter::SITE_REVIEW_READ, $project, VoterInterface::ACCESS_GRANTED);

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
    private function auditedVote(string $attribute, Project|SiteReviewComment $subject, int $expected = VoterInterface::ACCESS_DENIED): RecordingAuditor
    {
        $resolver = self::getContainer()->get(AuthenticatedProjectResolver::class);
        self::assertInstanceOf(AuthenticatedProjectResolver::class, $resolver);
        $actors = self::getContainer()->get(AuditActorProviderInterface::class);
        self::assertInstanceOf(AuditActorProviderInterface::class, $actors);
        $tokenStorage = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokenStorage);
        $securityToken = $tokenStorage->getToken();
        self::assertNotNull($securityToken);

        $audit = new RecordingAuditor($actors);
        $voter = new McpBoundProjectVoter($resolver, $audit->auditor);

        self::assertSame($expected, $voter->vote($securityToken, $subject, [$attribute]));

        return $audit;
    }
}
