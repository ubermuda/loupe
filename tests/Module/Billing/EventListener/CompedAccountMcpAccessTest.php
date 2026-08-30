<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\EventListener;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Billing\Command\Admin\GrantCompCommand;
use App\Module\Billing\Command\Admin\GrantCompHandler;
use App\Module\Project\Entity\Project;
use App\Tests\Support\BillingScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restoring machine access is what the comp exists for. The lapsed-trial case
 * is the positive control: without it a non-402 proves nothing, because a
 * still-running trial produces one too.
 */
final class CompedAccountMcpAccessTest extends WebTestCase
{
    private const string INIT = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}';

    public function test_a_lapsed_trial_gets_a_402_on_mcp(): void
    {
        $client = static::createClient();
        $raw = $this->seed($client, 'mcp-lapsed', comped: false);

        $this->callMcp($client, $raw);

        self::assertResponseStatusCodeSame(Response::HTTP_PAYMENT_REQUIRED);
        self::assertStringContainsString('subscription_required', (string) $client->getResponse()->getContent());
    }

    public function test_a_comped_account_reaches_mcp(): void
    {
        $client = static::createClient();
        $raw = $this->seed($client, 'mcp-comped', comped: true);

        $this->callMcp($client, $raw);

        self::assertResponseIsSuccessful();
    }

    private function callMcp(KernelBrowser $client, string $raw): void
    {
        $client->request(Request::METHOD_POST, '/mcp', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
            'CONTENT_TYPE' => 'application/json',
        ], content: self::INIT);
    }

    /** Returns the raw MCP token of an account whose trial lapsed yesterday. */
    private function seed(KernelBrowser $client, string $prefix, bool $comped): string
    {
        $client->disableReboot();
        $scenario = new BillingScenario(static::getContainer());
        $scenario->enableBilling();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $scenario->verifiedUser($prefix);
        $scenario->profile($user, new \DateTimeImmutable('-1 day'));
        $em->persist(new Project($user, $prefix.'-site'));

        [$token, $raw] = ApiToken::issue($user, 'tok', ApiTokenScope::Mcp);
        $em->persist($token);
        $em->flush();

        if ($comped) {
            $admin = $scenario->verifiedUser($prefix.'admin');
            $admin->roles = ['ROLE_ADMIN'];
            $em->flush();

            static::getContainer()->get(GrantCompHandler::class)(new GrantCompCommand($user, $admin));
        }

        $em->clear();

        return $raw;
    }
}
