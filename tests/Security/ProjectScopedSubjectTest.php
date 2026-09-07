<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Series;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Security\ProjectScopedSubject;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * McpBoundProjectVoter pairs an attribute with a subject through the string
 * scopedSubjectType() returns, not through instanceof. Two implementations
 * returning the same string are therefore one subject to the policy, and the
 * second one silently inherits the first one's attributes.
 *
 * PHPStan cannot see that, because both strings are valid. This is the check
 * that can.
 */
final class ProjectScopedSubjectTest extends KernelTestCase
{
    /**
     * The implementations known when this test was written. It is a floor, not
     * a ceiling: a seventh one is still checked for uniqueness, and a discovery
     * that stops finding these fails here rather than passing on a short list.
     *
     * @var list<class-string<ProjectScopedSubject>>
     */
    private const array KNOWN = [
        Card::class,
        Comment::class,
        Document::class,
        Project::class,
        Series::class,
        SiteReviewComment::class,
    ];

    public function test_every_implementation_is_discovered(): void
    {
        $missing = array_values(array_diff(self::KNOWN, $this->implementations()));

        self::assertSame([], $missing, sprintf(
            'Discovery found no mapping for %s. Every implementation must be reachable here, '
            .'or the uniqueness check below passes on a short list.',
            implode(', ', $missing),
        ));
    }

    public function test_no_two_implementations_claim_the_same_subject_type(): void
    {
        $types = [];
        foreach ($this->implementations() as $class) {
            $types[$class] = new \ReflectionClass($class)->newInstanceWithoutConstructor()->scopedSubjectType();
        }

        $collisions = [];
        foreach (array_count_values($types) as $type => $count) {
            if ($count > 1) {
                $collisions[] = sprintf('"%s" is claimed by %s', $type, implode(' and ', array_keys($types, $type, true)));
            }
        }

        self::assertSame([], $collisions, sprintf(
            'Each implementation must claim its own subject type, because McpBoundProjectVoter '
            .'pairs attributes with that string. %s',
            implode('; ', $collisions),
        ));
        self::assertCount(count($types), array_unique($types));
    }

    /**
     * Read from Doctrine's mapping rather than from get_declared_classes(),
     * which sees only what the run has already autoloaded and would miss the
     * new class this test exists to catch.
     *
     * @return list<class-string<ProjectScopedSubject>>
     */
    private function implementations(): array
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $found = [];
        foreach ($em->getMetadataFactory()->getAllMetadata() as $metadata) {
            $class = $metadata->getName();
            if (is_a($class, ProjectScopedSubject::class, allow_string: true)) {
                $found[] = $class;
            }
        }

        return $found;
    }
}
