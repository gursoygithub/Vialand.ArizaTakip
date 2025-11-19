<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('ui.task_closed') }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .content {
            margin-bottom: 30px;
        }
        .button {
            display: inline-block;
            background-color: #4CAF50;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
            margin: 20px 0;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            font-size: 14px;
            color: #666;
        }
        .info {
            margin: 15px 0;
        }
        .info strong {
            display: inline-block;
            width: 150px;
        }
    </style>
</head>
<body>
<div class="header">
    <h1>{{ __('ui.fault_tracking_panel') }}</h1>
</div>

<div class="content">
    <p>{{ __('ui.hello') }} {{ $user?->name}},</p>

    <p>{{ __('ui.task_closed_message') }}</p>

    <ul>
        <u>{{__('ui.task_details')}}:</u>
        <li>{{ __('ui.task_title') }}: <strong>{{ $task->title }}</strong></li>
        <li>{{ __('ui.task_date') }}: <strong>{{ $task->task_date->format('d.m.Y H:i') }}</strong></li>
        <li>{{ __('ui.assigned_to') }}: <strong>{{ $task->employee->name }}</strong></li>
        <li>{{ __('ui.description') }}: <strong>{{ $task->description }}</strong></li>
    </ul>

    <ul>
        <u>{{__('ui.resolution_details')}}:</u>
        <li>{{ __('ui.closed_by') }}: <strong>{{ $closed_by?->name }}</strong></li>
        <li>{{ __('ui.due_date') }}: <strong>{{ $task->due_date->format('d.m.Y') }}</strong></li>
        <li>{{ __('ui.resolution_notes') }}: <strong>{{ $task->resolution_notes }}</strong></li>
    </ul>
</div>

<p>{{ __('ui.enjoy_your_work') }}</p>
</body>
</html>
