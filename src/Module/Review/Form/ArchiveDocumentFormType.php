<?php

declare(strict_types=1);

namespace App\Module\Review\Form;

use Symfony\Component\Form\AbstractType;

/**
 * Carries an archive or a restore submission. Both are the same fieldless
 * confirmation — the document is named by the URL and the direction by the
 * route — so one type serves both rather than two empty classes that differ
 * only in name.
 *
 * Fieldless does not mean pointless: the form component issues and checks the
 * CSRF token, which is what this replaces a hand-registered stateless token id
 * with.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class ArchiveDocumentFormType extends AbstractType
{
}
