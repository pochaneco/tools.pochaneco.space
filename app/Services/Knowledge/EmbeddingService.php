<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use Laravel\Ai\Embeddings;

/**
 * Thin wrapper around the Laravel AI embeddings API. Centralising it here
 * gives us one place to apply provider/model overrides and ensures that
 * both the indexer and the search service use the exact same embedding
 * shape (model id + dimension count) — otherwise cosine similarity
 * between stored chunks and runtime queries would be meaningless.
 */
class EmbeddingService
{
    /**
     * @param  array<int, string>  $inputs
     * @return array{vectors: array<int, array<int, float>>, model: string, dims: int}
     */
    public function embed(array $inputs): array
    {
        $inputs = array_values(array_filter(
            array_map('strval', $inputs),
            fn (string $s) => $s !== '',
        ));

        if ($inputs === []) {
            return [
                'vectors' => [],
                'model' => $this->model(),
                'dims' => 0,
            ];
        }

        $provider = $this->provider();
        $model = $this->model();

        $response = Embeddings::for($inputs)->generate(
            provider: $provider,
            model: $model,
        );

        $vectors = [];
        foreach ($response->embeddings as $embedding) {
            // The SDK exposes each embedding as a value object with a
            // ->vector property — normalise it to a plain float array so
            // callers don't have to depend on internal SDK shape.
            $vector = is_object($embedding) && property_exists($embedding, 'vector')
                ? $embedding->vector
                : $embedding;

            $vectors[] = array_map('floatval', (array) $vector);
        }

        $dims = $vectors === [] ? 0 : count($vectors[0]);

        return [
            'vectors' => $vectors,
            'model' => $model,
            'dims' => $dims,
        ];
    }

    private function provider(): string
    {
        $configured = config('ai.default_for_embeddings');

        return is_string($configured) && $configured !== '' ? $configured : 'sakura';
    }

    private function model(): string
    {
        $configured = config('ai.embedding_model');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        // Second preference: provider-specific config key, if declared.
        $provider = $this->provider();
        $providerDefault = config("ai.providers.{$provider}.models.embeddings.default");
        if (is_string($providerDefault) && $providerDefault !== '') {
            return $providerDefault;
        }

        return 'multilingual-e5-large';
    }
}
