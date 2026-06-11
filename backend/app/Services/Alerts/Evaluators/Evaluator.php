<?php

declare(strict_types=1);

namespace App\Services\Alerts\Evaluators;

use App\Models\AlertRule;
use App\Services\Alerts\AlertEventDraft;

interface Evaluator
{
    /**
     * Evalúa la regla y devuelve los drafts a persistir. Si no hay
     * incumplimientos, devuelve [].
     *
     * @return list<AlertEventDraft>
     */
    public function evaluate(AlertRule $rule): array;
}
