<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Security;

use App\Module\Review\Security\DocumentVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A read permission on a state-changing route is a security gap waiting for the
 * day VIEW is widened to collaborators — the writes would come along silently.
 * DocumentVoter's three arms are identical today, so nothing else would notice.
 */
final class WriteRoutesRequireWritePermissionTest extends TestCase
{
    private const array WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function test_no_review_controller_guards_a_write_with_document_view(): void
    {
        $offenders = [];
        $checked = 0;

        foreach (glob(__DIR__.'/../../../../src/Module/Review/Controller/*.php') ?: [] as $file) {
            $class = 'App\\Module\\Review\\Controller\\'.basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            $methods = [];
            foreach ($reflection->getAttributes(Route::class) as $route) {
                $methods = [...$methods, ...(array) ($route->getArguments()['methods'] ?? [])];
            }
            if ([] === array_intersect($methods, self::WRITE_METHODS)) {
                continue;
            }

            ++$checked;
            foreach ($reflection->getAttributes(IsGranted::class) as $granted) {
                if (DocumentVoter::VIEW === $granted->newInstance()->attribute) {
                    $offenders[] = $reflection->getShortName();
                }
            }
        }

        // Without this the assertion below would also pass on a glob that matched
        // nothing, or a rename that moved every write route out of the directory.
        self::assertGreaterThanOrEqual(8, $checked, 'expected the review write routes to be discovered');
        self::assertSame([], $offenders);
    }
}
