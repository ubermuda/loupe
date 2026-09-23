<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Controller;

use App\Module\GitHub\Entity\GitHubRepositorySelection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A project owner knows the secret of the project's own hook, so a hook
 * signature proves only that the owner or GitHub signed the body. A delivery
 * the owner signs must never take a repository away from another project.
 */
final class ForgedHookClaimTest extends WebTestCase
{
    use GitHubDeliveryScenario;

    private const string APP_SECRET = 'github_app_webhook_test';

    public function test_a_forged_hook_delivery_does_not_take_a_repository_from_another_hook(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->enableBoard();
        $victim = $this->project('victim');
        $forger = $this->project('forger');
        $victimHook = $this->hook($victim);
        $forgerHook = $this->hook($forger);
        $victimCard = $this->linkedCard($victim, 'acme/target', 7);
        $forgerCard = $this->linkedCard($forger, 'acme/target', 7);

        $this->deliver($client, '/webhooks/forge/github/'.$forgerHook->hookKey, 'pull_request', $this->merged(701, 'acme/target', 7), $forgerHook->secret);
        $this->deliver($client, '/webhooks/forge/github/'.$victimHook->hookKey, 'pull_request', $this->merged(701, 'acme/target', 7), $victimHook->secret);

        self::assertSame([(string) $victimCard->id], $this->outboxSubjects($victim));
        self::assertSame([(string) $forgerCard->id], $this->outboxSubjects($forger));
    }

    public function test_a_forged_hook_delivery_does_not_take_a_repository_from_an_installation(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->enableBoard();
        $victim = $this->project('victim');
        $forger = $this->project('forger');
        $this->installation($victim, 9_000_702, GitHubRepositorySelection::All);
        $forgerHook = $this->hook($forger);
        $victimCard = $this->linkedCard($victim, 'acme/target', 7);
        $forgerCard = $this->linkedCard($forger, 'acme/target', 7);

        $this->deliver($client, '/webhooks/forge/github/'.$forgerHook->hookKey, 'pull_request', $this->merged(702, 'acme/target', 7), $forgerHook->secret);
        $this->deliver($client, '/webhooks/forge/github', 'pull_request', $this->merged(702, 'acme/target', 7, ['installation' => ['id' => 9_000_702]]), self::APP_SECRET);

        self::assertSame([(string) $victimCard->id], $this->outboxSubjects($victim));
        self::assertSame([(string) $forgerCard->id], $this->outboxSubjects($forger));
    }
}
