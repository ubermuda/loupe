<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** Why a bridge command exists. The bridge words the prompt of a resume from it. */
enum BridgeCommandCause: string
{
    case Person = 'person';

    /** The owner closed an ask of the session, and Loupe asks for the resume. */
    case AskClosed = 'ask-closed';
}
