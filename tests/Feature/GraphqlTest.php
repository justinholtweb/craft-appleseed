<?php

declare(strict_types=1);

use CraftCms\Cms\Gql\Data\GqlSchema;
use CraftCms\Cms\Gql\Gql;
use CraftCms\Cms\Support\Facades\Sites;
use JustinHoltWeb\Appleseed\Tests\Support\SeedsLinks;

uses(SeedsLinks::class);

/**
 * @param  list<string>  $scope
 * @return array<string, mixed>
 */
function appleseedGql(array $scope, string $query, bool $public = false): array
{
    $gql = app(Gql::class);
    $gql->invalidateCaches();

    return $gql->executeQuery(new GqlSchema(['name' => 'Test', 'scope' => $scope, 'isPublic' => $public]), $query);
}

beforeEach(fn () => $this->seedLinks());

it('answers a schema with the scope, in the 5.3 shape', function () {
    $result = appleseedGql(['appleseedLinks:read'], '{
        appleseedSummary { total broken redirects working blocked unchecked ignored lastScan { id type newBrokenCount } }
        appleseedLinkCount(status: "broken")
        appleseedLinks(status: "broken") { url status isBroken statusCode host sourceCount sources { sourceType fieldHandle linkText entryId } }
        appleseedLink(url: "https://moved.example.com/old") { status redirectUrl redirectChain }
        appleseedScans(limit: 5) { id type status brokenCount }
        appleseedScan { id }
    }');

    expect($result['errors'] ?? null)->toBeNull();
    $data = $result['data'];

    expect($data['appleseedSummary'])->toMatchArray(['total' => 3, 'broken' => 1, 'redirects' => 1, 'working' => 1, 'blocked' => 0, 'ignored' => 0])
        ->and($data['appleseedSummary']['lastScan'])->toMatchArray(['id' => $this->scan->id, 'type' => 'database', 'newBrokenCount' => 1])
        ->and($data['appleseedLinkCount'])->toBe(1)
        ->and($data['appleseedLinks'])->toBe([[
            'url' => 'https://gone.example.com/dead', 'status' => 'broken', 'isBroken' => true, 'statusCode' => 404, 'host' => 'gone.example.com', 'sourceCount' => 1,
            'sources' => [['sourceType' => 'database', 'fieldHandle' => 'body', 'linkText' => 'Dead', 'entryId' => $this->entry->id]],
        ]])
        ->and($data['appleseedLink'])->toBe(['status' => 'redirect', 'redirectUrl' => 'https://moved.example.com/new', 'redirectChain' => ['https://moved.example.com/new']])
        ->and($data['appleseedScans'][0])->toMatchArray(['id' => $this->scan->id, 'status' => 'completed', 'brokenCount' => 1])
        ->and($data['appleseedScan']['id'])->toBe($this->scan->id);
});

it('resolves a source’s entry only when the schema can read its section and site', function () {
    $query = '{ appleseedLinks(status: "broken") { sources { entry { title } } } }';

    $without = appleseedGql(['appleseedLinks:read'], $query);
    expect($without['errors'] ?? null)->toBeNull()
        ->and($without['data']['appleseedLinks'][0]['sources'][0]['entry'])->toBeNull();

    $sectionUid = $this->section()->uid;
    $siteUid = Sites::getPrimarySite()->uid;
    $with = appleseedGql(['appleseedLinks:read', "sections.{$sectionUid}:read", "sites.{$siteUid}:read"], $query);
    expect($with['errors'] ?? null)->toBeNull()
        ->and($with['data']['appleseedLinks'][0]['sources'][0]['entry'])->toBe(['title' => 'Harbour news']);
});

it('gives nothing to a schema without the scope, or to the public schema', function () {
    $without = appleseedGql([], '{ appleseedLinks { id } }');
    expect($without['data']['appleseedLinks'] ?? null)->toBeNull();

    $public = appleseedGql(['appleseedLinks:read'], '{ appleseedLinks { id } }', public: true);
    expect($public['data']['appleseedLinks'] ?? null)->toBeEmpty();
});
