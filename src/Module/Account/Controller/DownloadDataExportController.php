<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Command\DownloadDataExportCommand;
use App\Module\Account\Command\DownloadDataExportHandler;
use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\User;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/account/exports/{id:export}/download',
    name: 'app_account_export_download',
    methods: ['GET'],
)]
class DownloadDataExportController extends AppController
{
    public function __construct(
        private readonly DownloadDataExportHandler $downloadDataExport,
    ) {
    }

    public function __invoke(DataExport $export, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $view = ($this->downloadDataExport)(new DownloadDataExportCommand(
            export: $export,
            user: $user,
            token: (string) $request->query->get('token', ''),
        ));

        if (null === $view) {
            throw $this->createNotFoundException();
        }

        // Streamed rather than redirected, so the bucket need never be reachable
        // from the browser. No Content-Length: the expiry purge runs concurrently,
        // and an object deleted between the size lookup and the read would send a
        // short body — a corrupt ZIP rather than an error.
        $stream = $view->stream;
        $response = new StreamedResponse(static function () use ($stream): void {
            $output = fopen('php://output', 'wb');
            if (false !== $output) {
                stream_copy_to_stream($stream, $output);
                fclose($output);
            }
            fclose($stream);
        });
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $view->fileName,
        ));

        return $response;
    }
}
