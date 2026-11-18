<?php

namespace App\Contracts;

use App\Services\AI\Data\AIResult;

/**
 * Text / structured-output contract every provider implements. Capability-specific media
 * operations live in separate interfaces (VideoGeneratorInterface, ...), so a text model is
 * never treated as a media generator.
 *
 * $messages = ['system' => ?string, 'messages' => [['role' => 'user'|'assistant', 'content' => string], ...]]
 */
interface AIProviderInterface
{
    public function slug(): string;

    /** Performs a real, cheap authenticated request. Throws AIProviderException when the key is not usable. */
    public function testConnection(string $apiKey): array;

    /** @return array<int, array{id: string, name?: string}> */
    public function listModels(string $apiKey): array;

    public function generateText(string $apiKey, string $model, array $messages, array $options = []): AIResult;

    /** Returns an AIResult whose ->json holds the decoded object validated by the provider against $schema where supported. */
    public function generateStructured(string $apiKey, string $model, array $messages, array $schema, string $schemaName = 'motionforge_result', array $options = []): AIResult;
}
