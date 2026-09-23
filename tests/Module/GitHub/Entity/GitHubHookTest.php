<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Entity;

use App\Module\Account\Entity\User;
use App\Module\GitHub\Entity\GitHubHook;
use App\Module\GitHub\Entity\GitHubHookHealth;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitHubHook::class)]
final class GitHubHookTest extends TestCase
{
    public function test_a_new_key_is_26_lowercase_base32_characters(): void
    {
        $keys = [GitHubHook::newKey(), GitHubHook::newKey()];

        foreach ($keys as $key) {
            self::assertMatchesRegularExpression('/^'.GitHubHook::KEY_PATTERN.'$/', $key);
            self::assertSame(26, \strlen($key));
        }
        self::assertNotSame($keys[0], $keys[1]);
    }

    public function test_a_new_secret_is_32_random_bytes_in_hex(): void
    {
        $secret = GitHubHook::newSecret();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
        self::assertNotSame($secret, GitHubHook::newSecret());
    }

    public function test_a_hook_that_never_heard_from_github_is_waiting(): void
    {
        self::assertSame(GitHubHookHealth::Waiting, $this->hook()->health());
    }

    public function test_a_hook_whose_last_delivery_verified_is_working(): void
    {
        $hook = $this->hook();
        $hook->refused(new \DateTimeImmutable('2026-09-23 10:00'), 'bad_signature');
        $hook->accepted(new \DateTimeImmutable('2026-09-23 11:00'));

        self::assertSame(GitHubHookHealth::Working, $hook->health());
        self::assertSame('bad_signature', $hook->lastRefusedReason, 'An old failure stays on record.');
    }

    public function test_a_hook_whose_last_delivery_failed_is_failing(): void
    {
        $hook = $this->hook();
        $hook->accepted(new \DateTimeImmutable('2026-09-23 10:00'));
        $hook->refused(new \DateTimeImmutable('2026-09-23 11:00'), 'bad_signature');

        self::assertSame(GitHubHookHealth::Failing, $hook->health());
        self::assertEquals(new \DateTimeImmutable('2026-09-23 11:00'), $hook->lastRefusedAt);
    }

    private function hook(): GitHubHook
    {
        return new GitHubHook(new Project(new User(fullName: 'Riley', email: 'riley@example.com', password: 'x'), 'hooked'), GitHubHook::newKey(), GitHubHook::newSecret());
    }
}
