<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Security;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Security\SiteReviewMcpBoundProjectVoter;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

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
        $comment = new SiteReviewComment($project, 0, 'Fix this', '.a', 'A', 'https://app/x');
        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }

    public function test_grants_the_bound_project_and_its_comments(): void
    {
        $project = $this->project($this->user('sr-voter-grant@example.com'));
        $comment = $this->commentOn($project);
        $this->actAsMcpTokenBoundTo($project);

        self::assertTrue($this->authorization->isGranted(SiteReviewMcpBoundProjectVoter::READ, $project));
        self::assertTrue($this->authorization->isGranted(SiteReviewMcpBoundProjectVoter::WRITE, $project));
        self::assertTrue($this->authorization->isGranted(SiteReviewMcpBoundProjectVoter::READ, $comment));
        self::assertTrue($this->authorization->isGranted(SiteReviewMcpBoundProjectVoter::WRITE, $comment));
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
        self::assertFalse($this->authorization->isGranted(SiteReviewMcpBoundProjectVoter::READ, $projectB));
        self::assertFalse($this->authorization->isGranted(SiteReviewMcpBoundProjectVoter::WRITE, $projectB));
        self::assertFalse($this->authorization->isGranted(SiteReviewMcpBoundProjectVoter::READ, $commentInB));
        self::assertFalse($this->authorization->isGranted(SiteReviewMcpBoundProjectVoter::WRITE, $commentInB));
    }

    public function test_denies_when_the_request_carries_no_token_at_all(): void
    {
        $project = $this->project($this->user('sr-voter-anon@example.com'));
        $comment = $this->commentOn($project);

        $tokenStorage = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokenStorage);
        $tokenStorage->setToken(null);

        self::assertFalse($this->authorization->isGranted(SiteReviewMcpBoundProjectVoter::READ, $project));
        self::assertFalse($this->authorization->isGranted(SiteReviewMcpBoundProjectVoter::WRITE, $comment));
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

        self::assertFalse($this->authorization->isGranted(SiteReviewMcpBoundProjectVoter::READ, $project));
        self::assertFalse($this->authorization->isGranted(SiteReviewMcpBoundProjectVoter::WRITE, $comment));
    }

    public function test_denies_everything_for_a_token_bound_to_no_project(): void
    {
        $owner = $this->user('sr-voter-unbound@example.com');
        $project = $this->project($owner);
        $comment = $this->commentOn($project);
        $this->actAsUnboundMcpToken($owner);

        self::assertFalse($this->authorization->isGranted(SiteReviewMcpBoundProjectVoter::READ, $project));
        self::assertFalse($this->authorization->isGranted(SiteReviewMcpBoundProjectVoter::WRITE, $comment));
    }
}
