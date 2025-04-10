<?php

namespace App\Services;

use OpenAI;
use Illuminate\Support\Facades\Log;

class AISummaryService
{
    public static function generateSummary($rawText)
    {
        $client = OpenAI::client(config('openai.api_key'));

        $prompt = "Analyze the following health report and return a JSON object with:
        1. diagnosis (short title),
        2. key_findings (bullet points),
        3. recommendations (bullet points),
        4. confidence_score (0-100%)

Text: {$rawText}";

        try {
            // Step 1: Get the summary in English
            $response = $client->chat()->create([
                'model' => 'gpt-4',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a medical expert AI assistant.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

            $summary = json_decode($response->choices[0]->message->content, true);

            // Step 2: Translate the summary to Hindi
            $translatePrompt = "Translate the following medical summary to Hindi:\n\n" . json_encode($summary, JSON_PRETTY_PRINT);

            $translation = $client->chat()->create([
                'model' => 'gpt-4',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a professional Hindi translator.'],
                    ['role' => 'user', 'content' => $translatePrompt],
                ],
            ]);

            $hindiVersion = $translation->choices[0]->message->content;

            // Step 3: Return full summary including Hindi version
            return [
                ...$summary,
                'hindi_version' => $hindiVersion,
            ];
        } catch (\Exception $e) {
            Log::error("AI summary or translation failed: " . $e->getMessage());
            return null;
        }
    }
}
