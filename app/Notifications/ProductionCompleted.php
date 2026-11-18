<?php

namespace App\Notifications;

use App\Models\Project;
use Illuminate\Notifications\Notification;

class ProductionCompleted extends Notification
{
    public function __construct(public Project $project, public bool $qcPassed) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['project_id' => $this->project->id, 'message' => $this->qcPassed ? 'Final video ready.' : 'Video rendered with QC issues to review.'];
    }
}
