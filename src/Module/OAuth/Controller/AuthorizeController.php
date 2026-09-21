<?php

declare(strict_types=1);

namespace App\Module\OAuth\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\OAuth\ClientMetadata\ClientIdUrl;
use App\Module\OAuth\Command\PrepareWidgetAuthorizationCommand;
use App\Module\OAuth\Command\PrepareWidgetAuthorizationHandler;
use App\Module\OAuth\Command\RegisterClientMetadataDocumentCommand;
use App\Module\OAuth\Command\RegisterClientMetadataDocumentHandler;
use App\Module\OAuth\Command\ResolveAuthorizationCommand;
use App\Module\OAuth\Command\ResolveAuthorizationHandler;
use App\Module\OAuth\Command\ShowConsentCommand;
use App\Module\OAuth\Command\ShowConsentHandler;
use App\Module\OAuth\Form\ConsentFormType;
use App\Module\OAuth\Form\ConsentRequest;
use App\Module\OAuth\Scope\GrantedScope;
use App\Module\OAuth\Service\McpResource;
use App\Module\OAuth\Service\ResourceParameter;
use App\Module\OAuth\Widget\WidgetAuthorizationRefused;
use App\Module\OAuth\Widget\WidgetClient;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\SubmitButton;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The authorization endpoint. It replaces the bundle's controller, because the
 * bundle's resolve event cannot add the project scope the consent page picks.
 * Every redirect back to the client carries `iss` (RFC 9207), errors included.
 */
