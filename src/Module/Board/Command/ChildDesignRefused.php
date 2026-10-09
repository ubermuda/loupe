<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

/** Thrown when the workflow cannot carry out the choice an agent stated for a child card. The message is fit for an agent. */
final class ChildDesignRefused extends \DomainException
{
}
