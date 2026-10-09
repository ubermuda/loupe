<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** Names of action parameters that more than one module reads. */
final class ParameterNames
{
    public const string DOCUMENT_TAG = 'document.tag';

    public const string DOCUMENT_STATUS = 'document.status';

    public const string PROMPT = 'prompt';

    public const string PROMPT_PATTERN = '/^[a-z][a-z0-9-]{0,39}$/D';
}
