<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: sans-serif; color: #374151; padding: 32px; max-width: 600px; margin: 0 auto;">
    <p style="font-size: 16px; line-height: 1.6;">
        {{ $inviter->name }} of {{ $organization->name }} has invited you to join their team on Projector.
    </p>
    <p style="margin-top: 24px;">
        <a href="{{ $link }}"
           style="display: inline-block; background-color: #4f46e5; color: #ffffff; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px;">
            Accept Invitation
        </a>
    </p>
    <p style="margin-top: 24px; font-size: 13px; color: #9ca3af;">
        This invitation expires in 7 days. If you did not expect this invitation, you can ignore this email.
    </p>
    <p style="margin-top: 8px; font-size: 12px; color: #d1d5db;">
        Or copy this link: {{ $link }}
    </p>
</body>
</html>
