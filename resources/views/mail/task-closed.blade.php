<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('ui.task_closed') }}</title>
    <style>
        @media only screen and (max-width: 600px) {
            .container { width: 100% !important; }
            .content-padding { padding: 15px !important; }
        }
    </style>
</head>
<body style="font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #444; margin: 0; padding: 0; background-color: #f8f9fa;">

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f8f9fa;">
    <tr>
        <td align="center" style="padding: 40px 10px;">
            <table class="container" width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border-collapse: collapse; overflow: hidden;">

                <tr>
                    <td align="center" style="background: linear-gradient(135deg, #198754 0%, #146c43 100%); padding: 40px 20px;">
                        <h1 style="margin: 0; color: #ffffff; font-size: 28px; letter-spacing: 1px; font-weight: 700;">
                            {{ __('ui.fault_tracking_panel') }}
                        </h1>
                        <div style="margin: 15px auto; height: 3px; width: 50px; background-color: #ffffff; border-radius: 2px; opacity: 0.5;"></div>

                        <div style="display: inline-block; padding: 6px 15px; background-color: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3); border-radius: 20px; color: #ffffff; font-size: 14px; font-weight: 600; margin-bottom: 10px;">
                            #{{ $task->id }}
                        </div>

                        <p style="margin: 5px 0 0 0; color: #ffffff; font-size: 18px; font-weight: 500; opacity: 0.95;">
                            {{ __('ui.task_closed') }}
                        </p>
                    </td>
                </tr>
                <tr>
                    <td class="content-padding" style="padding: 40px;">
                        <p style="margin-top: 0; margin-bottom: 20px; font-size: 18px; color: #333;">
                            {{ __('ui.hello') }} <strong>{{ $user?->name }}</strong>,
                        </p>

                        <p style="margin-bottom: 30px; font-size: 15px; color: #666;">
                            {{ __('ui.task_closed_message') }}
                        </p>

                        <div style="background-color: #ffffff; border: 1px solid #e9ecef; border-radius: 10px; overflow: hidden; margin-bottom: 25px;">
                            <div style="padding: 15px 20px; background-color: #fcfcfc; border-bottom: 1px solid #e9ecef;">
                                <h3 style="margin: 0; color: #333; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px;">{{__('ui.task_details')}}</h3>
                            </div>
                            <div style="padding: 20px;">
                                <table width="100%" cellpadding="0" cellspacing="0" style="font-size: 14px;">
                                    <tr>
                                        <td width="140" style="padding-bottom: 10px; color: #888;">{{ __('ui.area') }} / {{ __('ui.sub_area') }}</td>
                                        <td style="padding-bottom: 10px; color: #333; font-weight: 600;">{{ $task->area?->name ?? '-' }} / <span style="color: #007bff;">{{ $task->subArea?->name ?? '-' }}</span></td>
                                    </tr>
                                    <tr>
                                        <td style="padding-bottom: 10px; color: #888;">{{ __('ui.task_unit') }}</td>
                                        <td style="padding-bottom: 10px; color: #333; font-weight: 600;">{{ $task->unit?->name ?? '-' }}</td>
                                    </tr>
                                    <tr>
                                        <td style="padding-bottom: 12px; font-size: 14px; color: #888;">{{ __('ui.assigned_by') }}</td>
                                        <td style="padding-bottom: 12px; font-size: 14px; color: #333;">
                                            <strong>{{ $assigned_by?->name }}</strong>
                                            <span style="color: #bbb; margin: 0 5px;">|</span>
                                            <span style="color: #666; font-size: 13px;">{{ $task->updated_at?->format('d.m.Y H:i') ?? now()->format('d.m.Y H:i') }}</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding-bottom: 15px; font-size: 14px; color: #888;">{{ __('ui.fault_date') }}</td>
                                        <td style="padding-bottom: 15px;">
                                            <div style="display: inline-block; padding: 4px 12px; background-color: #f8f9fa; border-left: 3px solid #6c757d; border-radius: 4px;">
                                                <span style="font-size: 14px; color: #333; font-weight: 700; letter-spacing: 0.3px;">
                                                    {{ $task->task_date?->format('d.m.Y') ?? '-' }}
                                                </span>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding-bottom: 10px; color: #888;">{{ __('ui.task_type') }} / {{ __('ui.priority') }}</td>
                                        <td style="padding-bottom: 10px;">
                                            <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; background-color: #e7f3ff; color: #007bff; font-size: 11px; font-weight: bold; margin-right: 5px; border: 1px solid #cce5ff;">{{ $task->type_id->getLabel() }}</span>

                                            @php
                                                $priorityStyles = match($task->priority) {
                                                    \App\Enums\TaskPriorityEnum::Low => ['bg' => '#d4edda', 'text' => '#155724'],
                                                    \App\Enums\TaskPriorityEnum::Medium => ['bg' => '#e7f3ff', 'text' => '#007bff'],
                                                    \App\Enums\TaskPriorityEnum::High => ['bg' => '#fff3cd', 'text' => '#856404'],
                                                    \App\Enums\TaskPriorityEnum::Urgent => ['bg' => '#f8d7da', 'text' => '#721c24'],
                                                    default => ['bg' => '#f1f3f5', 'text' => '#6c757d'],
                                                };

                                                $policy = \App\Models\SlaPolicy::where('area_id', $task->area_id)
                                                    ->where('unit_id', $task->unit_id)
                                                    ->where('priority', $task->priority)
                                                    ->where('sub_area_id', $task->sub_area_id)
                                                    ->first() ?? \App\Models\SlaPolicy::where('area_id', $task->area_id)
                                                    ->where('unit_id', $task->unit_id)
                                                    ->where('priority', $task->priority)
                                                    ->whereNull('sub_area_id')
                                                    ->first();

                                                $slaText = ($policy && $policy->deadline_minutes)
                                                    ? ($policy->deadline_minutes >= 60 ? round($policy->deadline_minutes / 60, 1) . ' Saat' : $policy->deadline_minutes . ' Dakika')
                                                    : '-';
                                            @endphp
                                            <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; background-color: {{ $priorityStyles['bg'] }}; color: {{ $priorityStyles['text'] }}; font-size: 11px; font-weight: bold; margin-right: 5px;">{{ $task->priority->getLabel() }}</span>

                                            <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; background-color: #ffffff; color: #636e72; font-size: 11px; font-weight: bold; border: 1px solid #dfe6e9; vertical-align: middle;">
                                                <small style="font-weight: normal; opacity: 0.8; margin-right: 4px;">{{ __('ui.sla') }}:</small> {{ $slaText }}
                                            </span>
                                        </td>
                                    </tr>
                                </table>

                                <div style="margin-top: 15px; padding: 12px; background-color: #fdfdfe; border: 1px dashed #dee2e6; border-radius: 6px;">
                                    <strong style="display: block; margin-bottom: 4px; font-size: 11px; color: #aaa; text-transform: uppercase;">{{ __('ui.description') }}</strong>
                                    <div style="font-size: 13px; color: #666; font-style: italic;">
                                        "{{ $task->description }}"
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div style="background-color: #ffffff; border: 1px solid #198754; border-radius: 10px; overflow: hidden;">
                            <div style="padding: 15px 20px; background-color: #f1f8f5; border-bottom: 1px solid #d1e7dd;">
                                <h3 style="margin: 0; color: #146c43; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px;">{{__('ui.resolution_details')}}</h3>
                            </div>
                            <div style="padding: 20px;">
                                <table width="100%" cellpadding="0" cellspacing="0" style="font-size: 14px;">
                                    <tr>
                                        <td width="140" style="padding-bottom: 12px; color: #888;">{{ __('ui.closed_by') }}</td>
                                        <td style="padding-bottom: 12px; color: #333;"><strong>{{ $closed_by?->name }}</strong></td>
                                    </tr>
                                    <tr>
                                        <td style="padding-bottom: 12px; color: #888;">{{ __('ui.due_date') }}</td>
                                        <td style="padding-bottom: 12px; color: #333;">
                                            {{ $task->due_date?->format('d.m.Y H:i') }}
                                            @php
                                                $targetDate = $task->created_at->addMinutes($policy?->deadline_minutes ?? 0);
                                                $isSuccess = $task->due_date <= $targetDate;
                                            @endphp
                                            <span style="margin-left: 10px; display: inline-block; padding: 2px 8px; border-radius: 4px; background-color: {{ $isSuccess ? '#d4edda' : '#f8d7da' }}; color: {{ $isSuccess ? '#155724' : '#721c24' }}; font-size: 11px; font-weight: bold;">
                                                {{ $isSuccess ? __('ui.resolved_on_time') : __('ui.sla_breached') }}
                                            </span>
                                        </td>
                                    </tr>

                                    <tr>
                                        <td colspan="2" style="padding: 30px 0 10px 0; text-align: center; border-top: 1px solid #f1f3f5;">
                                            <div style="margin-bottom: 10px; font-size: 12px; color: #a1a1a1; text-transform: uppercase; letter-spacing: 1.5px; font-weight: 600;">
                                                {{ __('ui.waiting_time') }}
                                            </div>

                                            @if ($task->created_at)
                                                @php
                                                    $start = $task->created_at;
                                                    $end = ($task->status->value === \App\Enums\TaskStatusEnum::COMPLETED->value && $task->due_date)
                                                        ? $task->due_date
                                                        : now();

                                                    $diffText = $start->diffForHumans($end, [
                                                        'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE,
                                                        'parts' => 3,
                                                        'join' => ' ',
                                                    ]);

                                                    $isCompleted = ($task->status->value === \App\Enums\TaskStatusEnum::COMPLETED->value);
                                                    $bgColor = $isCompleted ? '#d4edda' : '#fff3cd';
                                                    $textColor = $isCompleted ? '#155724' : '#856404';
                                                    $borderColor = $isCompleted ? '#c3e6cb' : '#ffeeba';
                                                @endphp

                                                <div style="display: inline-block; padding: 12px 28px; border-radius: 50px; background-color: {{ $bgColor }}; border: 1px solid {{ $borderColor }}; box-shadow: 0 4px 10px rgba(0,0,0,0.04);">
                                                    <span style="font-size: 17px; font-weight: 800; color: {{ $textColor }}; letter-spacing: -0.2px;">
                                                        {{ $diffText }}
                                                    </span>
                                                </div>

                                                <div style="font-size: 11px; color: #bbb; margin-top: 12px;">
                                                    <span style="font-weight: 600;">{{ $start->format('d.m.Y H:i') }}</span>
                                                    <span style="margin: 0 10px; opacity: 0.5;">→</span>
                                                    <span style="font-weight: 600;">{{ $end->format('d.m.Y H:i') }}</span>
                                                </div>
                                            @else
                                                <span style="color: #ccc;">-</span>
                                            @endif
                                        </td>
                                    </tr>
                                </table>

                                <div style="margin-top: 10px; padding: 15px; background-color: #f8f9fa; border-radius: 6px; border-left: 4px solid #198754;">
                                    <strong style="display: block; margin-bottom: 5px; font-size: 12px; color: #888; text-transform: uppercase;">{{ __('ui.resolution_notes') }}</strong>
                                    <div style="font-size: 14px; color: #333;">"{{ $task->resolution_notes ?? '-' }}"</div>
                                </div>
                            </div>
                        </div>

                        <div style="text-align: center; margin-top: 40px;">
                            <a href="{{ config('app.url') . '/tasks/' . $task->id }}" target="_blank" style="display: inline-block; padding: 14px 35px; font-size: 16px; color: #ffffff; background-color: #198754; border-radius: 8px; text-decoration: none; font-weight: bold; box-shadow: 0 4px 12px rgba(25,135,84,0.2);">
                                {{ __('ui.view') }}
                            </a>
                        </div>

                        <p style="font-size: 14px; text-align: center; color: #888; margin-top: 30px;">
                            {{ __('ui.enjoy_your_work') }}<br>
                            <strong>{{__('ui.best_regards')}}</strong>
                        </p>
                    </td>
                </tr>

                <tr>
                    <td align="center" style="background-color: #f1f3f5; padding: 25px; border-top: 1px solid #e9ecef;">
                        <p style="margin: 0; font-size: 12px; color: #999; line-height: 1.5;">
                            &copy; {{ date('Y') }} <strong>{{ __('ui.gursoy_group') }}</strong>. {{ __('ui.all_rights_reserved') }}
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

</body>
</html>