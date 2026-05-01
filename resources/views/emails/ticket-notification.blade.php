@php
    $appName = config('app.name');
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:0;}
.container{max-width:600px;margin:40px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);}
.header{background:#1e40af;padding:24px;text-align:center;}
.header h1{color:#fff;margin:0;font-size:20px;letter-spacing:0.5px;}
.ticket-badge{background:#f0f4ff;border-left:4px solid #1e40af;padding:16px 24px;margin:24px 24px 0;}
.ticket-no{font-size:24px;font-weight:bold;color:#1e40af;}
.content{padding:16px 24px 24px;}
.event-box{background:#f8fafc;border-radius:6px;padding:16px;margin:16px 0;font-size:15px;color:#222;}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:16px 0;}
.label{font-size:11px;color:#888;text-transform:uppercase;letter-spacing:0.5px;}
.value{font-size:14px;color:#222;margin-top:3px;font-weight:500;}
.cta{text-align:center;margin:24px 0;}
.cta a{background:#1e40af;color:#fff;padding:12px 32px;border-radius:6px;text-decoration:none;font-weight:bold;font-size:15px;}
.note-box{background:#fffbeb;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:4px;margin:12px 0;}
.footer{background:#f4f4f4;padding:16px 24px;text-align:center;color:#999;font-size:11px;border-top:1px solid #e5e7eb;}
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>🔧 {{ $appName }}</h1>
  </div>
  <div class="ticket-badge">
    <div class="label">Talep No</div>
    <div class="ticket-no">{{ $ticketNo }}</div>
  </div>
  <div class="content">
    <div class="event-box">{{ $eventDescription }}</div>
    <div class="grid">
      @isset($area)
      <div><div class="label">Bölge</div><div class="value">{{ $area }}</div></div>
      @endisset
      @isset($priority)
      <div><div class="label">Öncelik</div><div class="value">{{ $priority }}</div></div>
      @endisset
      @isset($status)
      <div><div class="label">Durum</div><div class="value">{{ $status }}</div></div>
      @endisset
      @isset($assignee)
      <div><div class="label">Atanan Personel</div><div class="value">{{ $assignee }}</div></div>
      @endisset
    </div>
    @if(!empty($note))
    <div class="note-box">
      <div class="label">Not</div>
      <div style="margin-top:6px;color:#222;">{{ $note }}</div>
    </div>
    @endif
    <div class="cta">
      <a href="{{ $url }}">Talebi Görüntüle →</a>
    </div>
  </div>
  <div class="footer">
    Bu bildirim <strong>{{ $appName }}</strong> tarafından otomatik olarak gönderilmiştir. Lütfen yanıtlamayınız.
  </div>
</div>
</body>
</html>
