<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\GeminiService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GeminiServiceTest extends TestCase
{
    public function testDiagnoseBuildsValidPayloadKeysAndInlineData(): void
    {
        $mock = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertStringContainsString('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent', $url);

            $payload = $options['json'] ?? null;
            self::assertIsArray($payload);

            self::assertArrayHasKey('generationConfig', $payload);
            self::assertArrayHasKey('responseMimeType', $payload['generationConfig']);
            self::assertSame('application/json', $payload['generationConfig']['responseMimeType']);

            // cachedContent debe omitirse si no hay cacheId
            self::assertArrayNotHasKey('cachedContent', $payload);

            $parts = $payload['contents'][0]['parts'] ?? null;
            self::assertIsArray($parts);

            // Primer part: texto
            self::assertSame('text', array_key_first($parts[0]));

            // Audio data url -> mime_type normalizado
            $audio = $parts[1]['inline_data'] ?? null;
            self::assertIsArray($audio);
            self::assertSame('audio/mpeg', $audio['mime_type']);
            self::assertSame('AAAA', $audio['data']);

            return new MockResponse(json_encode([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => '{"title":"x","description":"y","summary_text":"z","category":"DIY","sub_category":"General","risk_level":"LOW","estimated_price_min":0,"estimated_price_max":0,"urgency":"SCHEDULED","schedule_intent":null}'
                        ]],
                    ],
                ]],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });

        $service = new GeminiService('test-key', 'gemini-2.5-flash', $mock, new NullLogger());

        $result = $service->diagnose(
            description: 'hola',
            image: null,
            audio: 'data:audio/mp3;base64,AAAA',
            video: null,
            location: null,
            cacheId: null
        );

        self::assertSame('DIY', $result['category']);
    }
}

