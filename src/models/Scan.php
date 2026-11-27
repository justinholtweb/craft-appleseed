<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Models;

use Carbon\Carbon;
use CraftCms\Cms\Shared\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Str;
use JustinHoltWeb\Appleseed\Database\Tables;
use JustinHoltWeb\Appleseed\Support\ScanType;
use Override;

/**
 * One scan run: what it read, and how the links it checked fared.
 *
 * Prunable: Laravel's `model:prune` (scheduled daily by the plugin) deletes finished scans older
 * than the `scanHistoryDays` setting. Links and their results are kept; a link's `lastScanId`
 * is set to null by the foreign key.
 *
 * @property int $id
 * @property string $status
 * @property string $type
 * @property int $totalLinksFound
 * @property int $totalLinksChecked
 * @property int $brokenCount
 * @property int $newBrokenCount
 * @property int $redirectCount
 * @property int $workingCount
 * @property Carbon|null $startedAt
 * @property Carbon|null $completedAt
 * @property int|null $entryId
 * @property Carbon $dateCreated
 * @property Carbon $dateUpdated
 * @property string $uid
 */
class Scan extends BaseModel
{
    use Prunable;

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_CANCELLED = 'cancelled';

    #[Override]
    protected $table = Tables::SCANS;

    #[Override]
    protected $attributes = [
        'status' => self::STATUS_RUNNING,
        'type' => ScanType::FULL,
        'totalLinksFound' => 0,
        'totalLinksChecked' => 0,
        'brokenCount' => 0,
        'newBrokenCount' => 0,
        'redirectCount' => 0,
        'workingCount' => 0,
    ];

    #[Override]
    protected static function booted(): void
    {
        static::creating(function (Scan $scan): void {
            if (in_array($scan->getAttribute('uid'), [null, '', '0'], true)) {
                $scan->uid = Str::uuid()->toString();
            }
        });
    }

    /**
     * @param  Builder<Scan>  $query
     * @return Builder<Scan>
     */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_RUNNING);
    }

    /**
     * @param  Builder<Scan>  $query
     * @return Builder<Scan>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scans that read every entry's content (full, database, incremental). The schedule and
     * incremental scans are measured from these, so a scan of one saved entry never pushes them back.
     *
     * @param  Builder<Scan>  $query
     * @return Builder<Scan>
     */
    public function scopeReadContent(Builder $query): Builder
    {
        return $query->whereIn('type', ScanType::CONTENT);
    }

    /**
     * @param  Builder<Scan>  $query
     * @return Builder<Scan>
     */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('id');
    }

    /**
     * Finished scans past the retention period.
     *
     * @return Builder<Scan>
     */
    public function prunable(): Builder
    {
        $days = max(1, app(Settings::class)->scanHistoryDays);

        return Scan::query()
            ->where('status', '!=', self::STATUS_RUNNING)
            ->where('dateCreated', '<', now()->subDays($days));
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    /** 0–100: links checked of links found. */
    public function progressPercent(): int
    {
        return $this->totalLinksFound > 0
            ? (int) min(100, round($this->totalLinksChecked / $this->totalLinksFound * 100))
            : 0;
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'totalLinksFound' => 'integer',
            'totalLinksChecked' => 'integer',
            'brokenCount' => 'integer',
            'newBrokenCount' => 'integer',
            'redirectCount' => 'integer',
            'workingCount' => 'integer',
            'entryId' => 'integer',
            'startedAt' => 'datetime',
            'completedAt' => 'datetime',
        ];
    }
}
