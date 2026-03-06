<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('ui.task_assigned') }}</title>
    <style>
        @media only screen and (max-width: 600px) {
            .container { width: 100% !important; }
            .content-padding { padding: 15px !important; }
            .label-col { width: 100px !important; }
        }
    </style>
</head>
<body style="font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #444; margin: 0; padding: 0; background-color: #f8f9fa;">

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f8f9fa;">
    <tr>
        <td align="center" style="padding: 40px 10px;">
            <table class="container" width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border-collapse: collapse; overflow: hidden;">

                <tr>
                    <td align="center" style="background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); padding: 40px 20px;">
                        <h1 style="margin: 0; color: #ffffff; font-size: 28px; letter-spacing: 1px; font-weight: 700;">
                            {{ __('ui.fault_tracking_panel') }}
                        </h1>
                        <div style="margin-top: 10px; height: 3px; width: 50px; background-color: #ffffff; border-radius: 2px; opacity: 0.5;"></div>
                        <p style="margin: 15px 0 0 0; color: #ffffff; font-size: 16px; opacity: 0.9;">
                            {{ __('ui.new_task_assigned') }}
                        </p>
                    </td>
                </tr>

                <tr>
                    <td class="content-padding" style="padding: 40px;">
                        <p style="margin-top: 0; margin-bottom: 20px; font-size: 18px; color: #333;">
                            {{ __('ui.hello') }} <strong>{{ $user?->name }}</strong>,
                        </p>

                        <p style="margin-bottom: 30px; font-size: 15px; color: #666;">
                            {{ __('ui.task_assigned_message') }}
                        </p>

                        <div style="background-color: #ffffff; border: 1px solid #e9ecef; border-radius: 10px; overflow: hidden;">
                            <div style="padding: 15px 20px; background-color: #fcfcfc; border-bottom: 1px solid #e9ecef;">
                                <h3 style="margin: 0; color: #333; font-size: 16px; text-transform: uppercase; letter-spacing: 0.5px;">
                                    {{__('ui.task_details')}}
                                </h3>
                            </div>

                            <div style="padding: 20px;">
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td width="140" style="padding-bottom: 12px; font-size: 14px; color: #888;">{{ __('ui.area') }} / {{ __('ui.sub_area') }}</td>
                                        <td style="padding-bottom: 12px; font-size: 14px; color: #333; font-weight: 600;">
                                            {{ $task->area?->name ?? '-' }} / <span style="color: #007bff;">{{ $task->subArea?->name ?? '-' }}</span>
                                        </td>
                                    </tr>

                                    <tr>
                                        <td style="padding-bottom: 12px; font-size: 14px; color: #888;">{{ __('ui.task_unit') }}</td>
                                        <td style="padding-bottom: 12px; font-size: 14px; color: #333; font-weight: 600;">
                                            {{ $task->unit?->name ?? '-' }}
                                        </td>
                                    </tr>

                                    <tr>
                                        <td style="padding-bottom: 12px; font-size: 14px; color: #888;">{{ __('ui.type') }} / {{ __('ui.priority') }} / {{ __('ui.sla') }}</td>
                                        <td style="padding-bottom: 12px;">
                                            @php
                                                // SLA Politika Sorgusu
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

                                                // Enum Renk Eşleştirmesi (integer değerlere göre)
                                                $priorityStyles = match($task->priority) {
                                                    \App\Enums\TaskPriorityEnum::Low => ['bg' => '#d4edda', 'text' => '#155724'],    // Success
                                                    \App\Enums\TaskPriorityEnum::Medium => ['bg' => '#e7f3ff', 'text' => '#007bff'], // Primary
                                                    \App\Enums\TaskPriorityEnum::High => ['bg' => '#fff3cd', 'text' => '#856404'],   // Warning
                                                    \App\Enums\TaskPriorityEnum::Urgent => ['bg' => '#f8d7da', 'text' => '#721c24'], // Danger
                                                    default => ['bg' => '#f1f3f5', 'text' => '#6c757d'],
                                                };
                                            @endphp

                                            <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; background-color: #f1f3f5; color: #495057; font-size: 12px; font-weight: bold; margin-right: 5px; border: 1px solid #dee2e6;">
                                                {{ $task->type_id->getLabel() }}
                                            </span>

                                            <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; background-color: {{ $priorityStyles['bg'] }}; color: {{ $priorityStyles['text'] }}; font-size: 12px; font-weight: bold; margin-right: 5px; border: 1px solid rgba(0,0,0,0.05);">
                                                {{ $task->priority->getLabel() }}
                                            </span>

                                            <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; background-color: #ffffff; color: #636e72; font-size: 12px; font-weight: bold; border: 1px solid #dfe6e9;">
                                                <small style="font-weight: normal; opacity: 0.8; margin-right: 4px;">{{ __('ui.sla') }}:</small> {{ $slaText }}
                                            </span>
                                        </td>
                                    </tr>

                                    <tr>
                                        <td style="padding-bottom: 12px; font-size: 14px; color: #888;">{{ __('ui.assigned_by') }}</td>
                                        <td style="padding-bottom: 12px; font-size: 14px; color: #333;">
                                            <strong>{{ $assigned_by?->name }}</strong>
                                            <span style="color: #bbb; margin: 0 5px;">|</span>
                                            <span style="color: #666;">{{ $task->task_date->format('d.m.Y') }}</span>
                                        </td>
                                    </tr>
                                </table>

                                <div style="margin-top: 15px; padding: 15px; background-color: #fdfdfe; border: 1px dashed #d1d8dd; border-radius: 6px;">
                                    <strong style="display: block; margin-bottom: 5px; font-size: 13px; color: #888; text-transform: uppercase;">{{ __('ui.description') }}</strong>
                                    <div style="font-size: 14px; color: #555; font-style: italic;">
                                        "{{ $task->description }}"
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div style="text-align: center; margin-top: 40px; margin-bottom: 20px;">
                            <a href="{{ config('app.url') . '/tasks/' . $task->id }}" target="_blank" style="display: inline-block; padding: 14px 35px; font-size: 16px; color: #ffffff; background-color: #007bff; border-radius: 8px; text-decoration: none; font-weight: bold; box-shadow: 0 4px 12px rgba(0,123,255,0.3);">
                                {{ __('ui.view') }}
                            </a>
                        </div>

                        <p style="font-size: 14px; text-align: center; color: #888; margin-top: 30px;">
                            {{ __('ui.enjoy_your_work') }} <br>
                            <strong>{{__('ui.best_regards')}}</strong>
                        </p>
                    </td>
                </tr>

                <tr>
                    <td align="center" style="background-color: #f1f3f5; padding: 25px; border-top: 1px solid #e9ecef;">
                        <p style="margin: 0; font-size: 12px; color: #999; line-height: 1.5;">
                            {{ __('ui.footer_message') }}<br>
                            &copy; {{ date('Y') }} <strong>{{ __('ui.gursoy_group') }}</strong>. Tüm hakları saklıdır.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

</body>
</html>