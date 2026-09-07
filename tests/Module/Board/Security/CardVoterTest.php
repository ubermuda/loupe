<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Security;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Security\CardVoter;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class CardVoterTest extends TestCase
{
    private CardVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new CardVoter();
    }

    /** @return iterable<string, array{string}> */
    public static function attributes(): iterable
    {
        yield 'view' => [CardVoter::VIEW];
        yield 'write' => [CardVoter::WRITE];
    }

    #[DataProvider('attributes')]
    public function test_the_project_owner_is_granted(string $attribute): void
    {
        $owner = $this->makeUser('alice');
        $card = $this->makeCard($owner);

        $result = $this->voter->vote($this->makeToken($owner), $card, [$attribute]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[DataProvider('attributes')]
    public function test_a_stranger_is_denied(string $attribute): void
    {
        $owner = $this->makeUser('alice');
        $card = $this->makeCard($owner);

        $result = $this->voter->vote($this->makeToken($this->makeUser('eve')), $card, [$attribute]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_an_unrelated_attribute_is_abstained_on(): void
    {
        $owner = $this->makeUser('alice');

        $result = $this->voter->vote($this->makeToken($owner), $this->makeCard($owner), ['card.something_else']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    private function makeCard(User $owner): Card
    {
        return new Card(project: new Project($owner, 'p'), title: 'Ship the board', body: 'Body', number: 1);
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
