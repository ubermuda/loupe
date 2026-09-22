<?php

declare(strict_types=1);

namespace App\Forge;

/** The delivery did not come from the forge it claims. Nothing in it is trustworthy. */
final class InvalidForgeSignature extends \RuntimeException
{
}
