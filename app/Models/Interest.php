<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Interest extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public function appUsers(): BelongsToMany
    {
        return $this->belongsToMany(AppUser::class);
    }
}
