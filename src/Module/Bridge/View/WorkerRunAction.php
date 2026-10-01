<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

/** The control a worker run row offers a person. */
enum WorkerRunAction: string
{
    case Resume = 'resume';
    case Stop = 'stop';
    case Cancel = 'cancel';

    public function translationKey(): string
    {
        return 'bridge.worker_runs.control.'.$this->value;
    }
}
