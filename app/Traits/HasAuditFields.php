<?php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;

/**
 * Trait HasAuditFields
 * 
 * Auto-populate audit fields (created_by, updated_by, deleted_by)
 * when creating, updating, or soft deleting models.
 */
trait HasAuditFields
{
    /**
     * Boot the trait
     */
    protected static function bootHasAuditFields(): void
    {
        static::creating(function ($model) {
            if (Auth::check()) {
                $model->created_by = Auth::id();
            }
        });

        static::updating(function ($model) {
            if (Auth::check()) {
                $model->updated_by = Auth::id();
            }
        });

        static::deleting(function ($model) {
            if (Auth::check() && method_exists($model, 'isForceDeleting') && !$model->isForceDeleting()) {
                $model->deleted_by = Auth::id();
                $model->save();
            }
        });
    }
}
