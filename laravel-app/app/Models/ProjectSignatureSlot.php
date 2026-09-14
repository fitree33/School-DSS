<?php

namespace App\Models;

use App\Enums\ProjectSignatureSlotCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ProjectSignatureSlot extends Model
{
    public const MAX_ASSIGNMENT_REVISION = 4294967295;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'project_id', 'slot_code', 'slot_no', 'assigned_user_id',
        'assignment_revision', 'assigned_by', 'assigned_at', 'created_at', 'updated_at',
    ];

    protected $casts = [
        'id' => 'integer',
        'project_id' => 'integer',
        'slot_code' => ProjectSignatureSlotCode::class,
        'slot_no' => 'integer',
        'assigned_user_id' => 'integer',
        'assignment_revision' => 'integer',
        'assigned_by' => 'integer',
        'assigned_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $slot): void {
            if ($slot->isDirty(['id', 'project_id', 'slot_code', 'slot_no', 'created_at'])) {
                throw new LogicException('Project signature slot identity cannot be changed.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Project signature slots cannot be deleted.');
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id')->withTrashed();
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by')->withTrashed();
    }
}
