<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Security;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Security\CommentVoter;
use App\Module\Review\ValueObject\Anchor;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class CommentVoterTest extends TestCase
{
    private CommentVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new CommentVoter();
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

    private function makeComment(User $owner, User $author): Comment
    {
        $document = new Document(owner: $owner, project: new Project($owner, 'p'), title: 'My doc');
        $version = new DocumentVersion(
            document: $document,
            versionNumber: 1,
            markdownSource: '# Title',
            renderedHtml: '<h1>Title</h1>',
        );

        return new Comment(
            version: $version,
            author: $author,
            body: 'A comment',
            anchor: new Anchor(quote: '', prefix: '', suffix: '', offsetHint: 0),
        );
    }

    public function test_document_owner_is_granted_comment_delete(): void
    {
        $owner = $this->makeUser('alice');
        $comment = $this->makeComment(owner: $owner, author: $owner);
        $token = $this->makeToken($owner);

        $result = $this->voter->vote($token, $comment, [CommentVoter::DELETE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_comment_author_who_is_not_document_owner_is_denied(): void
    {
        $owner = $this->makeUser('alice');
        $author = $this->makeUser('bob');
        $comment = $this->makeComment(owner: $owner, author: $author);
        $token = $this->makeToken($author);

        $result = $this->voter->vote($token, $comment, [CommentVoter::DELETE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_unrelated_user_is_denied_comment_delete(): void
    {
        $owner = $this->makeUser('alice');
        $other = $this->makeUser('eve');
        $comment = $this->makeComment(owner: $owner, author: $owner);
        $token = $this->makeToken($other);

        $result = $this->voter->vote($token, $comment, [CommentVoter::DELETE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_document_owner_is_granted_comment_resolve_and_reply(): void
    {
        $owner = $this->makeUser('alice');
        $comment = $this->makeComment(owner: $owner, author: $owner);
        $token = $this->makeToken($owner);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $comment, [CommentVoter::RESOLVE]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $comment, [CommentVoter::REPLY]));
    }

    public function test_non_owner_is_denied_comment_resolve_and_reply(): void
    {
        $owner = $this->makeUser('alice');
        $other = $this->makeUser('eve');
        $comment = $this->makeComment(owner: $owner, author: $owner);
        $token = $this->makeToken($other);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $comment, [CommentVoter::RESOLVE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $comment, [CommentVoter::REPLY]));
    }

    public function test_unsupported_attribute_abstains(): void
    {
        $owner = $this->makeUser('alice');
        $comment = $this->makeComment(owner: $owner, author: $owner);
        $token = $this->makeToken($owner);

        $result = $this->voter->vote($token, $comment, ['comment.view']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function test_non_comment_subject_abstains(): void
    {
        $owner = $this->makeUser('alice');
        $token = $this->makeToken($owner);

        $result = $this->voter->vote($token, new \stdClass(), [CommentVoter::DELETE]);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }
}
