<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Security;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Security\BoardColumnVoter;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class BoardColumnVoterTest extends TestCase
{
    private BoardColumnVoter $voter;
    private User $owner;
    private Project $project;
    private BoardColumn $column;

    protected function setUp(): void
    {
        $this->voter = new BoardColumnVoter(new NullLogger());
        $this->owner = $this->makeUser('alice');
        $this->project = new Project($this->owner, 'p');
        $this->column = new BoardColumn(project: $this->project, label: 'Backlog', slug: 'backlog', position: 0);
    }

    /** @return iterable<string, array{'project'|'column'}> */
    public static function subjects(): iterable
    {
        yield 'the project' => ['project'];
        yield 'a column' => ['column'];
    }

    #[DataProvider('subjects')]
    public function test_the_project_owner_is_granted(string $subject): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->makeToken($this->owner), $this->subject($subject), [BoardColumnVoter::MANAGE]));
    }

    #[DataProvider('subjects')]
    public function test_a_stranger_is_denied(string $subject): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->makeToken($this->makeUser('eve')), $this->subject($subject), [BoardColumnVoter::MANAGE]));
    }

    private function subject(string $name): Project|BoardColumn
    {
        return 'project' === $name ? $this->project : $this->column;
    }

    public function test_an_admin_who_does_not_own_the_project_is_denied(): void
    {
        $admin = $this->makeUser('root');
        $admin->roles = ['ROLE_ADMIN'];

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->makeToken($admin), $this->column, [BoardColumnVoter::MANAGE]));
    }

    public function test_another_subject_is_abstained_on(): void
    {
        $card = new Card(project: $this->project, column: $this->column, title: 'Card', body: '', number: 1);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($this->makeToken($this->owner), $card, [BoardColumnVoter::MANAGE]));
    }

    public function test_another_attribute_is_abstained_on(): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($this->makeToken($this->owner), $this->column, ['card.write']));
    }

    private function makeUser(string $username): User
    {
        return new User(fullName: ucfirst($username), email: $username.'@example.com', password: 'hashed');
    }

    private function makeToken(User $user): TokenInterface&Stub
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}
