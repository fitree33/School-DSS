<?php

namespace App\Http\Controllers\Api\V2\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V2\UserResource;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request): UserResource
    {
        return new UserResource(
            $request->user()->load(['department', 'role.permissions'])
        );
    }
}
