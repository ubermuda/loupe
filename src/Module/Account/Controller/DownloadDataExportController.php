<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\User;
use App\Routing\PaywallExempt;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[PaywallExempt]
#[Route(
    '/account/exports/{id:export}/download',
    name: 'app_account_export_download',
    methods: ['GET'],
)]
class DownloadDataExportController extends AppController
{
    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(DataExport $export, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $token = (string) $request->query->get('token', '');

        if (null === $export->user->id || null === $user->id
            || !$export->user->id->equals($user->id)
            || !$export->isDownloadTokenValid($token)) {
            $this->logger->info('account.data_export.download_denied', [
                'id' => (string) $export->id,
            ]);

            throw $this->createNotFoundException();
        }

        $exportId = $export->id ?? throw new \LogicException('resolved export always has an id');
        $path = DataExport::computeArchivePath($this->projectDir, $exportId);
        if (!is_file($path)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('loupe-export-%s.zip', (string) $exportId),
        );

        return $response;
    }
}
