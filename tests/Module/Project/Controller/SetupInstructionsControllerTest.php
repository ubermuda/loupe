<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class SetupInstructionsControllerTest extends WebTestCase
{
    private const string PROJECT_ID = '0192f4c8-7b1e-7a3d-9c2f-5e6a7b8c9d0e';

    public function test_an_anonymous_agent_reads_markdown_with_the_instance_url(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/setup.md');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/markdown; charset=utf-8');
        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('loupe login --url http://localhost ', $body);
        self::assertStringContainsString('curl -fsSL http://localhost/install.sh | LOUPE_INSTALL_NO_TTY=1 sh', $body);
        self::assertStringContainsString('<project id>', $body);
        // Shell redirections in the steps prove the text is not HTML-escaped.
        self::assertStringContainsString('> /tmp/loupe-login.log 2>&1 &', $body);
        self::assertStringNotContainsString('&gt;', $body);
        self::assertStringNotContainsString('&amp;', $body);
        self::assertStringNotContainsString('&lt;', $body);
    }

    public function test_the_steps_ask_about_worker_folders_before_the_finish(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/setup.md');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString("\n## 9. Worker folders\n", $body);
        self::assertStringContainsString("\n## 10. Finish\n", $body);
        self::assertLessThan(strpos($body, '## 10. Finish'), strpos($body, '## 9. Worker folders'));
        self::assertStringContainsString('before:', $body);
        self::assertStringContainsString('action: command', $body);
        self::assertStringContainsString("work:\n  implement:", $body);
        self::assertStringNotContainsString('on: board.card_moved', $body);
        self::assertStringContainsString('Go to step 8.', $body);
    }

    public function test_a_valid_project_id_fills_the_commands(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/setup.md?project='.self::PROJECT_ID);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('loupe init --project '.self::PROJECT_ID.' --mcp', $body);
        self::assertStringContainsString('codex mcp add loupe -- loupe mcp --project '.self::PROJECT_ID, $body);
        self::assertStringNotContainsString('<project id>', $body);
    }

    public function test_an_invalid_project_value_is_dropped(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/setup.md', ['project' => 'evil"; rm -rf ~']);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString('evil', $body);
        self::assertStringNotContainsString('rm -rf', $body);
        self::assertStringContainsString('<project id>', $body);
    }

    public function test_an_array_project_value_is_dropped(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/setup.md?project[]='.self::PROJECT_ID);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::PROJECT_ID, (string) $client->getResponse()->getContent());
    }
}
