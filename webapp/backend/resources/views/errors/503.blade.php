<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="refresh" content="20">
  <title>D.I.S onderhoud</title>
  <style>
    :root {
      color-scheme: dark;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      --bg: #070d16;
      --surface: rgba(13, 20, 31, .9);
      --border: #223349;
      --blue: #80c7ff;
      --green: #7dd3a7;
      --text: #f8fbff;
      --muted: #aebdd0;
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      min-height: 100vh;
      overflow: hidden;
      display: grid;
      place-items: center;
      background:
        radial-gradient(circle at 18% 18%, rgba(128, 199, 255, .16), transparent 30%),
        radial-gradient(circle at 84% 74%, rgba(125, 211, 167, .1), transparent 32%),
        linear-gradient(145deg, #060a10 0%, var(--bg) 46%, #0d1724 100%);
      color: var(--text);
    }

    body::before {
      content: "";
      position: fixed;
      inset: 0;
      pointer-events: none;
      background:
        linear-gradient(90deg, rgba(128, 199, 255, .045) 1px, transparent 1px),
        linear-gradient(180deg, rgba(128, 199, 255, .035) 1px, transparent 1px);
      background-size: 64px 64px;
      mask-image: linear-gradient(90deg, transparent, #000 16%, #000 84%, transparent);
    }

    .sky {
      position: fixed;
      inset: 0;
      pointer-events: none;
      overflow: hidden;
    }

    .drone-lane {
      position: absolute;
      left: -260px;
      top: 18%;
      width: 240px;
      height: 110px;
      animation: fly 13s linear infinite;
      opacity: .92;
      filter: drop-shadow(0 18px 28px rgba(0, 0, 0, .48));
    }

    .drone-lane:nth-child(2) { top: 42%; animation-duration: 17s; animation-delay: -7s; transform: scale(.78); opacity: .7; }
    .drone-lane:nth-child(3) { top: 70%; animation-duration: 19s; animation-delay: -12s; transform: scale(.92); opacity: .74; }

    .rotor-disc {
      transform-origin: center;
      animation: rotor .3s linear infinite;
      opacity: .76;
    }

    main {
      position: relative;
      z-index: 1;
      width: min(760px, calc(100vw - 40px));
      border: 1px solid rgba(128, 199, 255, .24);
      border-radius: 8px;
      background:
        linear-gradient(180deg, rgba(17, 29, 43, .94), rgba(9, 13, 18, .96)),
        var(--surface);
      box-shadow:
        0 28px 96px rgba(0, 0, 0, .52),
        inset 0 1px 0 rgba(255, 255, 255, .04);
      overflow: hidden;
      padding: clamp(24px, 5vw, 44px);
    }

    main::before {
      content: "";
      position: absolute;
      inset: 0 0 auto;
      height: 3px;
      background: linear-gradient(90deg, var(--blue), var(--green), transparent);
    }

    .status {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      color: var(--blue);
      font-size: 12px;
      font-weight: 800;
      text-transform: uppercase;
    }

    .status::before {
      content: "";
      width: 9px;
      height: 9px;
      border-radius: 999px;
      background: var(--green);
      box-shadow: 0 0 0 6px rgba(125, 211, 167, .12);
      animation: pulse 1.6s ease-in-out infinite;
    }

    h1 {
      max-width: 12ch;
      margin: 22px 0 12px;
      font-size: clamp(38px, 7vw, 72px);
      line-height: .94;
    }

    p {
      max-width: 58ch;
      margin: 0;
      color: var(--muted);
      font-size: clamp(16px, 2vw, 18px);
      line-height: 1.55;
    }

    @keyframes fly {
      from { translate: -12vw 0; }
      to { translate: calc(100vw + 300px) 0; }
    }

    @keyframes rotor {
      to { rotate: 360deg; }
    }

    @keyframes pulse {
      0%, 100% { transform: scale(1); opacity: .95; }
      50% { transform: scale(1.28); opacity: .62; }
    }

    @media (max-width: 680px) {
      body { place-items: end center; padding: 18px; }
      main { width: 100%; padding: 24px; }
      .drone-lane:nth-child(2) { top: 30%; }
      .drone-lane:nth-child(3) { display: none; }
    }

    @media (prefers-reduced-motion: reduce) {
      .drone-lane, .rotor-disc, .status::before { animation: none; }
      .drone-lane { translate: 18vw 0; }
      .drone-lane:nth-child(2), .drone-lane:nth-child(3) { display: none; }
    }
  </style>
</head>
<body>
  <div class="sky" aria-hidden="true">
    @for ($i = 0; $i < 3; $i++)
      <svg class="drone-lane" viewBox="0 0 240 110" role="img" aria-label="">
        <defs>
          <linearGradient id="drone-body-{{ $i }}" x1="64" x2="174" y1="35" y2="76" gradientUnits="userSpaceOnUse">
            <stop stop-color="#e8f4ff"/>
            <stop offset=".42" stop-color="#9bb3c9"/>
            <stop offset="1" stop-color="#26384a"/>
          </linearGradient>
          <radialGradient id="rotor-wash-{{ $i }}" cx="50%" cy="50%" r="50%">
            <stop stop-color="#f4fbff" stop-opacity=".48"/>
            <stop offset=".58" stop-color="#a9dfff" stop-opacity=".16"/>
            <stop offset="1" stop-color="#a9dfff" stop-opacity="0"/>
          </radialGradient>
        </defs>
        <path d="M118 61 L64 108 H176 L124 61Z" fill="#80c7ff" opacity=".12"/>
        <g class="rotor-disc">
          <ellipse cx="43" cy="24" rx="37" ry="9" fill="url(#rotor-wash-{{ $i }})"/>
          <ellipse cx="197" cy="24" rx="37" ry="9" fill="url(#rotor-wash-{{ $i }})"/>
          <ellipse cx="43" cy="80" rx="37" ry="9" fill="url(#rotor-wash-{{ $i }})"/>
          <ellipse cx="197" cy="80" rx="37" ry="9" fill="url(#rotor-wash-{{ $i }})"/>
        </g>
        <g fill="none" stroke="#8fb0c9" stroke-width="5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M93 50 L52 27"/>
          <path d="M147 50 L188 27"/>
          <path d="M92 61 L52 78"/>
          <path d="M148 61 L188 78"/>
          <path d="M88 86 C103 96 137 96 152 86"/>
        </g>
        <g fill="#162131" stroke="#d6edf8" stroke-width="2.5">
          <circle cx="43" cy="24" r="10"/>
          <circle cx="197" cy="24" r="10"/>
          <circle cx="43" cy="80" r="10"/>
          <circle cx="197" cy="80" r="10"/>
        </g>
        <path d="M88 48 C92 33 106 26 120 26 C134 26 148 33 152 48 L158 65 C148 75 133 80 120 80 C107 80 92 75 82 65Z" fill="url(#drone-body-{{ $i }})" stroke="#e8f4ff" stroke-width="2.5"/>
        <path d="M98 48 H142" stroke="#334a5f" stroke-width="3" stroke-linecap="round" opacity=".7"/>
        <rect x="107" y="70" width="26" height="17" rx="7" fill="#111a26" stroke="#d6edf8" stroke-width="2"/>
        <circle cx="120" cy="79" r="5" fill="#0c1119" stroke="#80c7ff" stroke-width="2"/>
        <circle cx="89" cy="60" r="3" fill="#ef4444"/>
        <circle cx="151" cy="60" r="3" fill="#7dd3a7"/>
        <g fill="none" stroke="#eef8ff" stroke-width="2" stroke-linecap="round" opacity=".82">
          <path d="M15 24 H71"/>
          <path d="M169 24 H225"/>
          <path d="M15 80 H71"/>
          <path d="M169 80 H225"/>
        </g>
      </svg>
    @endfor
  </div>
  <main>
    <span class="status">Onderhoud actief</span>
    <h1>Drone Inzet Systeem wordt bijgewerkt</h1>
    <p>De operationele omgeving staat tijdelijk in onderhoud. Deze pagina ververst automatisch zodra de controle is afgerond.</p>
  </main>
</body>
</html>
