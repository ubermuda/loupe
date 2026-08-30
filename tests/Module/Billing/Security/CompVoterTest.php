<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Security;

use App\Module\Account\Entity\User;
use App\Module\Billing\Security\CompVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class CompVoterTest extends TestCase
{
    private function target(): User
    {
        return new User(fullName: 'Target Account', email: 'target@example.com', password: 'irrelevant');
    }

    /** @param list<string> $roles */
    private function token(array $roles): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getRoleNames')->willReturn($roles);

        return $token;
    }

    public function test_an_admin_may_manage_a_comp(): void
    {
        $vote = new CompVoter()->vote($this->token(['ROLE_USER', 'ROLE_ADMIN']), $this->target(), [CompVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $vote);
    }

    public function test_a_user_without_the_attribute_is_denied(): void
    {
        $vote = new CompVoter()->vote($this->token(['ROLE_USER']), $this->target(), [CompVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $vote);
    }

    public function test_the_voter_abstains_on_another_subject(): void
    {
        $vote = new CompVoter()->vote($this->token(['ROLE_ADMIN']), new \stdClass(), [CompVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $vote);
    }

    public function test_the_voter_abstains_on_another_attribute(): void
    {
        $vote = new CompVoter()->vote($this->token(['ROLE_ADMIN']), $this->target(), ['project.manage']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $vote);
    }
}
