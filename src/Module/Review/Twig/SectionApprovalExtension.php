<?php

declare(strict_types=1);

namespace App\Module\Review\Twig;

use App\Module\Review\Entity\Document;
use App\Module\Review\Service\SectionControlInjector;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Puts an approval control beside every heading of the rendered document.
 *
 * The markup stays in a template rather than being assembled in PHP, so the
 * route, the CSRF token and the translated name read the way they do everywhere
 * else. This class only renders one control per section and hands them to
 * {@see SectionControlInjector}, which does the string surgery.
 */
final class SectionApprovalExtension extends AbstractExtension
{
    public function __construct(
        private readonly SectionControlInjector $injector,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('section_approval_controls', $this->controls(...), ['needs_environment' => true]),
        ];
    }

    /**
     * An empty $rows list returns the HTML untouched, which is what an older
     * version renders: its controls would post onto the current one.
     *
     * @param list<array{headingId: string, level: int, label: string, approved: bool}> $rows
     */
    public function controls(Environment $twig, string $html, array $rows, Document $document, int $versionNumber): string
    {
        $controls = [];
        foreach ($rows as $row) {
            // Trimmed because `spaceless` clears whitespace BETWEEN tags only,
            // and a leading newline here would reach the pane's text.
            $controls[$row['headingId']] = trim($twig->render('@Review/_section_approval_control.html.twig', [
                'document' => $document,
                'headingId' => $row['headingId'],
                'label' => $row['label'],
                'approved' => $row['approved'],
                'versionNumber' => $versionNumber,
            ]));
        }

        return $this->injector->inject($html, $controls);
    }
}
