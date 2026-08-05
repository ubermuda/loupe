<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Security;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Security\DocumentVoter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class DocumentVoterTest extends TestCase
{
    private DocumentVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new DocumentVoter();
    }

    private function makeUser(string $username): User
    {
        return new User(
            fullName: ucfirst($username),
            email: $username.'@example.com',
            password: 'hashed',
        );
    }

    private function makeToken(User $user): TokenInterface&Stub
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }

    public function test_owner_is_granted_document_view(): void
    {
        $owner = $this->makeUser('alice');
        $document = new Document(owner: $owner, project: new Project($owner, 'p'), title: 'My doc');
        $token = $this->makeToken($owner);

        $result = $this->voter->vote($token, $document, [DocumentVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_non_owner_is_denied_document_view(): void
    {
        $owner = $this->makeUser('alice');
        $other = $this->makeUser('eve');
        $document = new Document(owner: $owner, project: new Project($owner, 'p'), title: 'Alice doc');
        $token = $this->makeToken($other);

        $result = $this->voter->vote($token, $document, [DocumentVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_owner_is_granted_document_manage(): void
    {
        $owner = $this->makeUser('alice');
        $document = new Document(owner: $owner, project: new Project($owner, 'p'), title: 'My doc');
        $token = $this->makeToken($owner);

        $result = $this->voter->vote($token, $document, [DocumentVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_non_owner_is_denied_document_manage(): void
    {
        $owner = $this->makeUser('alice');
        $other = $this->makeUser('eve');
        $document = new Document(owner: $owner, project: new Project($owner, 'p'), title: 'Alice doc');
        $token = $this->makeToken($other);

        $result = $this->voter->vote($token, $document, [DocumentVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_unsupported_attribute_abstains(): void
    {
        $owner = $this->makeUser('alice');
        $document = new Document(owner: $owner, project: new Project($owner, 'p'), title: 'My doc');
        $token = $this->makeToken($owner);

        $result = $this->voter->vote($token, $document, ['document.delete']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function test_non_document_subject_abstains(): void
    {
        $owner = $this->makeUser('alice');
        $token = $this->makeToken($owner);

        $result = $this->voter->vote($token, new \stdClass(), [DocumentVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }
}
