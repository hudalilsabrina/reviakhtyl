<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Suspended - {{ config('app.name') }}</title>
    @vite(['resources/scripts/index.tsx'])
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .suspension-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 90%;
            padding: 40px;
            text-align: center;
        }
        .suspension-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: #ef4444;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .suspension-icon svg {
            width: 40px;
            height: 40px;
            color: white;
        }
        h1 {
            color: #1f2937;
            font-size: 28px;
            margin: 0 0 10px;
            font-weight: 600;
        }
        .subtitle {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 30px;
        }
        .reason-box {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            text-align: left;
        }
        .reason-label {
            font-size: 12px;
            font-weight: 600;
            color: #991b1b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        .reason-text {
            color: #dc2626;
            font-size: 15px;
            line-height: 1.6;
            word-wrap: break-word;
        }
        .info-box {
            background: #f3f4f6;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
            text-align: left;
            font-size: 14px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .info-row:last-child {
            margin-bottom: 0;
        }
        .info-label {
            color: #6b7280;
            font-weight: 500;
        }
        .info-value {
            color: #1f2937;
            font-weight: 600;
        }
        .permanent-badge {
            display: inline-block;
            background: #dc2626;
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .temporary-badge {
            display: inline-block;
            background: #f59e0b;
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .contact-info {
            margin-top: 30px;
            padding-top: 30px;
            border-top: 1px solid #e5e7eb;
            color: #6b7280;
            font-size: 14px;
            line-height: 1.6;
        }
        .btn-logout {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 24px;
            background: #4f46e5;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 500;
            transition: background 0.2s;
        }
        .btn-logout:hover {
            background: #4338ca;
        }
    </style>
</head>
<body>
    <div class="suspension-container">
        <div class="suspension-icon">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
            </svg>
        </div>

        <h1>Account Suspended</h1>
        <p class="subtitle">Your account access has been temporarily disabled</p>

        <div class="reason-box">
            <div class="reason-label">Suspension Reason</div>
            <div class="reason-text">{{ $reason ?? 'No reason provided.' }}</div>
        </div>

        <div class="info-box">
            @if($suspended_at)
            <div class="info-row">
                <span class="info-label">Suspended On:</span>
                <span class="info-value">{{ \Carbon\Carbon::parse($suspended_at)->format('M j, Y g:i A') }}</span>
            </div>
            @endif

            <div class="info-row">
                <span class="info-label">Status:</span>
                <span class="info-value">
                    @if($suspend_until)
                        <span class="temporary-badge">Temporary</span>
                    @else
                        <span class="permanent-badge">Permanent</span>
                    @endif
                </span>
            </div>

            @if($suspend_until)
            <div class="info-row">
                <span class="info-label">Expires:</span>
                <span class="info-value">{{ \Carbon\Carbon::parse($suspend_until)->format('M j, Y g:i A') }}</span>
            </div>
            @endif
        </div>

        <div class="contact-info">
            <strong>What can you do?</strong><br>
            If you believe this suspension was made in error or would like to appeal, please contact support. You will not be able to access your account or servers until this suspension is lifted.
        </div>

        <a href="{{ route('auth.logout') }}" class="btn-logout" 
           onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
            Return to Login
        </a>

        <form id="logout-form" action="{{ route('auth.logout') }}" method="POST" style="display: none;">
            @csrf
        </form>
    </div>
</body>
</html>
