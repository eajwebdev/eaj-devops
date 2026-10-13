<?php

namespace App\Enums;

enum WorkspaceStatus: string
{
    case Creating = 'creating';
    case Ready = 'ready';
    case Removing = 'removing';
    case Removed = 'removed';
    case Failed = 'failed';
}
