<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Masters;
use Illuminate\Database\Eloquent\Model;

/** Operator wording and availability for one ReportCategory. */
class ReportCategorySetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Masters::flush());
    }
}
