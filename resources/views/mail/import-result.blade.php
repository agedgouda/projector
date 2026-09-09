<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: sans-serif; color: #374151; padding: 32px; max-width: 600px; margin: 0 auto;">
    <p style="font-size: 15px; line-height: 1.6; white-space: pre-wrap;">{!! preg_replace('/(https?:\/\/\S+)/', '<a href="$1" style="color: #4f46e5;">$1</a>', e($resultMessage)) !!}</p>
</body>
</html>
