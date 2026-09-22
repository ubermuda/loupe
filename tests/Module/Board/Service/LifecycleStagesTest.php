<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Board\Service\LifecycleStages;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Tag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LifecycleStages::class)]
final class LifecycleStagesTest extends TestCase
{
    /**
     * @param list<string>                         $tags
     * @param array{from: string, to: string}|null $expected
     */
    #[DataProvider('documents')]
    public function test_it_reads_the_stage_from_the_tags(array $tags, ?array $expected): void
    {
        self::assertSame($expected, (new LifecycleStages())->forDocument($this->documentTagged($tags)));
    }

    /** @return iterable<string, array{list<string>, array{from: string, to: string}|null}> */
    public static function documents(): iterable
    {
        $product = ['from' => 'product-design', 'to' => 'tech-design'];
        $techDesign = ['from' => 'tech-design', 'to' => 'implementation'];

        yield 'product' => [['product'], $product];
        yield 'product beside an unrelated tag' => [['product', 'billing'], $product];
        yield 'tech design' => [['design', 'decisions'], $techDesign];
        yield 'design alone names no stage' => [['design'], null];
        yield 'decisions alone names no stage' => [['decisions'], null];
        yield 'no tags' => [[], null];
        yield 'unrelated tags' => [['plan', 'audit'], null];
        // A document tagged for both stages says nothing about which move an
        // approval means, so it moves no card rather than guessing.
        yield 'both stages is ambiguous' => [['product', 'design', 'decisions'], null];
    }

    /**
     * Tag::normalizeName lowercases a name, so a document cannot carry a tag
     * whose case differs. A test for that here would pass with the mapping's
     * own lowercasing removed, which is why there is none.
     *
     * @param list<string> $names
     */
    private function documentTagged(array $names): Document
    {
        $project = $this->createStub(Project::class);
        $document = new Document($this->createStub(User::class), $project, 'A document');
        foreach ($names as $name) {
            $document->tags->add(new Tag($project, $name));
        }

        return $document;
    }
}
