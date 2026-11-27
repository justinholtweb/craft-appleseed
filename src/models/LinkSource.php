<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Models;

use Carbon\Carbon;
use CraftCms\Cms\Shared\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use JustinHoltWeb\Appleseed\Database\Tables;
use Override;

/**
 * One place a link was found: an entry's field (`database`) or a crawled page (`spider`).
 *
 * A scan replaces the sources of everything it read rather than adding to them, so a link removed
 * from an entry stops being reported against it.
 *
 * @property int $id
 * @property int $linkId
 * @property int|null $entryId
 * @property int|null $siteId
 * @property string|null $fieldHandle
 * @property string|null $linkText
 * @property string $sourceType
 * @property string|null $sourceUrl
 * @property Carbon $dateCreated
 * @property Carbon $dateUpdated
 * @property string $uid
 */
class LinkSource extends BaseModel
{
    public const string TYPE_DATABASE = 'database';

    public const string TYPE_SPIDER = 'spider';

    #[Override]
    protected $table = Tables::LINK_SOURCES;

    #[Override]
    protected static function booted(): void
    {
        static::creating(function (LinkSource $source): void {
            if (in_array($source->getAttribute('uid'), [null, '', '0'], true)) {
                $source->uid = Str::uuid()->toString();
            }
        });
    }

    /** @return BelongsTo<Link, $this> */
    public function link(): BelongsTo
    {
        return $this->belongsTo(Link::class, 'linkId');
    }

    /**
     * Sources on a site. Crawled pages count for every site.
     *
     * @param  Builder<LinkSource>  $query
     * @return Builder<LinkSource>
     */
    public function scopeForSite(Builder $query, ?int $siteId): Builder
    {
        if (! $siteId) {
            return $query;
        }

        return $query->where(fn (Builder $or) => $or->where('siteId', $siteId)->orWhere('sourceType', self::TYPE_SPIDER));
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'linkId' => 'integer',
            'entryId' => 'integer',
            'siteId' => 'integer',
        ];
    }
}
