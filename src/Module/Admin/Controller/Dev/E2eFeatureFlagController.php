<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller\Dev;

use App\Controller\AppController;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

/**
 * Dev-only endpoint that upserts a boolean feature flag, so Playwright specs can
 * switch a flag on or off without driving the admin UI or logging in as an
 * administrator. Form parameters: name, enabled (0|1).
 *
 * Guarded twice, like the other dev seams: the #[When('dev')] attribute keeps
 * the controller service (and its route) out of every other environment, and the
 * runtime environment check refuses to run if it somehow existed there.
 */
#[Route(
    '/dev/e2e/feature-flag',
    name: 'dev_e2e_feature_flag',
    methods: ['POST'],
)]
#[When('dev')]
final class E2eFeatureFlagController extends AppController
{
    public function __construct(
        private readonly FeatureFlagRepository $featureFlags,
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (!in_array($this->environment, ['dev', 'test'], true)) {
            throw $this->createNotFoundException();
        }

        $name = $request->request->getString('name');
        if ('' === $name) {
            return $this->json(['error' => 'name is required'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $enabled = $request->request->getBoolean('enabled');

        $flag = $this->featureFlags->findOneBy(['name' => $name]);
        if (null === $flag) {
            $flag = new FeatureFlag($name, FeatureFlagType::Bool, $enabled);
            $this->em->persist($flag);
        } else {
            $flag->type = FeatureFlagType::Bool;
            $flag->value = $enabled;
        }
        $this->em->flush();

        return $this->json(['name' => $name, 'enabled' => $enabled]);
    }
}
