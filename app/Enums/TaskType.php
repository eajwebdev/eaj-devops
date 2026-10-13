<?php

namespace App\Enums;

enum TaskType: string
{
    case Feature = 'feature';
    case Bug = 'bug';
    case Improvement = 'improvement';
    case Chore = 'chore';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
