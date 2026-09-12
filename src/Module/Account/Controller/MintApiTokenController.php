<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Command\MintApiTokenCommand;
use App\Module\Account\Command\MintApiTokenHandler;
use App\Module\Account\Entity\User;
use App\Module\Account\Form\MintApiTokenFormType;
use App\Module\Account\Form\MintApiTokenRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/account/api-tokens',
    name: 'app_api_token_mint',
    methods: ['POST'],
)]
class MintApiTokenController extends AppController
{
    public function __construct(
        private readonly MintApiTokenHandler $mintApiToken,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $data = new MintApiTokenRequest();
        $form = $this->createForm(MintApiTokenFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // NotBlank guarantees a value, but "0" is a legal label that `?:` would
            // wrongly reject — narrow with an explicit empty check instead.
            $label = trim($data->label ?? '');
            if ('' === $label) {
                throw new \LogicException('label required after validation');
            }

            $raw = ($this->mintApiToken)(new MintApiTokenCommand(
                owner: $user,
                label: $label,
                scope: $data->scope ?? throw new \LogicException('scope required after validation'),
            ));

            // A dedicated flash key, whitelisted out of the layout's flash strip,
            // so the raw value renders once in a copyable field and never as a
            // notification. It is not stored, so a reload cannot show it again.
            $this->addFlash('minted_api_token', $raw);

            return $this->redirectToRoute('app_account_settings');
        }

        // 422 so Turbo renders the re-bound form and the error lands on the field
        // rather than in a lossy flash.
        return $this->forward(ShowAccountSettingsController::class, [
            'mintForm' => $form->createView(),
        ])->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
