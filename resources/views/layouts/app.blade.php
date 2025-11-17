<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Misafir Formu')</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">

<div class="bg-white shadow-lg rounded-2xl flex w-full max-w-4xl overflow-hidden">
    <!-- Sol Logo Alanı -->
    <div class="bg-white-600 text-white flex flex-col items-center justify-center px-6 py-8 w-1/3">
        <img src="{{ asset('vialand-logo.png') }}" alt="Logo" class="w-60 h-60 mb-4">
        <h1 class="text-xl font-bold text-center text-gray-600">@yield('sidebar-title', 'Misafir Formu')</h1>
    </div>

    <!-- İçerik Alanı -->
    <div class="w-2/3 p-8">
        @yield('content')
    </div>
</div>

</body>
</html>
