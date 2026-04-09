<?php
/**
 * GeminiService
 *
 * HTTP client wrapper for the Google Gemini generativeLanguage API.
 * Supports both plain-text and JSON-mode responses. Uses cURL with a
 * retry policy for transient 429/5xx failures.
 *
 * Usage:
 *   $gemini = new GeminiService($config);
 *   $text   = $gemini->generate(SummaryPrompt::PROMPT, $sourceText);
 *   $data   = $gemini->generateJson($structuredPrompt, $sourceText);
 */

declare(strict_types=1);

namespace StratFlow\Services;

class GeminiService
{
    // ===========================
    // CONFIG
    // ===========================

    private string $apiKey;
    private string $model;
    private string $openaiKey;

    private const API_BASE    = 'https://generativelanguage.googleapis.com/v1beta/models';
    private const MAX_RETRIES = 2;
    private const RETRY_SLEEP = 2;    // seconds between retries

    public function __construct(array $config)
    {
        $this->apiKey    = $config['gemini']['api_key'];
        $this->model     = $config['gemini']['model'];
        $this->openaiKey = $config['openai']['api_key'] ?? '';
    }

    // ===========================
    // PUBLIC INTERFACE
    // ===========================

    /**
     * Generate a plain-text response from Gemini.
     *
     * @param string $prompt Instruction / system prompt
     * @param string $input  Source content to act on
     * @return string        Generated text from the model
     * @throws \RuntimeException On API error or unexpected response shape
     */
    public function generate(string $prompt, string $input): string
    {
        // Try Gemini first, fall back to OpenAI on ANY failure
        try {
            $url  = $this->buildUrl('generateContent');
            $body = $this->buildBody($prompt, $input);
            $response = $this->makeRequest($url, $body);
            return $response['candidates'][0]['content']['parts'][0]['text']
                ?? throw new \RuntimeException('Unexpected Gemini response format');
        } catch (\Throwable $e) {
            if ($this->openaiKey !== '') {
                error_log('[StratFlow] Gemini failed (' . $e->getMessage() . '), falling back to OpenAI');
                return $this->openaiGenerate($prompt, $input);
            }
            throw $e;
        }
    }

    /**
     * Generate a JSON-mode response from Gemini and decode it.
     *
     * @param string $prompt Instruction / system prompt (should request JSON output)
     * @param string $input  Source content to act on
     * @return array         Decoded JSON array from the model
     * @throws \RuntimeException On API error, unexpected shape, or invalid JSON
     */
    public function generateJson(string $prompt, string $input): array
    {
        try {
            $url  = $this->buildUrl('generateContent');
            $body = $this->buildBody($prompt, $input, responseMimeType: 'application/json');
            $response = $this->makeRequest($url, $body);
            $text = $response['candidates'][0]['content']['parts'][0]['text']
                ?? throw new \RuntimeException('Unexpected Gemini response format');
        } catch (\Throwable $e) {
            if ($this->openaiKey !== '') {
                error_log('[StratFlow] Gemini failed (' . $e->getMessage() . '), falling back to OpenAI for JSON');
                $text = $this->openaiGenerate($prompt . "\n\nRespond with valid JSON only. No markdown fences.", $input);
            } else {
                throw $e;
            }
        }

        $text = trim($text);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
        }

        $decoded = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('AI returned invalid JSON: ' . json_last_error_msg());
        }

        return $decoded;
    }

    // ===========================
    // HELPERS
    // ===========================

    /**
     * Build the full Gemini API endpoint URL.
     *
     * @param string $action API action (e.g. 'generateContent')
     * @return string        Fully-qualified URL with API key query param
     */
    private function buildUrl(string $action): string
    {
        return self::API_BASE . "/{$this->model}:{$action}?key={$this->apiKey}";
    }

    /**
     * Assemble the request body for a generateContent call.
     *
     * @param string      $prompt           Instruction prepended to input
     * @param string      $input            Source content appended after prompt
     * @param string|null $responseMimeType Optional MIME type for JSON mode
     * @return array                        Body array ready for json_encode
     */
    private function buildBody(string $prompt, string $input, ?string $responseMimeType = null): array
    {
        $body = [
            'contents' => [
                ['parts' => [['text' => $prompt . "\n\n---\n\n" . $input]]]
            ],
            'generationConfig' => [
                'temperature'     => 0.4,
                'maxOutputTokens' => 4096,
            ],
        ];

        if ($responseMimeType !== null) {
            $body['generationConfig']['responseMimeType'] = $responseMimeType;
        }

        return $body;
    }

    /**
     * Execute an HTTP POST to the Gemini API with retry on transient errors.
     *
     * Retries on HTTP 429 (rate limit) and 5xx (server error). Throws on
     * cURL failure, non-retryable HTTP error, or exhausted retries.
     *
     * @param string $url  Fully-qualified API endpoint
     * @param array  $body Request payload (will be JSON-encoded)
     * @return array       Decoded JSON response body
     * @throws \RuntimeException On cURL error, API error, or max retries exceeded
     */
    private function makeRequest(string $url, array $body): array
    {
        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST          => true,
                CURLOPT_POSTFIELDS    => json_encode($body),
                CURLOPT_HTTPHEADER    => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT       => 60,
            ]);

            $responseStr = curl_exec($ch);
            $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError   = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                throw new \RuntimeException('Gemini API cURL error: ' . $curlError);
            }

            if (($httpCode === 429 || $httpCode >= 500) && $attempt < self::MAX_RETRIES - 1) {
                sleep(self::RETRY_SLEEP);
                continue;
            }

            $response = json_decode($responseStr, true);

            if ($httpCode !== 200) {
                $errorMsg = $response['error']['message'] ?? "HTTP {$httpCode}";
                throw new \RuntimeException('Gemini API error: ' . $errorMsg);
            }

            return $response;
        }

        throw new \RuntimeException('Gemini API: max retries exceeded');
    }

    // ===========================
    // OPENAI FALLBACK
    // ===========================

    /**
     * Generate text via OpenAI Chat Completions API (GPT-4o-mini).
     * Used as automatic fallback when Gemini rate-limits.
     */
    private function openaiGenerate(string $prompt, string $input): string
    {
        $payload = json_encode([
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => $input],
            ],
            'temperature' => 0.4,
            'max_tokens' => 4096,
        ]);

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->openaiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);

        $responseStr = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \RuntimeException('OpenAI cURL error: ' . $curlError);
        }

        $response = json_decode($responseStr, true);

        if ($httpCode !== 200) {
            $errorMsg = $response['error']['message'] ?? "HTTP {$httpCode}";
            throw new \RuntimeException('OpenAI API error: ' . $errorMsg);
        }

        return $response['choices'][0]['message']['content']
            ?? throw new \RuntimeException('Unexpected OpenAI response format');
    }
}
