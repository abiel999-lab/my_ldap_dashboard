<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

trait AutoCreateUpdateBy
{
    protected static function bootAutoCreateUpdateBy(): void
    {
        static::creating(function (Model $model): void {
            if (! Auth::check()) {
                return;
            }

            if ($model->isFillable('created_by') && empty($model->created_by)) {
                $model->created_by = Auth::id();
            }

            if ($model->isFillable('updated_by') && empty($model->updated_by)) {
                $model->updated_by = Auth::id();
            }
        });

        static::updating(function (Model $model): void {
            if (! Auth::check()) {
                return;
            }

            if ($model->isFillable('updated_by')) {
                $model->updated_by = Auth::id();
            }
        });
    }
}
