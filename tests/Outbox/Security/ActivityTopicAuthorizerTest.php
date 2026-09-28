<?php

declare(strict_types=1);

namespace App\Tests\Outbox\Security;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Outbox\Security\ActivityTopicAuthorizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

final class ActivityTopicAuthorizerTest extends KernelTestCase
{
    private User $owner;
    private Project $project;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->owner = $this->user('activity-topic-owner@example.com');
        $this->project = $this->project($this->owner);
    }

    public function test_a_viewer_of_the_project_may_subscribe(): void
    {
        $this->signIn($this->owner);

        self::assertTrue($this->authorizer()->mayCurrentUserSubscribe($this->activityTopic($this->project)));
    }

    public function test_a_stranger_is_refused(): void
    {
        $stranger = $this->user('activity-topic-stranger@example.com');
        $this->signIn($stranger);

        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->activityTopic($this->project)));
        // Guard: the stranger passes on a project of their own, so the refusal is about the project.
        self::assertTrue($this->authorizer()->mayCurrentUserSubscribe($this->activityTopic($this->project($stranger))));
    }

    public function test_nobody_signed_in_is_refused(): void
    {
        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->activityTopic($this->project)));
    }

    public function test_a_project_that_does_not_exist_is_refused(): void
    {
        $this->signIn($this->owner);

        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->topics()->forActivity(Uuid::v7())));
    }

    public function test_it_claims_no_other_topic(): void
    {
        $this->signIn($this->owner);
        $projectId = $this->project->id ?? throw new \LogicException('The project has no id.');

        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($this->topics()->forBoard($projectId)));
        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($this->topics()->forProject($projectId)));
        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($this->topics()->forWorkerRuns($projectId)));
        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($this->topics()->forActivity($projectId).'/extra'));
    }

    private function signIn(User $user): void
    {
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function activityTopic(Project $project): string
    {
        return $this->topics()->forActivity($project->id ?? throw new \LogicException('The project has no id.'));
    }

    private function topics(): ProjectTopicBuilder
    {
        $topics = self::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);

        return $topics;
    }

    private function authorizer(): ActivityTopicAuthorizer
    {
        $authorizer = self::getContainer()->get(ActivityTopicAuthorizer::class);
        self::assertInstanceOf(ActivityTopicAuthorizer::class, $authorizer);

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

    private function project(User $owner): Project
    {
        $project = new Project($owner, 'activity-topic-'.bin2hex(random_bytes(4)));
        $this->em()->persist($project);
        $this->em()->flush();

        return $project;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
