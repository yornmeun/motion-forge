<?php

namespace App\Notifications;

use App\Models\GenerationJob;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProductionFailed extends Notification
{
    public function __construct(public GenerationJob $job) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return ['project_id' => $this->job->project_id, 'job_id' => $this->job->id, 'type' => $this->job->type, 'scene_id' => $this->job->scene_id, 'message' => mb_substr((string) $this->job->error, 0, 300)];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('MotionForge: '.str_replace('_', ' ', $this->job->type).' failed')
            ->line('Project: '.$this->job->project?->title)
            ->line(mb_substr((string) $this->job->error, 0, 300))
            ->action('Open project', route('projects.show', $this->job->project_id));
    }
}
