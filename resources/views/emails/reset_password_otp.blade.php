<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password - MOVA POS</title>
  <style>
    body {
      margin: 0;
      padding: 0;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
      background-color: #0d1117;
      color: #e6edf3;
    }
    .email-wrapper {
      width: 100%;
      background-color: #0d1117;
      padding: 40px 20px;
    }
    .email-card {
      max-width: 520px;
      margin: 0 auto;
      background: #161b22;
      border: 1px solid #30363d;
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
    }
    .header {
      background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
      padding: 30px 30px 24px;
      text-align: center;
      border-bottom: 1px solid #30363d;
    }
    .logo-badge {
      display: inline-block;
      background: linear-gradient(135deg, #6366f1, #3b82f6);
      color: #ffffff;
      font-weight: 800;
      font-size: 20px;
      letter-spacing: 1px;
      padding: 10px 20px;
      border-radius: 12px;
      margin-bottom: 12px;
    }
    .header-title {
      font-size: 20px;
      font-weight: 700;
      color: #f0f6fc;
      margin: 0;
    }
    .content {
      padding: 30px;
    }
    .greeting {
      font-size: 16px;
      color: #f0f6fc;
      margin-bottom: 14px;
    }
    .message {
      font-size: 14px;
      color: #8b949e;
      line-height: 1.6;
      margin-bottom: 24px;
    }
    .otp-container {
      background: #0d1117;
      border: 2px dashed #6366f1;
      border-radius: 12px;
      padding: 20px;
      text-align: center;
      margin: 24px 0;
    }
    .otp-label {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 1.5px;
      color: #818cf8;
      font-weight: 600;
      margin-bottom: 8px;
    }
    .otp-code {
      font-size: 36px;
      font-weight: 800;
      letter-spacing: 8px;
      color: #38bdf8;
      font-family: 'Courier New', Courier, monospace;
      margin: 0;
    }
    .badge-expiry {
      display: inline-block;
      background: rgba(245, 158, 11, 0.15);
      border: 1px solid rgba(245, 158, 11, 0.4);
      color: #fbbf24;
      font-size: 12px;
      padding: 4px 10px;
      border-radius: 20px;
      margin-top: 10px;
    }
    .security-notice {
      background: rgba(239, 68, 68, 0.1);
      border-left: 4px solid #ef4444;
      padding: 12px 14px;
      border-radius: 6px;
      font-size: 12px;
      color: #fca5a5;
      line-height: 1.5;
      margin-top: 20px;
    }
    .footer {
      background: #0d1117;
      padding: 20px 30px;
      text-align: center;
      border-top: 1px solid #21262d;
      font-size: 12px;
      color: #6e7681;
    }
  </style>
</head>
<body>
  <div class="email-wrapper">
    <div class="email-card">
      <div class="header">
        <div class="logo-badge">MOVA POS</div>
        <h1 class="header-title">Permintaan Reset Password</h1>
      </div>

      <div class="content">
        <div class="greeting">Halo, <strong>{{ $userName }}</strong></div>
        <p class="message">
          Kami menerima permintaan untuk mereset kata sandi akun MOVA POS Anda. Gunakan kode verifikasi (OTP) 6-digit di bawah ini untuk melanjutkan:
        </p>

        <div class="otp-container">
          <div class="otp-label">Kode Verifikasi Anda</div>
          <div class="otp-code">{{ $otpCode }}</div>
          <div class="badge-expiry">⏱️ Berlaku selama {{ $expiryMinutes }} Menit</div>
        </div>

        <p class="message" style="margin-bottom: 0;">
          Masukkan kode tersebut pada halaman verifikasi di browser Anda untuk membuat password baru.
        </p>

        <div class="security-notice">
          <strong>⚠️ Peringatan Keamanan:</strong> Jangan berikan kode ini kepada siapa pun, termasuk pihak yang mengaku sebagai staf MOVA POS. Jika Anda tidak merasa meminta reset password, abaikan email ini dan akun Anda tetap aman.
        </div>
      </div>

      <div class="footer">
        © {{ date('Y') }} MOVA POS — Move Your Business Forward.<br>
        Email ini dikirimkan secara otomatis oleh sistem keamanan MOVA POS.
      </div>
    </div>
  </div>
</body>
</html>
