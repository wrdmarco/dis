import { useEffect, useState } from 'react';
import QRCode from 'qrcode';

interface TotpQrCodeProps {
  value?: string | null;
}

export function TotpQrCode({ value }: TotpQrCodeProps) {
  const [dataUrl, setDataUrl] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    async function renderQrCode() {
      if (!value) {
        setDataUrl(null);
        return;
      }

      setError(null);
      try {
        const nextDataUrl = await QRCode.toDataURL(value, {
          errorCorrectionLevel: 'M',
          margin: 2,
          scale: 6,
          color: {
            dark: '#061018',
            light: '#ffffff',
          },
        });

        if (!cancelled) {
          setDataUrl(nextDataUrl);
        }
      } catch {
        if (!cancelled) {
          setError('QR-code kon niet worden gemaakt.');
        }
      }
    }

    void renderQrCode();

    return () => {
      cancelled = true;
    };
  }, [value]);

  if (error) {
    return <p className="error-text">{error}</p>;
  }

  if (!dataUrl) {
    return null;
  }

  return (
    <div className="totp-qr">
      <img src={dataUrl} alt="MFA QR-code voor Authenticator app" />
      <span>Scan deze QR-code met je Authenticator app.</span>
    </div>
  );
}
