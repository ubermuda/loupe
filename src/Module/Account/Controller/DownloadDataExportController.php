<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\User;
use App\Routing\PaywallExempt;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
        private readonly FilesystemOperator $exportStorage,
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
        $key = DataExport::computeArchiveKey($exportId);

        // The archive is streamed rather than redirected to: the bucket is
        // never assumed to be reachable from the browser, which is what lets a
        // self-hosted install keep it entirely private.
        try {
            $size = $this->exportStorage->fileSize($key);
            $stream = $this->exportStorage->readStream($key);
        } catch (FilesystemException) {
            throw $this->createNotFoundException();
        }

        $response = new StreamedResponse(static function () use ($stream): void {
            $output = fopen('php://output', 'wb');
            if (false !== $output) {
                stream_copy_to_stream($stream, $output);
                fclose($output);
            }
            fclose($stream);
        });
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Length', (string) $size);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            sprintf('loupe-export-%s.zip', (string) $exportId),
        ));

        return $response;
    }
}
