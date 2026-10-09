<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

enum ParameterType: string
{
    case String = 'string';
    case Int = 'int';
    case Slot = 'slot';
    /** An expression over conditions, such as the release of a pause. */
    case Expression = 'expression';
    /** A non-empty list of non-empty strings. */
    case List = 'list';
    /** A map with a tag and optionally a document status. It gives the parameters "document.tag" and "document.status". */
    case Document = 'document';
    /** A non-empty list of ask options, each with a label and the actions that run when the owner picks it. */
    case Options = 'options';
}
