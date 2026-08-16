<?php

namespace App\Http\Resources\Api\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $role = $this->resource->relationLoaded('role') ? $this->role : null;
        $department = $this->resource->relationLoaded('department') ? $this->department : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'teacher_code' => $this->teacher_code,
            'phone' => $this->phone,
            'email_verified_at' => $this->email_verified_at?->toISOString(),
            'last_login_at' => $this->last_login_at?->toISOString(),
            'is_active' => (bool) $this->is_active,
            'department' => $department ? [
                'id' => $department->id,
                'name' => $department->name,
            ] : null,
            'role' => $role ? [
                'id' => $role->id,
                'code' => $role->code,
                'name' => $role->name,
            ] : null,
            'permissions' => $role && $role->relationLoaded('permissions')
                ? $role->permissions->pluck('code')->sort()->values()->all()
                : [],
        ];
    }
}
