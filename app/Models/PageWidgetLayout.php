<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PageWidgetLayout extends Model
{
    protected $fillable = ['user_id', 'page', 'layout'];

    protected function casts(): array
    {
        return ['layout' => 'array'];
    }
}
