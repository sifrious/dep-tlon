<?php

declare(strict_types=1);

namespace Tlon\Shell;

use Tlon\Core\{Recorded, Rejected};

function execute(object $action): int
{
    return match (true) {
        $action instanceof Recorded => report_recorded($action),
        $action instanceof Rejected => report_rejected($action),
        default => report_unknown_action(),
    };
}

function report_recorded(Recorded $action): int
{
    $detail = $action->detail === [] ? '' : ' ' . json_encode($action->detail);
    emit('  ' . $action->what . $detail);

    return 0;
}

function report_rejected(Rejected $action): int
{
    emit_error('  refused (' . $action->code . '): ' . $action->reason);

    return 1;
}

function report_unknown_action(): int
{
    emit_error('  the shell does not know how to perform that action');

    return 1;
}
