<?php

declare(strict_types=1);

namespace App\Module\Diagnostics\Check;

use App\Module\Diagnostics\Diagnostic;
use App\Module\Diagnostics\DiagnosticInterface;
use App\Module\Diagnostics\DiagnosticState;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * Builds the configured transport and, when it speaks SMTP, opens a real
 * connection to it. Anything else is reported as unverifiable rather than
 * assumed working: an API transport would need a live credentialed call to
 * prove anything, and a status page must not spend the operator's quota.
 */
final readonly class MailerTransportCheck implements DiagnosticInterface
{
    /** A firewalled SMTP host must make the page slow, never make it hang. */
    private const float PROBE_TIMEOUT_SECONDS = 3.0;

    public function __construct(
        #[Autowire(service: 'mailer.transport_factory')]
        private Transport $transportFactory,

        #[Autowire('%env(default::MAILER_DSN)%')]
        private ?string $mailerDsn,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public static function priority(): int
    {
        return 70;
    }

    #[\Override]
    public function __invoke(): Diagnostic
    {
        if (null === $this->mailerDsn || '' === $this->mailerDsn) {
            return new Diagnostic('mailer', DiagnosticState::Failed, 'account.system_status.mailer.unset');
        }

        try {
            $transport = $this->transportFactory->fromString($this->mailerDsn);
        } catch (\Throwable $e) {
            $this->logger->warning('account.system_status.mailer_dsn_invalid', ['exception' => $e]);

            return new Diagnostic('mailer', DiagnosticState::Failed, 'account.system_status.mailer.invalid');
        }

        if ($transport instanceof NullTransport) {
            return new Diagnostic('mailer', DiagnosticState::Failed, 'account.system_status.mailer.null_transport');
        }

        if (!$transport instanceof SmtpTransport) {
            return new Diagnostic('mailer', DiagnosticState::Unknown, 'account.system_status.mailer.unverifiable');
        }

        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout(self::PROBE_TIMEOUT_SECONDS);
        }

        try {
            $transport->start();
            $transport->stop();
        } catch (\Throwable $e) {
            $this->logger->warning('account.system_status.smtp_unreachable', ['exception' => $e]);

            return new Diagnostic('mailer', DiagnosticState::Failed, 'account.system_status.mailer.smtp_unreachable');
        }

        return new Diagnostic('mailer', DiagnosticState::Ok, 'account.system_status.mailer.smtp_reachable');
    }
}
