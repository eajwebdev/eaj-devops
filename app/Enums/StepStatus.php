<?php

namespace App\Enums;

enum StepStatus: string
{
    case Pending = 'PENDING';
    case Running = 'RUNNING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';
}
