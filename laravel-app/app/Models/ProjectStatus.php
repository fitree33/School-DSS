<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectStatus extends Model
{
    protected $fillable = ['name', 'code', 'color', 'sort_order', 'is_terminal'];

    protected $casts = ['is_terminal' => 'boolean'];

    public function getDisplayNameAttribute(): string
    {
        return match ($this->code) {
            'draft' => 'ร่างโครงการ',
            'returned' => 'ส่งกลับแก้ไข',
            'pending_deputy' => 'รอรอง ผอ. กลั่นกรอง',
            'pending_director' => 'รอ ผอ. อนุมัติ',
            'approved' => 'อนุมัติแล้ว',
            'rejected' => 'ไม่อนุมัติ',
            'in_progress' => 'กำลังดำเนินการ',
            'completed' => 'รายงานผลแล้ว',
            'archived' => 'จัดเก็บแล้ว',
            default => $this->name,
        };
    }
}
