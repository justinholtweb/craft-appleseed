<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Models;

use Carbon\Carbon;
use CraftCms\Cms\Shared\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use JustinHoltWeb\Appleseed\Database\Tables;
use JustinHoltWeb\Appleseed\Services\Reporting;
use JustinHoltWeb\Appleseed\Support\LinkStatus;
use Override;

use function CraftCms\Cms\cp_url;

/**
 * A unique URL Appleseed found, and how it fared when last checked. Deduplicated on `urlHash`
 * (SHA-256 of the URL); where it was found lives in {@see LinkSource}.
 *
 * @property int $id
 * @property string $url
 * @property string $urlHash
 * @property string|null $host
 * @property int|null $statusCode
 * @property string $status
 * @property string|null $redirectUrl
 * @property list<string>|null $redirectChain
 * @property string|null $errorMessage
 * @property Carbon|null $lastCheckedAt
 * @property Carbon|null $firstBrokenAt
 * @property int|null $lastScanId
 * @property bool $isIgnored
 * @property int|null $ignoredById
 * @property Carbon|null $dateIgnored
 * @property Carbon $dateCreated
 * @property Carbon $dateUpdated
 * @property string $uid
 */
class Link extends BaseModel
{
    #[Override]
    protected $table = Tables::LINKS;

    #[Override]
    protected $attributes = [
        'status' => LinkStatus::UNKNOWN,
        'isIgnored' => false,
    ];

    #[Override]
    protected static function booted(): void
    {
        static::creating(function (Link $link): void {
            if (in_array($link->getAttribute('uid'), [null, '', '0'], true)) {
                $link->uid = Str::uuid()->toString();
            }
        });
    }

    /** @return HasMany<LinkSource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(LinkSource::class, 'linkId')->orderBy('id');
    }

    /**
     * Links with a broken status (broken, server_error, timeout, dns_error), not ignored.
     *
     * @param  Builder<Link>  $query
     * @return Builder<Link>
     */
    public function scopeBroken(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('status'), LinkStatus::BROKEN_STATUSES)
            ->where($this->qualifyColumn('isIgnored'), false);
    }

    /**
     * @param  Builder<Link>  $query
     * @return Builder<Link>
     */
    public function scopeNotIgnored(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('isIgnored'), false);
    }

    /**
     * A status filter as the dashboard, GraphQL and MCP take it: a status, `broken` for every
     * broken status, `ignored` for ignored links, or null for every link that isn't ignored.
     *
     * @param  Builder<Link>  $query
     * @return Builder<Link>
     */
    public function scopeWithStatus(Builder $query, ?string $filter): Builder
    {
        $filter = LinkStatus::normalizeFilter($filter);

        if ($filter === LinkStatus::IGNORED) {
            return $query->where($this->qualifyColumn('isIgnored'), true);
        }

        $query->where($this->qualifyColumn('isIgnored'), false);

        if ($filter === LinkStatus::BROKEN) {
            return $query->whereIn($this->qualifyColumn('status'), LinkStatus::BROKEN_STATUSES);
        }

        return $filter !== null ? $query->where($this->qualifyColumn('status'), $filter) : $query;
    }

    /**
     * Links found on a site. Crawled pages count for every site.
     *
     * @param  Builder<Link>  $query
     * @return Builder<Link>
     */
    public function scopeForSite(Builder $query, ?int $siteId): Builder
    {
        if (! $siteId) {
            return $query;
        }

        return $query->whereExists(fn ($sub) => $sub->from(Tables::LINK_SOURCES, 'ss')
            ->whereColumn('ss.linkId', $this->qualifyColumn('id'))
            ->where(fn ($or) => $or->where('ss.siteId', $siteId)->orWhere('ss.sourceType', LinkSource::TYPE_SPIDER)));
    }

    /**
     * @param  Builder<Link>  $query
     * @return Builder<Link>
     */
    public function scopeForHost(Builder $query, ?string $host): Builder
    {
        $host = strtolower(trim((string) $host));

        return $host !== '' ? $query->where($this->qualifyColumn('host'), $host) : $query;
    }

    /**
     * Links found in an entry's fields (its canonical ID).
     *
     * @param  Builder<Link>  $query
     * @return Builder<Link>
     */
    public function scopeForEntry(Builder $query, ?int $entryId): Builder
    {
        if (! $entryId) {
            return $query;
        }

        return $query->whereExists(fn ($sub) => $sub->from(Tables::LINK_SOURCES, 'es')
            ->whereColumn('es.linkId', $this->qualifyColumn('id'))
            ->where('es.entryId', $entryId));
    }

    /**
     * @param  Builder<Link>  $query
     * @return Builder<Link>
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        $search = trim((string) $search);

        return $search !== '' ? $query->where($this->qualifyColumn('url'), 'like', '%'.addcslashes($search, '%_\\').'%') : $query;
    }

    /**
     * Every listing filter at once. See {@see Reporting::FILTER_KEYS}.
     *
     * `includeIgnored` only applies without a status filter.
     *
     * @param  Builder<Link>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Link>
     */
    public function scopeFiltered(Builder $query, array $filters): Builder
    {
        $status = LinkStatus::normalizeFilter($filters['status'] ?? null);

        if ($status !== null || empty($filters['includeIgnored'])) {
            $query->withStatus($status);
        }

        return $query
            ->search(isset($filters['search']) ? (string) $filters['search'] : null)
            ->forHost(isset($filters['host']) ? (string) $filters['host'] : null)
            ->forSite(isset($filters['siteId']) ? (int) $filters['siteId'] : null)
            ->forEntry(isset($filters['entryId']) ? (int) $filters['entryId'] : null);
    }

    public function isBroken(): bool
    {
        return LinkStatus::isBroken($this->status);
    }

    public function cpUrl(): string
    {
        return cp_url('appleseed/dashboard/detail/'.$this->id);
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'statusCode' => 'integer',
            'lastScanId' => 'integer',
            'ignoredById' => 'integer',
            'isIgnored' => 'boolean',
            'redirectChain' => 'array',
            'lastCheckedAt' => 'datetime',
            'firstBrokenAt' => 'datetime',
            'dateIgnored' => 'datetime',
        ];
    }
}
