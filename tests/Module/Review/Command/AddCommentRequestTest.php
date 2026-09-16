<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Module\Review\Form\AddCommentRequest;
use App\Module\Review\Form\StrikePassageRequest;
use App\Module\Review\Form\SuggestRewordingRequest;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The anchor columns (Anchor value object) are VARCHAR(255) for prefix/suffix; a
 * hand-crafted POST bypassing the Stimulus controller's 32-character cap would
 * otherwise hit a driver exception (500) instead of a validation error.
 */
final class AddCommentRequestTest extends KernelTestCase
{
    public function test_each_annotation_requires_a_positive_version_number(): void
    {
        self::bootKernel();
        $validator = self::getContainer()->get(ValidatorInterface::class);
        self::assertInstanceOf(ValidatorInterface::class, $validator);

        foreach ([
            new AddCommentRequest(versionNumber: 1, body: 'Comment'),
            new StrikePassageRequest(versionNumber: 1, quote: 'Passage'),
            new SuggestRewordingRequest(versionNumber: 1, quote: 'Passage', replacement: 'Replacement'),
        ] as $request) {
            self::assertCount(0, $validator->validate($request));
            foreach ([null, 0, -1] as $versionNumber) {
                $request->versionNumber = $versionNumber;
                $violations = $validator->validate($request);
                self::assertCount(1, $violations);
                $violation = $violations[0];
                self::assertNotNull($violation);
                self::assertSame('versionNumber', $violation->getPropertyPath());
            }
        }
    }

    public function test_overlong_prefix_and_suffix_are_rejected(): void
    {
        self::bootKernel();
        $validator = self::getContainer()->get(ValidatorInterface::class);
        self::assertInstanceOf(ValidatorInterface::class, $validator);

        $request = new AddCommentRequest(
            versionNumber: 1,
            quote: 'ok',
            prefix: str_repeat('a', 256),
            suffix: str_repeat('b', 256),
            body: 'A valid body',
        );

        $violations = $validator->validate($request);
        $paths = [];
        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        self::assertContains('prefix', $paths);
        self::assertContains('suffix', $paths);
    }

    public function test_overlong_quote_is_rejected(): void
    {
        self::bootKernel();
        $validator = self::getContainer()->get(ValidatorInterface::class);
        self::assertInstanceOf(ValidatorInterface::class, $validator);

        $request = new AddCommentRequest(
            versionNumber: 1,
            quote: str_repeat('a', 2001),
            prefix: '',
            suffix: '',
            body: 'A valid body',
        );

        $violations = $validator->validate($request);
        $paths = [];
        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        self::assertContains('quote', $paths);
    }

    public function test_fields_within_bounds_are_valid(): void
    {
        self::bootKernel();
        $validator = self::getContainer()->get(ValidatorInterface::class);
        self::assertInstanceOf(ValidatorInterface::class, $validator);

        $request = new AddCommentRequest(
            versionNumber: 1,
            quote: str_repeat('a', 2000),
            prefix: str_repeat('b', 255),
            suffix: str_repeat('c', 255),
            body: 'A valid body',
        );

        self::assertCount(0, $validator->validate($request));
    }
}
