<?php

namespace Omnisong\Itunes\Tests;

use Omnisong\Exception\NotSupportedException;
use Omnisong\Exception\ProviderException;
use Omnisong\Exception\RateLimitedException;
use Omnisong\Exception\UnavailableException;
use Omnisong\Itunes\ItunesCatalogFactory;
use Omnisong\Model\Reference;
use Omnisong\Model\Release;
use Omnisong\Platform;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from the API on 2026-11-14, store "de" (Carlos Kleiber's
 * Beethoven 5 & 7 on Deutsche Grammophon): lookup-upc.json
 * (lookup?upc=028948637751&entity=song, the same answer as lookup?id=1644892939),
 * lookup-track.json (lookup?id=1644892943: a track alone), search-albums.json
 * (search?term=Carlos+Kleiber&entity=album&attribute=artistTerm&limit=3).
 */
final class ItunesCatalogTest extends TestCase
{
    private const UPC = '028948637751';
    private const EMPTY = "\n\n\n{\n \"resultCount\":0,\n \"results\": []\n}\n\n\n";

    /** @var list<array{string, array<mixed>}> the path and query of each call */
    private array $calls = [];

    /** @param array<string, mixed> $options */
    private function catalog(?MockResponse $response = null, array $options = ['country' => 'de']): \Omnisong\Catalog\CatalogInterface
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $this->calls[] = [$path, $query];
            if ($response) {
                return $response;
            }
            $fixture = match (true) {
                '/search' === $path && 'Carlos Kleiber' === $query['term'] => 'search-albums.json',
                '/lookup' === $path && self::UPC === ($query['upc'] ?? null), '/lookup' === $path && '1644892939' === ($query['id'] ?? null) => 'lookup-upc.json',
                '/lookup' === $path && ('1644892943' === ($query['id'] ?? null) || isset($query['isrc'])) => 'lookup-track.json',
                default => null,
            };

