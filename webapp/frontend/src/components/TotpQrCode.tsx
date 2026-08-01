import { useEffect, useState } from 'react';
import QRCode from 'qrcode';

interface TotpQrCodeProps {
  value?: string | null;
  alt?: string;
  helpText?: string;
  downloadFileName?: string;
  downloadLabel?: string;
}

export function TotpQrCode({
  value,
  alt = 'QR-code',
  helpText = 'Scan deze QR-code.',
  downloadFileName,
  downloadLabel = 'QR-code downloaden',
}: TotpQrCodeProps) {
  const [dataUrl, setDataUrl] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    async function renderQrCode() {
      if (!value) {
        setDataUrl(null);
        setError(null);
        return;
      }

      setDataUrl(null);
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
          setDataUrl(null);
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
      <img src={dataUrl} alt={alt} />
      <span>{helpText}</span>
      {downloadFileName ? (
        <a className="secondary-button" download={downloadFileName} href={dataUrl}>
          {downloadLabel}
        </a>
      ) : null}
    </div>
  );
}
