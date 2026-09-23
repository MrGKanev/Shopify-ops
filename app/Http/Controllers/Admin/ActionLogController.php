<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;

class ActionLogController extends Controller
{
    private const array LOG_NAMES = [
        'administration' => ['administration'],
        'operator' => ['operator-actions'],
        'all' => ['administration', 'operator-actions'],
    ];

    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): View
    {
        $category = $request->query('category');
        $category = is_string($category) && array_key_exists($category, self::LOG_NAMES) ? $category : 'administration';

        $activities = Activity::query()->inLog(...self::LOG_NAMES[$category])->with(['causer', 'subject'])->latest('id')->paginate(50)->withQueryString();

        return view('admin.action-log', ['activities' => $activities, 'category' => $category]);
    }
}
