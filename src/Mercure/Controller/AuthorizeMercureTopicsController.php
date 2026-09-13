<?php

declare(strict_types=1);

namespace App\Mercure\Controller;

use App\Controller\AppController;
use App\Mercure\Command\AuthorizeMercureTopicsCommand;
use App\Mercure\Command\AuthorizeMercureTopicsHandler;
use App\Mercure\Form\AuthorizeMercureTopicsFormType;
use App\Mercure\Form\AuthorizeMercureTopicsRequest;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Each topic passes through its module's authorizer, so this route needs a
 * signed-in user and nothing more. access_control requires one.
 */
#[Route(
    '/mercure/authorize',
    name: 'app_mercure_authorize',
    methods: ['POST'],
)]
final class AuthorizeMercureTopicsController extends AppController
{
    public function __construct(
        private readonly AuthorizeMercureTopicsHandler $authorize,

        #[Autowire(service: 'limiter.mercure_authorize')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->getUser() ?? throw new \LogicException('access_control lets only a signed-in user reach this route.');
        if (!$this->limiter->create('user:'.$user->getUserIdentifier())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(message: 'Too many subscription renewals.');
        }

        $data = new AuthorizeMercureTopicsRequest();
        $form = $this->createForm(AuthorizeMercureTopicsFormType::class, $data);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return new JsonResponse(['topics' => []], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['topics' => ($this->authorize)(new AuthorizeMercureTopicsCommand(array_values($data->topics)))]);
    }
}
