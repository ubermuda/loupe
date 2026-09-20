<?php

declare(strict_types=1);

namespace App\Module\OAuth\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\OAuth\Command\ResolveDeviceAuthorizationCommand;
use App\Module\OAuth\Command\ResolveDeviceAuthorizationHandler;
use App\Module\OAuth\Command\ShowDeviceConsentCommand;
use App\Module\OAuth\Command\ShowDeviceConsentHandler;
use App\Module\OAuth\Form\DeviceCodeEntryFormType;
use App\Module\OAuth\Form\DeviceCodeEntryRequest;
use App\Module\OAuth\Form\DeviceConsentFormType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\SubmitButton;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The verification page of the device flow (RFC 8628). With no user_code in
 * the URL it asks for the code. With a pending one it shows the consent.
 */
#[Route(
    '/oauth/device',
    name: 'oauth2_device_verify',
    methods: ['GET', 'POST'],
)]
final class VerifyDeviceController extends AppController
{
    public function __construct(
        private readonly ShowDeviceConsentHandler $showConsent,
        private readonly ResolveDeviceAuthorizationHandler $resolveDeviceAuthorization,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); access_control must require ROLE_USER here.', self::class, get_debug_type($user)));
        }

        $errors = [];
        $userCode = $request->query->get('user_code');
        if (\is_string($userCode) && '' !== $userCode) {
            try {
                $view = ($this->showConsent)(new ShowDeviceConsentCommand($userCode, $user));
                $consent = $this->createForm(DeviceConsentFormType::class, null, ['action' => $request->getRequestUri()]);
                $consent->handleRequest($request);

                if (!$consent->isSubmitted() || !$consent->isValid()) {
                    return $this->renderFormResponse('@OAuth/verify_device.html.twig', $consent, ['view' => $view, 'result' => null]);
                }

                $approve = $consent->get('approve');
                $approved = $approve instanceof SubmitButton && $approve->isClicked();
                ($this->resolveDeviceAuthorization)(new ResolveDeviceAuthorizationCommand($userCode, $user, $approved));

                return $this->render('@OAuth/verify_device.html.twig', ['form' => null, 'view' => $view, 'result' => $approved ? 'approved' : 'denied']);
            } catch (DomainErrors $e) {
                $errors = $e->errors;
            }
        }

        $entry = $this->createForm(DeviceCodeEntryFormType::class, new DeviceCodeEntryRequest(\is_string($userCode) ? $userCode : null), [
            'action' => $this->generateUrl('oauth2_device_verify'),
        ]);
        $entry->handleRequest($request);

        if ($entry->isSubmitted() && $entry->isValid()) {
            try {
                $view = ($this->showConsent)(new ShowDeviceConsentCommand($entry->getData()->userCode ?? '', $user));

                return $this->redirectToRoute('oauth2_device_verify', ['user_code' => $view->userCode]);
            } catch (DomainErrors $e) {
                $errors = $e->errors;
            }
        }

        foreach ($errors as $field => $translationKey) {
            $entry->get($field)->addError(new FormError($this->translator->trans($translationKey)));
        }

        return $this->renderFormResponse('@OAuth/verify_device.html.twig', $entry, ['view' => null, 'result' => null]);
    }
}
