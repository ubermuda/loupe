<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\User;
use App\Module\Account\Form\ApiTokenFormType;
use App\Module\Account\Form\ApiTokenRequest;
use App\Module\Account\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/account/api-tokens',
    name: 'app_api_token_create',
    methods: ['POST'],
)]
class CreateApiTokenController extends AppController
{
    public function __construct(
        private readonly ApiTokenRepository $apiTokens,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        assert($user instanceof User);

        $data = new ApiTokenRequest();
        $form = $this->createForm(ApiTokenFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $label = $data->label ?: throw new \LogicException('Label is required after form validation.');
            [$token, $raw] = ApiToken::issue($user, $label);
            $this->em->persist($token);
            $this->em->flush();

            $this->addFlash('success', sprintf(
                'Token "%s" created. Copy it now — it will not be shown again: %s',
                $token->label,
                $raw,
            ));

            $this->logger->info('account.api_token.created', [
                'userId' => (string) $user->id,
                'tokenId' => (string) $token->id,
                'label' => $token->label,
            ]);

            return $this->redirectToRoute('app_api_tokens');
        }

        $tokens = $this->apiTokens->findBy(['owner' => $user], ['createdAt' => 'DESC']);

        return $this->renderFormResponse('@Account/api_tokens/list_api_tokens.html.twig', $form, [
            'tokens' => $tokens,
        ]);
    }
}
