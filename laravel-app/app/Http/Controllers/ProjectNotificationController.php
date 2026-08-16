<?php

namespace App\Http\Controllers;

use App\Models\ProjectNotification;
use Illuminate\Http\Request;

class ProjectNotificationController extends Controller
{
    public function index(Request $request)
    {
        $notifications = ProjectNotification::query()
            ->with('project')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return view('notifications.index', compact('notifications'));
    }

    public function read(Request $request, ProjectNotification $notification)
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        $notification->update(['read_at' => now()]);

        return $notification->project
            ? redirect()->route('projects.show', $notification->project)
            : redirect()->route('notifications.index');
    }
}
