<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Account\Form\ApiTokenFormType;
use App\Module\Account\Form\ApiTokenRequest;
use App\Module\Account\Repository\ApiTokenRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/account/api-tokens',
    name: 'app_api_tokens',
    methods: ['GET'],
)]
class ListApiTokensController extends AppController
{
    public function __construct(
        private readonly ApiTokenRepository $apiTokens,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        assert($user instanceof User);

        $tokens = $this->apiTokens->findBy(['owner' => $user], ['createdAt' => 'DESC']);
        $form = $this->createForm(ApiTokenFormType::class, new ApiTokenRequest());

        return $this->render('@Account/api_tokens/list_api_tokens.html.twig', [
            'tokens' => $tokens,
            'form' => $form,
        ]);
    }
}
