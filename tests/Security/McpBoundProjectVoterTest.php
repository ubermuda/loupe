<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Series;
use App\Module\Review\ValueObject\Anchor;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Security\McpBoundProjectVoter;
use App\Security\ProjectScopedSubject;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\McpTokenScenario;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Ubermuda\AuditBundle\AuditActorProviderInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

/**
 * The shared MCP scoping policy, and the card subjects the board module put on
 * the MCP surface. Each module keeps its own test for its own subjects.
 */
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

    private function project(User $owner): Project
    {
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    private function cardIn(Project $project): Card
    {
        $card = new Card(project: $project, title: 'Ship it', body: 'Body', number: 1);
        $this->em->persist($card);
        $this->em->flush();

        return $card;
    }

    public function test_grants_a_card_of_the_bound_project(): void
    {
        $card = $this->cardIn($this->project($this->user('card-voter-grant@example.com')));
        $this->actAsMcpTokenBoundTo($card->project);

        self::assertTrue($this->authorization->isGranted(McpBoundProjectVoter::CARD_READ, $card));
        self::assertTrue($this->authorization->isGranted(McpBoundProjectVoter::CARD_WRITE, $card));
    }

    public function test_denies_a_card_in_another_project_of_the_same_owner(): void
    {
        $owner = $this->user('card-voter-cross@example.com');
        $card = $this->cardIn($this->project($owner));
        $this->actAsMcpTokenBoundTo($this->project($owner));

        // Ownership is not the question the MCP surface asks: the owner is the
        // same person, and CardVoter would grant this.
        self::assertFalse($this->authorization->isGranted(McpBoundProjectVoter::CARD_READ, $card));
        self::assertFalse($this->authorization->isGranted(McpBoundProjectVoter::CARD_WRITE, $card));
    }

    public function test_denies_a_card_for_a_token_bound_to_no_project(): void
    {
        $owner = $this->user('card-voter-unbound@example.com');
        $card = $this->cardIn($this->project($owner));
        $this->actAsUnboundMcpToken($owner);

        self::assertFalse($this->authorization->isGranted(McpBoundProjectVoter::CARD_READ, $card));
        self::assertFalse($this->authorization->isGranted(McpBoundProjectVoter::CARD_WRITE, $card));
    }

    public function test_denies_a_card_when_the_request_carries_no_token_at_all(): void
    {
        $card = $this->cardIn($this->project($this->user('card-voter-anon@example.com')));

        $tokenStorage = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokenStorage);
        $tokenStorage->setToken(null);

        self::assertFalse($this->authorization->isGranted(McpBoundProjectVoter::CARD_READ, $card));
        self::assertFalse($this->authorization->isGranted(McpBoundProjectVoter::CARD_WRITE, $card));
    }

    /**
     * Every subject sits in the bound project, so a widened pairing grants and
     * the assertion sees it. Asserting the matching pairs alone cannot: they
     * grant either way.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function pairings(): iterable
    {
        $subjects = ['document', 'comment', 'series', 'card', 'project', 'site_review_comment'];
        $accepts = [
            McpBoundProjectVoter::DOCUMENT_READ => ['document'],
            McpBoundProjectVoter::DOCUMENT_WRITE => ['document'],
            McpBoundProjectVoter::COMMENT_READ => ['comment'],
            McpBoundProjectVoter::COMMENT_WRITE => ['comment'],
            McpBoundProjectVoter::SERIES_WRITE => ['series'],
            McpBoundProjectVoter::SITE_REVIEW_READ => ['project', 'site_review_comment'],
            McpBoundProjectVoter::SITE_REVIEW_WRITE => ['project', 'site_review_comment'],
            McpBoundProjectVoter::CARD_READ => ['card'],
            McpBoundProjectVoter::CARD_WRITE => ['card'],
        ];

        foreach ($accepts as $attribute => $accepted) {
            foreach ($subjects as $subject) {
                if (!in_array($subject, $accepted, true)) {
                    yield $attribute.' on '.$subject => [$attribute, $subject];
                }
            }
        }
    }

    #[DataProvider('pairings')]
    public function test_an_attribute_denies_a_subject_type_it_does_not_pair_with(string $attribute, string $subjectType): void
    {
        $project = $this->project($this->user('pairing-'.uniqid().'@example.com'));
        $this->actAsMcpTokenBoundTo($project);

        self::assertFalse($this->authorization->isGranted($attribute, $this->subjectOfType($subjectType, $project)));
    }

    public function test_each_attribute_grants_the_subject_type_it_pairs_with(): void
    {
        $project = $this->project($this->user('pairing-grant@example.com'));
        $this->actAsMcpTokenBoundTo($project);

        self::assertTrue($this->authorization->isGranted(McpBoundProjectVoter::DOCUMENT_READ, $this->subjectOfType('document', $project)));
        self::assertTrue($this->authorization->isGranted(McpBoundProjectVoter::COMMENT_READ, $this->subjectOfType('comment', $project)));
        self::assertTrue($this->authorization->isGranted(McpBoundProjectVoter::SERIES_WRITE, $this->subjectOfType('series', $project)));
        self::assertTrue($this->authorization->isGranted(McpBoundProjectVoter::CARD_READ, $this->subjectOfType('card', $project)));
        self::assertTrue($this->authorization->isGranted(McpBoundProjectVoter::SITE_REVIEW_READ, $this->subjectOfType('project', $project)));
        self::assertTrue($this->authorization->isGranted(McpBoundProjectVoter::SITE_REVIEW_WRITE, $this->subjectOfType('site_review_comment', $project)));
    }

    private function subjectOfType(string $subjectType, Project $project): ProjectScopedSubject
    {
        $owner = $project->owner;

        if ('project' === $subjectType) {
            return $project;
        }

        if ('card' === $subjectType) {
            return $this->cardIn($project);
        }

        if ('series' === $subjectType) {
            $series = new Series($project, 'Series '.uniqid());
            $this->em->persist($series);
            $this->em->flush();

            return $series;
        }

        if ('site_review_comment' === $subjectType) {
            $comment = new SiteReviewComment($project, 0, 'Fix this', 'https://app/x')->addAnchor('.a', 'A');
            $this->em->persist($comment);
            $this->em->flush();

            return $comment;
        }

        $document = new Document(owner: $owner, project: $project, title: 'Doc');
        $document->addVersion('# Hello', '<h1>Hello</h1>');
        $this->em->persist($document);
        $this->em->flush();

        if ('document' === $subjectType) {
            return $document;
        }

        $comment = new Comment($document->currentVersion(), $owner, 'Note', new Anchor('Hello', '# ', '', 2));
        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }

    public function test_a_denied_card_is_recorded_on_the_security_channel(): void
    {
        $owner = $this->user('card-voter-audit@example.com');
        $card = $this->cardIn($this->project($owner));
        $projectB = $this->project($owner);
        $this->actAsMcpTokenBoundTo($projectB);

        $audit = $this->auditedVote(McpBoundProjectVoter::CARD_READ, $card);

        $record = $audit->record('board.mcp_access_denied');
        self::assertSame(AuditOutcome::Refused, $record->outcome);
        self::assertSame(Auditor::CATEGORY_SECURITY, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('card', $record->subject->type);
        self::assertSame((string) $card->id, $record->subject->id);
        self::assertSame([
            'attribute' => McpBoundProjectVoter::CARD_READ,
            'subjectId' => (string) $card->id,
            'subjectProjectId' => (string) $card->project->id,
            'boundProjectId' => (string) $projectB->id,
        ], $record->context);

        self::assertSame(['board.mcp_access_denied'], $audit->securityLogLines());
        self::assertSame([], $audit->domainLogLines());
    }

    public function test_an_unbound_token_records_a_null_bound_project(): void
    {
        $owner = $this->user('card-voter-audit-unbound@example.com');
        $card = $this->cardIn($this->project($owner));
        $this->actAsUnboundMcpToken($owner);

        $audit = $this->auditedVote(McpBoundProjectVoter::CARD_WRITE, $card);

        self::assertNull($audit->record('board.mcp_access_denied')->context['boundProjectId']);
    }

    public function test_a_granted_vote_records_nothing(): void
    {
        $card = $this->cardIn($this->project($this->user('card-voter-audit-grant@example.com')));
        $this->actAsMcpTokenBoundTo($card->project);

        $audit = $this->auditedVote(McpBoundProjectVoter::CARD_READ, $card, VoterInterface::ACCESS_GRANTED);

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
    private function auditedVote(string $attribute, ProjectScopedSubject $subject, int $expected = VoterInterface::ACCESS_DENIED): RecordingAuditor
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
