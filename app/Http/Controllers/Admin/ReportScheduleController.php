<?php

namespace App\Http\Controllers\Admin;

use App\Application\Reports\DispatchScheduledReport;
use App\Application\Reports\ReportRegistry;
use App\Application\Reports\ScheduledReportPlan;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReportScheduleRequest;
use App\Models\ReportSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class ReportScheduleController extends Controller
{
    public function index(Request $request, ReportRegistry $registry): View
    {
        $store = $this->resolveStore($request);

        return view('admin.report-schedules.index', ['schedules' => $store->reportSchedules()->with('latestRun')->latest()->paginate(30), 'registry' => $registry]);
    }

    public function create(Request $request, ScheduledReportPlan $plan, ReportRegistry $registry): View
    {
        $store = $this->resolveStore($request);
        $validated = $request->validate(['source_run_id' => ['nullable', 'integer', 'min:1'], 'tool' => ['nullable', 'in:shipment_backlog,shopify_backlog,shipment_movement_monitor,run_audit']]);
        $source = isset($validated['source_run_id']) ? $store->reportRuns()->whereKey($validated['source_run_id'])->firstOrFail() : null;
        if ($source !== null) {
            $plan->assertTemplate($source);
        }
        $tool = $source->tool ?? ($validated['tool'] ?? 'shipment_backlog');
        $schedule = new ReportSchedule(['name' => $source === null ? __($registry->find($tool)->label ?? $tool) : __($registry->find($source->tool)->label ?? $source->tool), 'tool' => $tool, 'arguments' => $source->arguments ?? [], 'parameters' => $source->parameters ?? [], 'uses_date_range' => $source?->start_date !== null || $tool === 'run_audit', 'enabled' => false, 'frequency' => 'daily', 'time' => '07:00', 'timezone' => $store->shopTimezone(), 'weekday' => 1, 'month_day' => 1, 'lookback_days' => 30, 'end_offset_days' => 1, 'notification_enabled' => false, 'notification_threshold' => 1]);

        return view('admin.report-schedules.form', compact('schedule', 'source', 'registry'));
    }

    public function store(ReportScheduleRequest $request, ScheduledReportPlan $plan): RedirectResponse
    {
        $store = $this->resolveStore($request);
        $data = $request->safe()->except(['source_run_id', 'tool']);
        $sourceId = $request->validated('source_run_id');
        if ($sourceId !== null) {
            $source = $store->reportRuns()->whereKey($sourceId)->firstOrFail();
            $plan->assertTemplate($source);
            $template = ['tool' => $source->tool, 'arguments' => $source->arguments, 'parameters' => $source->parameters ?? [], 'uses_date_range' => $source->start_date !== null];
        } else {
            if (! in_array($request->validated('tool'), ['shipment_backlog', 'shopify_backlog', 'shipment_movement_monitor', 'run_audit'], true)) {
                throw ValidationException::withMessages(['source_run_id' => __('Run a report with the desired filters, then choose Schedule with these filters.')]);
            }
            $template = ['tool' => $request->validated('tool'), 'arguments' => [], 'parameters' => [], 'uses_date_range' => $request->validated('tool') === 'run_audit'];
        }
        $schedule = new ReportSchedule([...$data, ...$template, 'store_id' => $store->id, 'user_id' => $request->user()?->id]);
        $this->validatePeriod($schedule);
        $schedule->next_run_at = $schedule->enabled ? $plan->nextRun($schedule, CarbonImmutable::now('UTC')) : null;
        $schedule->save();
        activity('operator-actions')->causedBy($request->user())->performedOn($store)->withProperties(['schedule_id' => $schedule->id, 'tool' => $schedule->tool, 'enabled' => $schedule->enabled])->log('save_report_schedule');

        return to_route('admin.report-schedules.index')->with('status', __('Report schedule saved.'));
    }

    public function edit(Request $request, int $schedule, ReportRegistry $registry): View
    {
        $schedule = $this->resolveStore($request)->reportSchedules()->findOrFail($schedule);

        return view('admin.report-schedules.form', ['schedule' => $schedule, 'source' => null, 'registry' => $registry]);
    }

    public function update(ReportScheduleRequest $request, int $schedule, ScheduledReportPlan $plan): RedirectResponse
    {
        $store = $this->resolveStore($request);
        $schedule = $store->reportSchedules()->findOrFail($schedule);
        $schedule->fill($request->safe()->except(['source_run_id', 'tool']));
        $schedule->user_id = $request->user()?->id;
        $this->validatePeriod($schedule);
        $schedule->next_run_at = $schedule->enabled ? $plan->nextRun($schedule, CarbonImmutable::now('UTC')) : null;
        $schedule->save();
        if (! $schedule->enabled) {
            $schedule->cancelQueuedAutomaticRuns();
        }
        activity('operator-actions')->causedBy($request->user())->performedOn($store)->withProperties(['schedule_id' => $schedule->id, 'enabled' => $schedule->enabled])->log('save_report_schedule');

        return to_route('admin.report-schedules.index')->with('status', __('Report schedule saved.'));
    }

    public function toggle(Request $request, int $schedule, ScheduledReportPlan $plan): RedirectResponse
    {
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $store = $this->resolveStore($request);
        $schedule = $store->reportSchedules()->findOrFail($schedule);
        $schedule->enabled = (bool) $validated['enabled'];
        $schedule->user_id = $request->user()?->id;
        $schedule->next_run_at = $schedule->enabled ? $plan->nextRun($schedule, CarbonImmutable::now('UTC')) : null;
        $schedule->save();
        if (! $schedule->enabled) {
            $schedule->cancelQueuedAutomaticRuns();
        }
        activity('operator-actions')->causedBy($request->user())->performedOn($store)->withProperties(['schedule_id' => $schedule->id, 'enabled' => $schedule->enabled])->log('toggle_report_schedule');

        return to_route('admin.report-schedules.index')->with('status', __('Report schedule saved.'));
    }

    private function validatePeriod(ReportSchedule $schedule): void
    {
        if ($schedule->tool === 'shipment_activity' && $schedule->end_offset_days < 1) {
            throw ValidationException::withMessages(['end_offset_days' => __('Shipment activity requires completed store-local days.')]);
        }
        if ($schedule->tool === 'inventory_forecast' && $schedule->lookback_days !== 30) {
            throw ValidationException::withMessages(['lookback_days' => __('Inventory Forecast requires a 30-day lookback.')]);
        }
    }

    public function run(Request $request, int $schedule, DispatchScheduledReport $dispatch): RedirectResponse
    {
        $store = $this->resolveStore($request);
        $schedule = $store->reportSchedules()->findOrFail($schedule);
        try {
            $dispatch->handle($schedule, manual: true);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['schedule' => __('The report could not be queued. Check integrations and queue health.')]);
        }
        activity('operator-actions')->causedBy($request->user())->performedOn($store)->withProperties(['schedule_id' => $schedule->id])->log('run_report_schedule');

        return to_route('admin.report-schedules.index')->with('status', __('Report queued.'));
    }

    public function result(Request $request, int $schedule, int $run, ReportRegistry $registry): RedirectResponse
    {
        $schedule = $this->resolveStore($request)->reportSchedules()->findOrFail($schedule);
        $run = $schedule->runs()->findOrFail($run);
        abort_unless($run->status === 'completed', 404);
        if (in_array($schedule->tool, ['shipment_backlog', 'shopify_backlog'], true)) {
            $date = $run->result()?->meta['counts']['captured_at'] ?? ($run->created_at?->toIso8601String() ?? now()->toIso8601String());

            return to_route('report-trends.index', ['start_date' => substr($date, 0, 10), 'end_date' => substr($date, 0, 10)]);
        }
        if ($schedule->tool === 'run_audit') {
            return to_route('saved-reports.index');
        }
        $tool = $registry->find($schedule->tool);
        abort_unless($tool?->scheduleAction !== null, 404);

        return to_route($tool->route.'.result', [...($run->parameters ?? []), 'report_run' => $run->id]);
    }
}
