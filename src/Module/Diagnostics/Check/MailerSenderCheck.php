<?php

declare(strict_types=1);

namespace App\Module\Diagnostics\Check;

use App\Module\Diagnostics\Diagnostic;
use App\Module\Diagnostics\DiagnosticInterface;
use App\Module\Diagnostics\DiagnosticState;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class MailerSenderCheck implements DiagnosticInterface
{
    /**
     * Domain of the shipped MAILER_FROM_ADDRESS default. Any address on it is
     * deliverable nowhere, so an instance still using one cannot complete a
     * single registration — and matching the domain rather than the exact
     * default also catches the operator who edited the local part and stopped.
     */
    private const string PLACEHOLDER_FROM_DOMAIN = '@localhost';

    public function __construct(
        #[Autowire(param: 'app.mailer.from_address')]
        private string $mailerFromAddress,
    ) {
    }

    #[\Override]
    public static function priority(): int
    {
        return 60;
    }

    #[\Override]
    public function __invoke(): Diagnostic
    {
        if ('' === $this->mailerFromAddress || str_ends_with($this->mailerFromAddress, self::PLACEHOLDER_FROM_DOMAIN)) {
            return new Diagnostic('mailer_sender', DiagnosticState::Warning, 'account.system_status.mailer_sender.placeholder');
        }

        return new Diagnostic(
            'mailer_sender',
            DiagnosticState::Ok,
            'account.system_status.mailer_sender.configured',
            ['%address%' => $this->mailerFromAddress],
        );
    }
}
