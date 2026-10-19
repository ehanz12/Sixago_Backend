<!DOCTYPE html>
<html lang="id">
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; color: #1a2b1f; line-height: 1.6;">
  <div style="max-width: 560px; margin: 0 auto; border: 1px solid #e3ecdf; border-radius: 12px; overflow: hidden;">
    <div style="background: #1A5D2C; color: #fff; padding: 16px 24px;">
      <strong>SixAgo Travel Governance</strong>
    </div>
    <div style="padding: 24px;">
      <p>{{ $greeting }}</p>
      <p>{{ $body }}</p>
      @if($ctaUrl)
        <p><a href="{{ $ctaUrl }}" style="display: inline-block; background: #28B446; color: #fff; padding: 10px 22px; border-radius: 999px; text-decoration: none;">{{ $ctaLabel ?? 'Buka SixAgo' }}</a></p>
      @endif
      <p style="font-size: 12px; color: #6b7a68;">Email otomatis — mohon tidak membalas langsung.</p>
    </div>
  </div>
</body>
</html>
