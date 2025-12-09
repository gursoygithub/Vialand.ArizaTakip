<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('ui.task_closed') }}</title>
    <style>
        @media only screen and (max-width: 600px) {
            .container {
                width: 100% !important;
            }
            .content-padding {
                padding: 15px !important;
            }
        }
    </style>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333333; margin: 0; padding: 0; background-color: #f4f4f4;">

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f4;">
    <tr>
        <td align="center" style="padding: 20px 0;">
            <table class="container" width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); border-collapse: collapse;">

                <tr>
                    <td align="center" style="background-color: #198754; padding: 30px 20px; border-top-left-radius: 8px; border-top-right-radius: 8px;">
                        <h1 style="margin: 0; color: #ffffff; font-size: 26px;">
                            {{ __('ui.fault_tracking_panel') }}
                        </h1>
                        <p style="margin: 5px 0 0 0; color: #ffffff; font-size: 18px; opacity: 0.9;">
                            {{ __('ui.task_closed') }}
                        </p>
                    </td>
                </tr>

                <tr>
                    <td class="content-padding" style="padding: 30px;">
                        <p style="margin-bottom: 20px; font-size: 16px;">
                            {{ __('ui.hello') }} <strong>{{ $user?->name }}</strong>,
                        </p>

                        <p style="margin-bottom: 25px; font-size: 16px;">
                            {{ __('ui.task_closed_message') }}
                        </p>

                        <div style="margin-bottom: 30px; border: 1px solid #dee2e6; border-radius: 6px;">
                            <h3 style="margin: 0; padding: 15px; background-color: #e9ecef; color: #333333; font-size: 18px; border-bottom: 1px solid #dee2e6;">
                                {{__('ui.task_details')}}
                            </h3>
                            <div style="padding: 15px;">

                                <p style="margin: 5px 0;">
                                    <strong style="display: inline-block; width: 150px; color: #555;">{{ __('ui.task_type') }}:</strong>
                                    <span style="font-weight: bold; padding: 3px 10px; border-radius: 4px; background-color: #d1ecf1; color: #0c5460; font-size: 15px;">
                                        {{ $task->type_id->getLabel() }}
                                    </span>
                                </p>

                                <p style="margin: 5px 0;">
                                    <strong style="display: inline-block; width: 150px; color: #555;">{{ __('ui.task_unit') }}:</strong>
                                    <span style="font-weight: bold; padding: 3px 10px; border-radius: 4px; background-color: #e2e3e5; color: #495057; font-size: 15px;">
                                        {{ $task->unit->name }}
                                    </span>
                                </p>

                                <p style="margin: 5px 0;">
                                    <strong style="display: inline-block; width: 150px; color: #555;">{{ __('ui.task_date') }}:</strong>
                                    <span style="font-weight: bold;">{{ $task->task_date->format('d.m.Y H:i') }}</span>
                                </p>
                                <p style="margin: 5px 0;">
                                    <strong style="display: block; margin-bottom: 5px; color: #555;">{{ __('ui.description') }}:</strong>
                                    <span style="display: block; padding-left: 10px; border-left: 2px solid #007bff; color: #6c757d;">{{ $task->description }}</span>
                                </p>
                            </div>
                        </div>

                        <div style="margin-bottom: 30px; border: 1px solid #dee2e6; border-radius: 6px;">
                            <h3 style="margin: 0; padding: 15px; background-color: #d1ecf1; color: #0c5460; font-size: 18px; border-bottom: 1px solid #dee2e6;">
                                {{__('ui.resolution_details')}}
                            </h3>
                            <div style="padding: 15px;">
                                <p style="margin: 5px 0;">
                                    <strong style="display: inline-block; width: 150px; color: #555;">{{ __('ui.closed_by') }}:</strong>
                                    <span style="font-weight: bold;">{{ $closed_by?->name }}</span>
                                </p>
                                <p style="margin: 5px 0;">
                                    <strong style="display: inline-block; width: 150px; color: #555;">{{ __('ui.due_date') }}:</strong>
                                    <span style="font-weight: bold;">{{ $task->due_date->format('d.m.Y') }}</span>
                                </p>
                                <p style="margin: 5px 0;">
                                    <strong style="display: block; margin-bottom: 5px; color: #555;">{{ __('ui.resolution_notes') }}:</strong>
                                    <span style="display: block; padding: 10px; background-color: #f8f9fa; border: 1px solid #ced4da; border-radius: 4px; color: #333333;">{{ $task->resolution_notes }}</span>
                                </p>
                            </div>
                        </div>

                        <table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-top: 20px; margin-bottom: 25px;">
                            <tr>
                                <td align="center">
                                    <a href="{{ config('app.url') }}" target="_blank" style="display: inline-block; padding: 10px 20px; font-size: 15px; color: #ffffff; background-color: #007bff; border-radius: 5px; text-decoration: none; font-weight: bold;">
                                        {{ __('ui.link_to_panel') }}
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="font-size: 16px;">
                            {{ __('ui.enjoy_your_work') }}
                        </p>

                        <p style="font-size: 16px; margin-top: 25px;">
                            {{__('ui.best_regards')}}
                        </p>
                    </td>
                </tr>

                <tr>
                    <td align="center" style="background-color: #e9ecef; padding: 20px; border-bottom-left-radius: 8px; border-bottom-right-radius: 8px;">
                        <p style="margin: 0; font-size: 12px; color: #6c757d;">
                            {{ __('ui.footer_message') }}
                        </p>
                        <p style="margin: 5px 0 0 0; font-size: 12px; color: #6c757d;">
                            &copy; {{ date('Y') }} {{ config('app.name') }}. {{ __('ui.all_rights_reserved') }}
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

</body>
</html>