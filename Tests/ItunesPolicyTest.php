<?php

namespace Omnisong\Itunes\Tests;

use Omnisense\Contracts\Policy\UsagePolicy;
use Omnisong\Exception\RateLimitedException;
use Omnisong\Exception\UsageLimitReachedException;
use Omnisong\Itunes\ItunesCatalog;
use Omnisong\Itunes\ItunesCatalogFactory;
use Omnisong\Registry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The iTunes Search API's declared policy (policy.json, as Apple's page
 * read on 2026-11-10): about 20 calls a minute, counted with the call over
 * it never made; a 403's Retry-After holding the next calls; the credit.
 */
final class ItunesPolicyTest extends TestCase
{
    private int $sent = 0;

    /** @param list<MockResponse> $responses */
    private function registry(array $responses = []): Registry
    {
        $http = new MockHttpClient(function () use (&$responses): MockResponse {
            ++$this->sent;

            return array_shift($responses) ?? new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/search-albums.json'));
        });

        return new Registry([new ItunesCatalogFactory($http)], ['itunes' => ['factory' => 'itunes', 'options' => ['country' => 'de']]]);
    }

    public function testThePolicyFileSaysTwentyAMinuteAndTheCredit(): void
    {
        $policy = UsagePolicy::fromFile(ItunesCatalog::POLICY);

        self::assertSame('itunes', $policy->provider);
        self::assertSame([20, 'minute'], [$policy->rateLimits[0]->max ?? null, $policy->rateLimits[0]->window->value ?? null]);
        self::assertSame('Provided courtesy of iTunes', $policy->rights->attribution->text);
        self::assertTrue($policy->rights->attribution->required);
        self::assertSame('forbidden', $policy->rights->storage->value, 'previews streamed only');
        self::assertFalse($policy->requirements->key);
        self::assertSame('2026-11-10', $policy->provenance->verifiedAt);
        self::assertStringContainsString('"omnisense": {"policy": "policy.json"}', (string) file_get_contents(__DIR__.'/../composer.json'), 'declared for omnisense-policies');
    }

    public function testTheTwentyFirstSearchOfTheMinuteIsNeverSent(): void
    {
        $registry = $this->registry();
        $catalog = $registry->guarded('itunes');
        for ($i = 0; $i < 20; ++$i) {
            $catalog->releases('Artist '.$i);
        }

        try {
            $catalog->releases('Artist 20');
            self::fail('Over Apple\'s 20 a minute.');
        } catch (RateLimitedException $e) {
            self::assertInstanceOf(UsageLimitReachedException::class, $e->getPrevious());
        }
        self::assertSame(20, $this->sent);
    }

    public function testA403sRetryAfterHoldsTheNextCallsWithNothingSent(): void
    {
        $registry = $this->registry([new MockResponse('', ['http_code' => 403, 'response_headers' => ['retry-after' => '60']])]);

        foreach (['first', 'second'] as $artist) {
            try {
                $registry->guarded('itunes')->releases($artist);
                self::fail('Told to slow down.');
            } catch (RateLimitedException $e) {
                self::assertGreaterThanOrEqual(59, $e->retryAfter);
            }
        }
        self::assertSame(1, $this->sent);
    }
}
