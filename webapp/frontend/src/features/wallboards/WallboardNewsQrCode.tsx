'use client';

import { useEffect, useState } from 'react';
import QRCode from 'qrcode';

interface WallboardNewsQrCodeProps {
  title: string;
  url: string;
}

export function WallboardNewsQrCode({ title, url }: WallboardNewsQrCodeProps) {
  const [dataUrl, setDataUrl] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    setDataUrl(null);
    void QRCode.toDataURL(url, {
      errorCorrectionLevel: 'M',
      margin: 1,
      width: 256,
      color: {
        dark: '#061018',
        light: '#ffffff',
      },
    }).then((nextDataUrl) => {
      if (!cancelled) setDataUrl(nextDataUrl);
    }).catch(() => {
      if (!cancelled) setDataUrl(null);
    });

    return () => {
      cancelled = true;
    };
  }, [url]);

  if (dataUrl === null) return null;

  return (
    <a
      className="wallboard-display__news-qr"
      href={url}
      target="_blank"
      rel="noreferrer"
      tabIndex={-1}
      aria-label={`Scan voor het volledige bericht: ${title}`}
    >
      <img src={dataUrl} alt={`QR-code voor het volledige bericht: ${title}`} />
      <span>Scan voor het hele bericht</span>
    </a>
  );
}