            // As Apple serves it: JSON under a text/javascript content type.
            return new MockResponse($fixture ? (string) file_get_contents(__DIR__.'/Fixtures/'.$fixture) : self::EMPTY, ['response_headers' => ['content-type' => 'text/javascript; charset=utf-8']]);
        });

        return (new ItunesCatalogFactory($http))->create($options);
    }

    public function testAUpcIsTheReleaseWithItsTracksAndTheirPreviews(): void
    {
        $release = $this->catalog()->release(Reference::upc(self::UPC));

        self::assertNotNull($release);
        self::assertNotNull($release->label);
        self::assertNotNull($release->links);
        self::assertSame('Beethoven: Symphonies Nos. 5 & 7', $release->title);
        self::assertStringStartsWith('Wiener Philharmoniker & Carlos Kleiber', (string) $release->artist);
        self::assertSame(self::UPC, $release->upc);
        self::assertSame(Release::ALBUM, $release->type);
        self::assertSame('1995-02-20', $release->releasedAt?->format('Y-m-d'));
        self::assertSame('℗ 1995 Deutsche Grammophon GmbH, Berlin', $release->copyright);
        self::assertSame('Deutsche Grammophon GmbH, Berlin', $release->label->name, 'who the ℗ line names');
        self::assertNull($release->label->url, 'the API knows no address for it');
        self::assertStringEndsWith('/22UMGIM89320.rgb.jpg/1200x1200bb.jpg', (string) $release->coverUrl);
        self::assertSame('Klassik', $release->genre);
        self::assertSame(8, $release->trackCount);
        self::assertSame(['itunes' => '1644892939', 'apple_music' => '1644892939'], $release->ids);
        self::assertSame('https://music.apple.com/de/album/beethoven-symphonies-nos-5-7/1644892939', $release->links->get(Platform::APPLE_MUSIC)?->url, 'without ?uo=4');
        self::assertSame('1644892939', $release->links->get(Platform::APPLE_MUSIC)->id);
        self::assertSame('DE', $release->links->get(Platform::APPLE_MUSIC)->country);

        self::assertCount(8, $release->tracks);
        self::assertSame(range(1, 8), array_map(static fn ($t) => $t->position, $release->tracks));
        $first = $release->tracks[0];
        self::assertSame('Symphony No. 5 in C Minor, Op. 67: I. Allegro con brio', $first->title);
        self::assertSame(1, $first->disc);
        self::assertSame(442, $first->duration, 'seconds');
        self::assertNull($first->isrc, 'not given by the Search API');
        self::assertNull($first->composer);
        self::assertSame('Wiener Philharmoniker & Carlos Kleiber', $first->artist);
        self::assertSame(['itunes' => '1644892943'], $first->ids);
        self::assertSame('https://music.apple.com/de/album/symphony-no-5-in-c-minor-op-67-i-allegro-con-brio/1644892939?i=1644892943', $first->url);
        foreach ($release->tracks as $track) {
            self::assertStringStartsWith('https://audio-ssl.itunes.apple.com/', (string) $track->previewUrl);
        }

        self::assertSame(['/lookup', ['upc' => self::UPC, 'entity' => 'song', 'country' => 'de', 'limit' => '200']], $this->calls[0]);
    }

    public function testAnAppleMusicUrlOrItsIdIsTheSameRelease(): void
    {
        $catalog = $this->catalog();
        $references = [
            Reference::url('https://music.apple.com/de/album/beethoven-symphonies-nos-5-7/1644892939?uo=4'),
            Reference::url('https://geo.music.apple.com/de/album/_/1644892939?mt=1&app=music&ls=1'),
            Reference::id(Platform::ITUNES, '1644892939'),
            Reference::id(Platform::APPLE_MUSIC, '1644892939'),
        ];
        foreach ($references as $reference) {
            $release = $catalog->release($reference);
            self::assertNotNull($release);
            self::assertSame('Beethoven: Symphonies Nos. 5 & 7', $release->title);
            self::assertCount(8, $release->tracks);
            self::assertNull($release->upc, 'only a UPC asked for is known: the API does not give it');
        }
        self::assertCount(1, $this->calls, 'the same question is asked once');
        self::assertSame('1644892939', $this->calls[0][1]['id']);
    }

    public function testATracksIdOrAnIsrcLeadsToItsAlbum(): void
    {
        $catalog = $this->catalog();
        $release = $catalog->release(Reference::id(Platform::ITUNES, '1644892943', Reference::SONG));

        self::assertNotNull($release);
        self::assertSame('Beethoven: Symphonies Nos. 5 & 7', $release->title);
        self::assertCount(8, $release->tracks);
        self::assertSame(['1644892943', '1644892939'], array_map(static fn ($c) => $c[1]['id'], $this->calls), 'the track, then its collection');

        $asked = \count($this->calls);
        self::assertCount(8, $catalog->release(Reference::isrc('QZ-ZZZ-25-00001'))->tracks ?? []);
        self::assertSame('QZZZZ2500001', $this->calls[$asked][1]['isrc']);
        self::assertArrayNotHasKey('isrc', $this->calls[$asked + 1][1]);
    }

    public function testTheLinksAreApples(): void
    {
        $catalog = $this->catalog();
        $links = $catalog->links(Reference::upc(self::UPC));

        self::assertSame(['apple_music', 'itunes'], array_keys($links->toArray()));
        self::assertSame('https://music.apple.com/de/album/beethoven-symphonies-nos-5-7/1644892939?app=itunes', $links->get(Platform::ITUNES)?->url);
        self::assertCount(0, $catalog->links(Reference::upc('4000000000001')), 'unknown: none');
        self::assertNull($catalog->release(Reference::upc('4000000000001')));
    }

    public function testAnArtistsAlbumsNewestFirst(): void
    {
        $releases = $this->catalog()->releases('Carlos Kleiber', 3);

        self::assertCount(3, $releases);
        self::assertSame(['2010', '1995', '1979'], array_map(static fn (Release $r) => $r->releasedAt?->format('Y'), $releases), 'newest first');
        self::assertSame('Beethoven: Symphonies Nos. 5 & 7', $releases[1]->title);
        self::assertSame('Wiener Philharmoniker & Carlos Kleiber', $releases[1]->artist);
        self::assertSame('Deutsche Grammophon GmbH, Berlin', $releases[1]->label?->name);
        self::assertSame([], $releases[0]->tracks);
        self::assertSame(['/search', ['term' => 'Carlos Kleiber', 'media' => 'music', 'entity' => 'album', 'attribute' => 'artistTerm', 'country' => 'de', 'limit' => '3']], $this->calls[0]);

        $rows = [
            ['wrapperType' => 'collection', 'collectionId' => 1, 'collectionName' => 'Older', 'releaseDate' => '2019-01-14T08:00:00Z', 'copyright' => '℗ Shaboom 2016'],
            ['wrapperType' => 'collection', 'collectionId' => 2, 'collectionName' => 'Newer - Single', 'releaseDate' => '2024-05-01T07:00:00Z', 'copyright' => 'All rights reserved'],
            ['wrapperType' => 'collection', 'collectionId' => 3, 'collectionName' => 'Between - EP', 'releaseDate' => '2021-05-01T07:00:00Z', 'copyright' => '℗ 2014 2017 Shifting Sounds'],
        ];
        $releases = $this->catalog(new MockResponse((string) json_encode(['resultCount' => 3, 'results' => $rows])), ['country' => null])->releases('Anyone', 50, 'FR');
        self::assertSame(['Newer', 'Between', 'Older'], array_map(static fn (Release $r) => $r->title, $releases));
        self::assertSame([Release::SINGLE, Release::EP, Release::ALBUM], array_map(static fn (Release $r) => $r->type, $releases));
        self::assertSame([null, 'Shifting Sounds', 'Shaboom'], array_map(static fn (Release $r) => $r->label?->name, $releases));
        self::assertSame('fr', $this->calls[1][1]['country'], 'the country asked for, over the configured one');
    }

    public function testWhatIsNotApplesIsNotRead(): void
    {
        $this->expectException(NotSupportedException::class);
        $this->catalog()->release(Reference::url('https://open.spotify.com/album/4aawyAB9vmqN3uQ7FjRGTy'));
    }

    public function testToldToSlowDownOrDownIsUnavailable(): void
    {
        foreach ([403, 429] as $status) {
            try {
                $this->catalog(new MockResponse('', ['http_code' => $status, 'response_headers' => ['Retry-After' => '30']]))->release(Reference::upc(self::UPC));
                self::fail('RateLimitedException expected');
            } catch (RateLimitedException $e) {
                self::assertSame(30, $e->retryAfter);
            }
        }
        foreach ([new MockResponse('', ['http_code' => 503]), new MockResponse('', ['error' => 'Connection timed out']), new MockResponse('<html>Maintenance</html>')] as $response) {
            try {
                $this->catalog($response)->releases('Carlos Kleiber');
                self::fail('UnavailableException expected');
            } catch (UnavailableException $e) {
                self::assertSame('itunes', $e->catalog);
            }
        }

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('[itunes] HTTP 400 Invalid value(s) for key(s): [country]');
        $this->catalog(new MockResponse('{"errorMessage":"Invalid value(s) for key(s): [country]", "queryParameters":{}}', ['http_code' => 400]))->release(Reference::upc(self::UPC));
    }

    /** A track whose duration is no number (a list, a word): the answer is not one the catalogue reads, a ProviderException, not a TypeError. */
    public function testATrackWhoseDurationIsNotANumberIsAProviderException(): void
    {
        foreach ([['180000'], 'three minutes'] as $millis) {
            $rows = [
                ['wrapperType' => 'collection', 'collectionId' => 1, 'collectionName' => 'Album'],
                ['wrapperType' => 'track', 'kind' => 'song', 'collectionId' => 1, 'trackId' => 11, 'trackName' => 'One', 'trackTimeMillis' => $millis],
            ];
            try {
                $this->catalog(new MockResponse((string) json_encode(['resultCount' => 2, 'results' => $rows])))->release(Reference::upc(self::UPC));
                self::fail('ProviderException expected');
            } catch (ProviderException $e) {
                self::assertSame('itunes', $e->catalog);
                self::assertStringContainsString('trackTimeMillis', $e->getMessage());
            }
        }
    }

    /** Retry-After may be an HTTP date (RFC 9110 §10.2.3): the seconds until then. */
    public function testARetryAfterDateIsTheSecondsUntilThen(): void
    {
        try {
            $this->catalog(new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After' => gmdate(\DATE_RFC7231, time() + 120)]]))->release(Reference::upc(self::UPC));
            self::fail('RateLimitedException expected');
        } catch (RateLimitedException $e) {
            self::assertGreaterThanOrEqual(118, $e->retryAfter);
            self::assertLessThanOrEqual(120, $e->retryAfter);
        }
    }

}
