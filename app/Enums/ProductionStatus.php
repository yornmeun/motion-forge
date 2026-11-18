<?php

namespace App\Enums;

enum ProductionStatus: string
{
    case Draft = 'draft';
    case AwaitingPackage = 'awaiting_package';
    case PackageApproved = 'package_approved';
    case Planning = 'planning';
    case Planned = 'planned';
    case Generating = 'generating';
    case ReadyForReview = 'ready_for_review';
    case Rendering = 'rendering';
    case QualityControl = 'quality_control';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }

    public function tone(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Failed => 'error',
            self::Planning, self::Generating, self::Rendering, self::QualityControl => 'accent',
            self::ReadyForReview, self::PackageApproved, self::Planned => 'primary',
            default => 'muted',
        };
    }
}