#[Route(
    '/oauth/authorize',
    name: 'oauth2_authorize',
    methods: ['GET', 'POST'],
)]
final class AuthorizeController extends AppController
{
    public function __construct(
        #[Autowire(service: 'league.oauth2_server.authorization_server')]
        private readonly AuthorizationServer $server,

        #[Autowire(service: 'league.oauth2_server.factory.psr_http')]
        private readonly HttpMessageFactoryInterface $psrRequests,

        #[Autowire(service: 'league.oauth2_server.factory.http_foundation')]
        private readonly HttpFoundationFactoryInterface $httpFoundation,

        #[Autowire(service: 'league.oauth2_server.factory.psr17')]
        private readonly ResponseFactoryInterface $psrResponses,
        private readonly ShowConsentHandler $showConsent,
        private readonly ResolveAuthorizationHandler $resolveAuthorization,
        private readonly PrepareWidgetAuthorizationHandler $prepareWidget,
        private readonly TranslatorInterface $translator,
        private readonly McpResource $mcpResource,
        private readonly RegisterClientMetadataDocumentHandler $registerClientMetadata,
        private readonly ClientManagerInterface $clients,

        #[Autowire(param: 'app.url')]
        private readonly string $issuer,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); access_control must require ROLE_USER here.', self::class, get_debug_type($user)));
        }

        // League reads response_type without checking that it is set.
        if (!\is_string($request->query->get('response_type'))) {
            return new JsonResponse(['error' => 'invalid_request', 'error_description' => 'The response_type parameter is missing.'], Response::HTTP_BAD_REQUEST);
        }

        $psrRequest = $this->psrRequests->createRequest($request);
        try {
            $clientId = $request->query->get('client_id');
            if (\is_string($clientId) && ClientIdUrl::isCandidate($clientId)) {
                ($this->registerClientMetadata)(new RegisterClientMetadataDocumentCommand($clientId, $user));
            }

            $authorizationRequest = $this->server->validateAuthorizationRequest($psrRequest);
            $widget = WidgetClient::ID === $authorizationRequest->getClient()->getIdentifier()
                ? ($this->prepareWidget)(new PrepareWidgetAuthorizationCommand($authorizationRequest, $user, $request->query->getString('project'), $request->query->getString('origin')))
                : null;
            $scopes = $this->requestedScopes($authorizationRequest);
            if (!$this->mcpResource->accepts(ResourceParameter::values((string) $request->server->get('QUERY_STRING')), \in_array(ApiTokenScope::Mcp, $scopes, true))) {
                throw new OAuthServerException('The resource is not one this server protects for the requested scope.', 0, 'invalid_target', 400, null, $this->errorRedirect($authorizationRequest));
            }

            $view = ($this->showConsent)(new ShowConsentCommand($authorizationRequest, $scopes, $user));
            $form = $this->createForm(ConsentFormType::class, new ConsentRequest(), [
                'action' => $request->getRequestUri(),
                'projects' => $view->projects,
                'needs_project' => $view->needsProject && null === $widget,
            ]);
            $form->handleRequest($request);

            if ($form->isSubmitted()) {
                $deny = $form->get('deny');
                $denied = $deny instanceof SubmitButton && $deny->isClicked();
                if ($denied || $form->isValid()) {
                    try {
                        return $this->toClient(($this->resolveAuthorization)(new ResolveAuthorizationCommand(
                            authorizationRequest: $authorizationRequest,
                            scopes: $scopes,
                            user: $user,
                            approved: !$denied,
                            projectId: $widget?->project->id?->toRfc4122() ?? $form->getData()?->project,
                        )));
                    } catch (DomainErrors $e) {
                        foreach ($e->errors as $field => $translationKey) {
                            ($form->has($field) ? $form->get($field) : $form)->addError(new FormError($this->translator->trans($translationKey)));
                        }
                    }
                }
            }

            return $this->renderFormResponse('@OAuth/authorize.html.twig', $form, ['view' => $view, 'widget' => $widget]);
        } catch (WidgetAuthorizationRefused $e) {
            return $this->render('@OAuth/authorize.html.twig', ['refusal' => $e->reasonKey], new Response(status: $e->status));
        } catch (OAuthServerException $e) {
            // League builds an invalid_client response from the request, and only its own throws set it.
            $e->setServerRequest($psrRequest);
            if ($e->hasRedirect()) {
                $e->setPayload([...$e->getPayload(), 'iss' => $this->issuer()]);
            }

            return $this->httpFoundation->createResponse($e->generateHttpResponse($this->psrResponses->createResponse()));
        }
    }

    /**
     * Exactly one base scope that the client may hold, and no project scope:
     * the project comes from the consent page alone, so a client cannot name
     * one for the user. League checks the client's scopes only at the token
     * endpoint, after the user has already consented.
     */
    /**
     * The base scopes the request asks for. A binding scope is not one of them,
     * so it is taken out before they are read.
     *
     * @return non-empty-list<ApiTokenScope>
     */
    private function requestedScopes(AuthorizationRequestInterface $authorizationRequest): array
    {
        $requested = array_map(static fn ($scope): string => $scope->getIdentifier(), $authorizationRequest->getScopes());

        $allowed = array_map(strval(...), $this->clients->find($authorizationRequest->getClient()->getIdentifier())?->getScopes() ?? []);

        $scopes = [];
        foreach ($requested as $identifier) {
            // The person picks the project on the consent screen. A client that
            // named one itself would bind a grant to a project nobody chose.
            if (null !== GrantedScope::projectIdOf($identifier)) {
                throw OAuthServerException::invalidScope(implode(' ', $requested), $this->errorRedirect($authorizationRequest));
            }
            if ([] !== $allowed && !\in_array($identifier, $allowed, true)) {
                throw OAuthServerException::invalidScope(implode(' ', $requested), $this->errorRedirect($authorizationRequest));
            }
            // A binding rather than a base scope, and checked against the
            // client's own list above, because it widens what the grant reaches.
            if (GrantedScope::ALL_PROJECTS === $identifier) {
                continue;
            }

            $scope = ApiTokenScope::tryFrom($identifier);
            if (null === $scope) {
                throw OAuthServerException::invalidScope(implode(' ', $requested), $this->errorRedirect($authorizationRequest));
            }
            $scopes[] = $scope;
        }

        if ([] === $scopes) {
            throw OAuthServerException::invalidScope(implode(' ', $requested), $this->errorRedirect($authorizationRequest));
        }

        if ('S256' !== $authorizationRequest->getCodeChallengeMethod() && null !== $authorizationRequest->getCodeChallenge()) {
            throw new OAuthServerException('Only the S256 code challenge method is supported.', 3, 'invalid_request', 400, null, $this->errorRedirect($authorizationRequest));
        }

        return $scopes;
    }

    private function errorRedirect(AuthorizationRequestInterface $authorizationRequest): string
    {
        $uri = $authorizationRequest->getRedirectUri() ?? (string) (((array) $authorizationRequest->getClient()->getRedirectUri())[0] ?? '');
        $state = $authorizationRequest->getState();

        return null === $state ? $uri : $uri.(str_contains($uri, '?') ? '&' : '?').http_build_query(['state' => $state]);
    }

    private function toClient(ResponseInterface $response): Response
    {
        $location = $response->getHeaderLine('Location');
        if ('' !== $location) {
            $response = $response->withHeader('Location', $location.(str_contains($location, '?') ? '&' : '?').http_build_query(['iss' => $this->issuer()]));
        }

        return $this->httpFoundation->createResponse($response);
    }

    private function issuer(): string
    {
        return rtrim($this->issuer, '/');
    }
}
