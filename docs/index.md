# omnisong/itunes

The iTunes Search API for [omnisense/omnisong](https://github.com/glitchr-omnisense/omnisong): a
release with its tracks, their 30-second previews and its artwork, and an artist's albums - no key,
no account. Apple's public catalogue API answers two requests, both used here:

| Operation | Endpoint | Query |
|---|---|---|
| a release, its tracks | `GET /lookup` | `upc=`, `isrc=` or `id=`, with `entity=song&country=<cc>&limit=200` |
| an artist's albums | `GET /search` | `term=<artist>&media=music&entity=album&attribute=artistTerm&country=<cc>&limit=<n>` |

on `https://itunes.apple.com/` (`Omnisong\Itunes\ItunesCatalog::BASE_URI`, the `base_uri` option).
There is no sandbox: the API is open and the same for everyone.

## Installation

```sh
composer require omnisense/omnisong omnisong/itunes
```

Requires PHP 8.2, `omnisense/omnisong` (1.x) and `symfony/http-client` (6.4, 7 or 8). In a Symfony
application with `Omnisong\Bridge\Symfony\OmnisongBundle`, the factory is registered as soon as the
package is installed.

## Credentials

None. Apple allows about 20 calls a minute from one address; past that it answers 403 (or 429),
which this catalogue reports as a `RateLimitedException`.

## Options

From `ItunesCatalogFactory`:

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `country` | string | `us` | no | the store asked (ISO 3166-1 alpha-2, sent in lower case): what is sold there, in its language. Empty or null is `us` |
| `base_uri` | string | `https://itunes.apple.com/` | no | the API's root. Empty or null is the default |

A `Reference`'s own `country` (`Reference::upc('...', 'fr')`) overrides `country` for that call;
`releases($artist, $limit, $country)` takes one too. No other option is read.

## Capabilities

| Call | Supported |
|---|---|
| `release()` by UPC/EAN | yes (`lookup?upc=`) |
| `release()` by ISRC | yes: the track (`lookup?isrc=`), then its album (`lookup?id=<collectionId>`) |
| `release()` by an Apple Music / iTunes URL | yes, by the album id in its path (`.../album/<slug>/1793146044`, `.../id1793146044`) |
| `release()` by `Reference::id(Platform::ITUNES or Platform::APPLE_MUSIC, $id)` | yes; a track's id leads to its album |
| `release()` by any other URL or platform id | no: `NotSupportedException` |
| `links()` | the release's two Apple links, for every reference `release()` reads |
| `releases($artist)` | yes, albums found by artist name |

## Behaviour

**Release.** One `lookup` with `entity=song`, which answers the album (`wrapperType: collection`)
and its songs. When the answer has no album but a track (an ISRC, a track's id), the track's
`collectionId` is looked up next: two calls. No album at all is `null`. Then:

| `Release` | From |
|---|---|
| `title` | `collectionName`, without a final ` - Single` or ` - EP` |
| `type` | `single` or `ep` from that suffix, else `album` |
| `artist` | `artistName` |
| `label` | a `Label` named after the `copyright` line's holder: `℗ 2025 ES-DUR` gives `ES-DUR`. A line with neither `℗` nor `©` gives none. The holder is who iTunes names, often the producer rather than the label (`℗ 2025 C2 Hamburg. Musik & Medienproduktion` in the recorded answer): give the site's own `Label` to the call to put the right one first |
| `upc` | the reference's UPC when it was asked by one; else null (the API does not return it) |
| `releasedAt` | `releaseDate`; unreadable is null |
| `coverUrl` | `artworkUrl100` with `100x100bb` replaced by `1200x1200bb` |
| `genre` | `primaryGenreName` |
| `trackCount` | `trackCount` |
| `ids` | `['itunes' => <collectionId>, 'apple_music' => <collectionId>]` |
| `copyright` | `copyright` as written |
| `links` | Apple Music: `collectionViewUrl` without its `uo` parameter, id the collection id, `country` from the URL's store; iTunes Store: the same URL with `app=itunes` |

Each song of that album becomes a `Track`: `trackName`, `trackNumber`, `discNumber`, the duration
in seconds (`trackTimeMillis` / 1000, rounded), `previewUrl`, `artistName`, `ids: ['itunes' =>
<trackId>]`, and `trackViewUrl` without `uo` as `url`. Tracks are sorted by disc, then number.
`isrc` and `composer` are always null: the API gives neither.

**Memory.** Each answer (null included) is kept in the object by its query: `links()` after
`release()` of the same reference calls nothing.

**Releases.** One `search` for albums whose artist matches the name, `limit` capped at 200 (at
least 1), each row read as above without tracks and without a UPC, sorted newest first, cut to
`limit`. The search matches the name, not an artist id: another artist of the same name can come
back.

**Answers.** The API serves JSON as `text/javascript`: the body is read as JSON whatever the
header says. Only rows that are objects are read.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnisong\Itunes\ItunesCatalogFactory;
use Omnisong\Model\Reference;
use Symfony\Component\HttpClient\HttpClient;

$itunes = (new ItunesCatalogFactory(HttpClient::create()))->create(['country' => 'de']);

$release = $itunes->release(Reference::upc('4015372820954'));
printf("%s | %s | %s | %s | %s | %d tracks | %s\n", $release->title, $release->type, $release->label?->name, $release->releasedAt?->format('Y-m-d'), $release->genre, $release->trackCount, $release->coverUrl);
$t = $release->tracks[0];
printf("%d-%d %s (%d s) isrc=%s composer=%s ids=%s\n  %s\n", $t->disc, $t->position, $t->title, $t->duration, var_export($t->isrc, true), var_export($t->composer, true), json_encode($t->ids), $t->url);
foreach ($itunes->links(Reference::upc('4015372820954')) as $link) {
    printf("  %s %s id=%s country=%s\n", $link->platform->value, $link->url, $link->id, $link->country);
}
foreach ($itunes->releases('Anaëlle Tourret', 3) as $r) {
    printf("  %s %s (%s) %s\n", $r->releasedAt?->format('Y-m-d'), $r->title, $r->type, $r->label?->name);
}
```

Run on 2026-10-26 on a `MockHttpClient` serving the package's recorded answers
(`Tests/Fixtures/lookup-upc.json`, `search-albums.json`, recorded on 2026-10-03; the search fixture
holds one album) instead of `HttpClient::create()`; the `>` lines are the mock's log of each request:

```
> https://itunes.apple.com/lookup?upc=4015372820954&entity=song&country=de&limit=200
Perspectives Concertantes | album | C2 Hamburg. Musik & Medienproduktion | 2025-02-28 | Klassik | 8 tracks | https://is1-ssl.mzstatic.com/image/thumb/Music221/v4/03/84/2e/03842ead-96bc-0e38-6446-f61a9bfde85c/cover.jpg/1200x1200bb.jpg
1-1 Concerto for Harp and Orchestra in E-Flat Major, Op. 74: I. Allegro Moderato (677 s) isrc=NULL composer=NULL ids={"itunes":"1793146055"}
  https://music.apple.com/de/album/concerto-for-harp-and-orchestra-in-e-flat-major-op-74/1793146044?i=1793146055
  apple_music https://music.apple.com/de/album/perspectives-concertantes/1793146044 id=1793146044 country=DE
  itunes https://music.apple.com/de/album/perspectives-concertantes/1793146044?app=itunes id=1793146044 country=DE
> https://itunes.apple.com/search?term=Anaëlle Tourret&media=music&entity=album&attribute=artistTerm&country=de&limit=3
  2021-07-17 Strauss & Franck: Sonatas for Violin and Piano (album) FARAO classics
```

The `links()` call sent nothing: it is the release already answered. In Symfony:

```yaml
omnisong:
    catalogs:
        itunes:
            factory: itunes
            options:
                country: 'de'
```

## Errors

| Answer | Exception |
|---|---|
| 403, 429 | `RateLimitedException` (`retryAfter` from `Retry-After` when sent; its `status` is 429 in both cases) |
| 404 | none: no rows, so `null` / empty links / `[]` |
| 5xx | `UnavailableException` (`[itunes] HTTP 503`) |
| other 4xx | `ProviderException` (`[itunes] HTTP 400 <errorMessage>`) |
| 2xx that is not JSON | `UnavailableException` (`an answer that is not JSON`) |
| transport error, timeout | `UnavailableException`, the client's exception as previous |
| a reference it cannot read | `NotSupportedException`, before any call |

> [!WARNING]
> Known defect: `Retry-After` written as an HTTP date is read as `retryAfter = 0`, where the seconds until that date (or null) are expected.

## Verified, and not

The package's tests (`Tests/ItunesCatalogTest.php`, 7 tests) run on answers recorded from the API on
2026-10-03 (store `de`): a UPC gives the release with its tracks and previews, an Apple Music URL or
its id the same release, a track's id or an ISRC its album, the links are Apple's, an artist's
albums come newest first, what is not Apple's is not read, and 403, 429 and 5xx are unavailable.
They pass (2026-10-26, PHP 8.4). The API was last called live on 2026-10-05
([omnisense/omnisong's installation](https://github.com/glitchr-omnisense/omnisong/blob/1.x/docs/installation.md)).

## Responsibility

This package is an independent, unofficial client, not affiliated with, endorsed by or sponsored by
Apple Inc. The previews are Apple's, for promotional use next to a link to the store: keep the Apple
Music link beside the player. Whoever runs it complies with the
[iTunes Search API terms](https://performance-partners.apple.com/search-api); see the README's
Responsibility section.
