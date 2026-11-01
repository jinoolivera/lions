<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Lions ERP</title>
    <link rel="icon" type="image/jpeg" href="{{ asset('images/lions-logo.jpeg') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/lions-logo.jpeg') }}">
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
</head>
<body>
    <div id="app"></div>
</body>
</html>
