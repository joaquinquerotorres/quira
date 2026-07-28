<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PredictMediaFetcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PredictMediaFetcherTest extends TestCase
{
    public function testRejectsNonSupabaseUrl(): void
    {
        $fetcher = new PredictMediaFetcher(
            'https://example.supabase.co',
            'requests',
            new MockHttpClient(),
            new NullLogger(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('URL de media no permitida');
        $fetcher->assertAllowedPublicUrl('https://evil.example/video.mp4');
    }

    public function testRejectsWrongBucket(): void
    {
        $fetcher = new PredictMediaFetcher(
            'https://example.supabase.co',
            'requests',
            new MockHttpClient(),
            new NullLogger(),
        );

        $this->expectException(\RuntimeException::class);
        $fetcher->assertAllowedPublicUrl(
            'https://example.supabase.co/storage/v1/object/public/avatars/1.jpg'
        );
    }

    public function testFetchesAllowedUrlAsDataUrl(): void
    {
        $binary = 'fake-image-bytes';
        $client = new MockHttpClient([
            new MockResponse($binary, [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'image/jpeg'],
            ]),
        ]);

        $fetcher = new PredictMediaFetcher(
            'https://example.supabase.co',
            'requests',
            $client,
            new NullLogger(),
        );

        $url = 'https://example.supabase.co/storage/v1/object/public/requests/1_photo_abc.jpg';
        $dataUrl = $fetcher->fetchAsDataUrl($url, 'image');

        $this->assertStringStartsWith('data:image/jpeg;base64,', $dataUrl);
        $this->assertSame(base64_encode($binary), substr($dataUrl, strlen('data:image/jpeg;base64,')));
    }

    public function testRejectsEmptyDownload(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'video/mp4'],
            ]),
        ]);

        $fetcher = new PredictMediaFetcher(
            'https://example.supabase.co',
            'requests',
            $client,
            new NullLogger(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('vacío');
        $fetcher->fetchAsDataUrl(
            'https://example.supabase.co/storage/v1/object/public/requests/1_video.mp4',
            'video'
        );
    }
}
