<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Email previews</title>
</head>
<body style="margin:0;padding:32px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1b1b18;background:#FDFDFC;">
    <h1 style="margin:0 0 16px;font-size:20px;">Email previews</h1>
    <ul style="margin:0;padding-left:20px;line-height:28px;">
        @foreach ($previews as $preview)
            <li><a href="{{ $preview['url'] }}" style="color:#f53003;">{{ $preview['name'] }}</a></li>
        @endforeach
    </ul>
</body>
</html>
