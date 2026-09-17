<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasBadge;

enum Gender: string
{
    use HasBadge;

    case Woman = 'woman';
    case Man = 'man';
    case NonBinary = 'non_binary';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Woman => 'Woman',
            self::Man => 'Man',
            self::NonBinary => 'Non-binary',
            self::Other => 'Other',
        };
    }

    public function badgeClasses(): string
    {
        return 'bg-muted text-muted-foreground';
    }

    /** Short form for dense table cells and chart legends. */
    public function abbreviation(): string
    {
        return match ($this) {
            self::Woman => 'W',
            self::Man => 'M',
            self::NonBinary => 'NB',
            self::Other => 'O',
        };
    }

    public function chartClasses(): string
    {
        return match ($this) {
            self::Woman => 'bg-chart-1',
            self::Man => 'bg-chart-3',
            self::NonBinary => 'bg-chart-2',
            self::Other => 'bg-chart-5',
        };
    }
}
