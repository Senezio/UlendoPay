<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookAdminController extends Controller
{
    public function webhookLogs(Request $request): JsonResponse
    {
        $query = \App\Models\WebhookLog::query()->orderByDesc('received_at');

        if ($request->filled('source'))    $query->where('source', $request->source);
        if ($request->filled('direction')) $query->where('direction', $request->direction);
        if ($request->filled('outcome'))   $query->where('outcome', $request->outcome);
        if ($request->filled('from'))      $query->whereDate('received_at', '>=', $request->from);
        if ($request->filled('to'))        $query->whereDate('received_at', '<=', $request->to);

        $total    = (clone $query)->count();
        $accepted = (clone $query)->where('outcome', 'accepted')->count();
        $failed   = (clone $query)->where('outcome', 'failed')->count();
        $rejected = (clone $query)->where('outcome', 'rejected')->count();
        $paginated = $query->paginate(50);

        return response()->json([
            'logs'      => $paginated->items(),
            'last_page' => $paginated->lastPage(),
            'summary'   => compact('total', 'accepted', 'failed', 'rejected'),
        ]);
    }

    public function webhookLogShow(int $id): JsonResponse
    {
        return response()->json(['log' => \App\Models\WebhookLog::findOrFail($id)]);
    }
}
