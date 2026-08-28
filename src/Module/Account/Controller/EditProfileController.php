<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Command\UpdateProfileCommand;
use App\Module\Account\Command\UpdateProfileHandler;
use App\Module\Account\Entity\User;
use App\Module\Account\Form\ProfileFormType;
use App\Module\Account\Form\ProfileRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(
    '/account/profile',
    name: 'app_account_profile',
    methods: ['GET', 'POST'],
)]
class EditProfileController extends AppController
{
    public function __construct(
        private readonly UpdateProfileHandler $updateProfile,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $form = $this->createForm(ProfileFormType::class, new ProfileRequest(fullName: $user->fullName));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            assert($data instanceof ProfileRequest);

            ($this->updateProfile)(new UpdateProfileCommand(
                user: $user,
                fullName: $data->fullName ?? throw new \LogicException('Full name is required after form validation.'),
            ));

            $this->addFlash('success', $this->translator->trans('account.profile.flash.updated'));

            return $this->redirectToRoute('app_account_settings');
        }

        return $this->renderFormResponse('@Account/edit_profile.html.twig', $form);
    }
}
