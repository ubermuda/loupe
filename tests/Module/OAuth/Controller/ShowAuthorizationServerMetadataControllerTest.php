<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShowAuthorizationServerMetadataControllerTest extends WebTestCase
{
    public function test_it_serves_rfc_8414_metadata_to_an_anonymous_client(): void
    {
        $browser = static::createClient();
        $browser->request(Request::METHOD_GET, '/.well-known/oauth-authorization-server');

        self::assertResponseIsSuccessful();
        self::assertFalse($browser->getResponse()->headers->has('Set-Cookie'), 'the endpoint is stateless');

        $issuer = rtrim((string) static::getContainer()->getParameter('app.url'), '/');
        $metadata = json_decode((string) $browser->getResponse()->getContent(), true);
        self::assertIsArray($metadata);
        self::assertSame($issuer, $metadata['issuer']);
        self::assertSame($issuer.'/oauth/authorize', $metadata['authorization_endpoint']);
        self::assertSame($issuer.'/oauth/token', $metadata['token_endpoint']);
        self::assertSame(['mcp', 'site-review', 'agent'], $metadata['scopes_supported']);
        self::assertSame(['code'], $metadata['response_types_supported']);
        self::assertSame(['authorization_code', 'refresh_token', 'urn:ietf:params:oauth:grant-type:device_code'], $metadata['grant_types_supported']);
        self::assertSame($issuer.'/oauth/device-authorization', $metadata['device_authorization_endpoint']);
        self::assertSame(['S256'], $metadata['code_challenge_methods_supported']);
        self::assertContains('none', $metadata['token_endpoint_auth_methods_supported']);
        self::assertTrue($metadata['authorization_response_iss_parameter_supported']);
        self::assertArrayNotHasKey('registration_endpoint', $metadata);
        self::assertArrayNotHasKey('client_id_metadata_document_supported', $metadata);
    }

    public function test_the_token_endpoint_is_public_and_answers_rfc_6749_errors(): void
    {
        $browser = static::createClient();
        $browser->request(Request::METHOD_POST, '/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => 'nobody']);

        self::assertSame(400, $browser->getResponse()->getStatusCode());
        self::assertSame('unsupported_grant_type', json_decode((string) $browser->getResponse()->getContent(), true)['error'] ?? null);
    }

    public function test_the_password_grant_is_not_enabled(): void
    {
        $browser = static::createClient();
        $browser->request(Request::METHOD_POST, '/oauth/token', ['grant_type' => 'password', 'client_id' => 'nobody', 'username' => 'a', 'password' => 'b']);

        self::assertSame('unsupported_grant_type', json_decode((string) $browser->getResponse()->getContent(), true)['error'] ?? null);
    }
}
