<?php

declare(strict_types=1);

namespace App\Module\Project\Security;

/**
 * Why a request resolved to no project. The caller turns this into the message
 * its own protocol uses, so no sentence is built here.
 */
enum ProjectRefusal: string
{
    case NoCredential = 'no-credential';
    case WrongScope = 'wrong-scope';
    case HeaderMalformed = 'header-malformed';
    case HeaderNotCovered = 'header-not-covered';
    case SeveralProjectsAndNoHeader = 'several-projects-and-no-header';
    case Unbound = 'unbound';
}
