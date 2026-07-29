<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\GeminiService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GeminiServiceCreateCacheTest extends TestCase
{
    public function testCreateCachePostsCatalogAndModel(): void
    {
        $csv = "Categoria,Subcategoria,Zona,Precio_Min,Precio_Max,Unidad,Complejidad\nFontanería,Test,Córdoba,1000,2000,Unidad,Baja\n";

        $mock = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertStringContainsString('cachedContents', $url);

            $payload = [];
            if (isset($options['json']) && \is_array($options['json'])) {
                $payload = $options['json'];
            } elseif (isset($options['body']) && \is_string($options['body'])) {
                $decoded = json_decode($options['body'], true);
                $payload = \is_array($decoded) ? $decoded : [];
            }

            self::assertSame('models/gemini-2.5-flash', $payload['model'] ?? null);
            self::assertSame('3600s', $payload['ttl'] ?? null);
            $text = $payload['contents'][0]['parts'][0]['text'] ?? '';
            self::assertStringContainsString('Fontanería,Test,Córdoba,1000,2000,Unidad,Baja', $text);
            self::assertStringContainsString('REGLAS DE PRECIOS', $text);

            return new MockResponse(json_encode([
                'name' => 'cachedContents/test-cache-id',
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });

        $service = new GeminiService('test-key', 'gemini-2.5-flash', $mock, new NullLogger());
        $id = $service->createCache($csv, 'gemini-2.5-flash');
        self::assertSame('cachedContents/test-cache-id', $id);
    }

    public function testCreateCacheReturnsNullOnHttpError(): void
    {
        $mock = new MockHttpClient([
            new MockResponse('boom', ['http_code' => 500]),
        ]);
        $service = new GeminiService('test-key', 'gemini-2.5-flash', $mock, new NullLogger());
        self::assertNull($service->createCache("Categoria,Subcategoria,Zona,Precio_Min,Precio_Max,Unidad,Complejidad\n"));
    }
}
