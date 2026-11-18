<?php

namespace App\Contracts;

use App\Services\AI\Data\VideoJobStatus;

/** Asynchronous native video generation (submit → poll → retrieve, cancel where supported). */
interface VideoGeneratorInterface
{
    /**
     * @param array{prompt: string, aspect_ratio: string, duration: int, resolution?: string, reference_images?: array<int, array{mime: string, data: string}>} $request
     */
    public function submitVideo(string $apiKey, string $model, array $request): VideoJobStatus;

    public function pollVideo(string $apiKey, string $model, string $operationId): VideoJobStatus;

    public function cancelVideo(string $apiKey, string $operationId): bool;
}
