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
      left: 0;
      top: 18%;
      width: 300px;
      height: 150px;
      --drone-scale: 1;
      --drone-bank: -2deg;
      animation: fly 13s linear infinite;
      opacity: .92;
      filter: drop-shadow(0 18px 28px rgba(0, 0, 0, .48));
      transform-origin: center;
      will-change: transform;
    }

    .drone-lane:nth-child(2) { top: 42%; animation-duration: 17s; animation-delay: -7s; --drone-scale: .78; --drone-bank: 3deg; opacity: .7; }
    .drone-lane:nth-child(3) { top: 70%; animation-duration: 19s; animation-delay: -12s; --drone-scale: .92; --drone-bank: -4deg; opacity: .74; }

    .rotor-blur {
      transform-origin: center;
      transform-box: fill-box;
      animation: rotor .22s linear infinite;
      opacity: .76;
    }

    .rotor-blade {
      transform-origin: center;
      transform-box: fill-box;
      animation: rotor .16s linear infinite;
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
      from { transform: translate3d(-340px, 0, 0) scale(var(--drone-scale)) rotate(var(--drone-bank)); }
      to { transform: translate3d(calc(100vw + 340px), 0, 0) scale(var(--drone-scale)) rotate(var(--drone-bank)); }
    }

    @keyframes rotor {
      to { rotate: 360deg; }
    }

    @keyframes pulse {
      0%, 100% { transform: scale(1); opacity: .95; }
      50% { transform: scale(1.28); opacity: .62; }
    }

    @media (max-width: 680px) {
      body {
        min-height: 100svh;
        overflow-y: auto;
        place-items: center;
        padding: 18px;
      }

      body::before {
        background-size: 44px 44px;
        mask-image: linear-gradient(180deg, #000, transparent 82%);
      }

      main {
        width: min(100%, 420px);
        padding: 22px 20px 24px;
      }

      .status {
        font-size: 11px;
        gap: 8px;
      }

      h1 {
        max-width: 100%;
        margin: 18px 0 12px;
        font-size: clamp(30px, 10vw, 42px);
        line-height: 1.02;
      }

      p {
        font-size: 15px;
        line-height: 1.5;
      }

      .drone-lane {
        width: 230px;
        height: 115px;
        top: 12%;
        opacity: .46;
        filter: drop-shadow(0 12px 18px rgba(0, 0, 0, .42));
      }

      .drone-lane:nth-child(2) { top: 76%; opacity: .28; }
      .drone-lane:nth-child(3) { display: none; }
    }

    @media (prefers-reduced-motion: reduce) {
      .drone-lane, .rotor-blur, .rotor-blade, .status::before { animation: none; }
      .drone-lane { transform: translate3d(18vw, 0, 0) scale(var(--drone-scale)) rotate(var(--drone-bank)); }
      .drone-lane:nth-child(2), .drone-lane:nth-child(3) { display: none; }
    }
  </style>
</head>
<body>
  <div class="sky" aria-hidden="true">
    @for ($i = 0; $i < 3; $i++)
      <svg class="drone-lane" viewBox="0 0 300 150" role="img" aria-label="">
        <defs>
          <linearGradient id="drone-body-{{ $i }}" x1="95" x2="205" y1="44" y2="102" gradientUnits="userSpaceOnUse">
            <stop stop-color="#f4f7f9"/>
            <stop offset=".46" stop-color="#9eaab4"/>
            <stop offset="1" stop-color="#2b333b"/>
          </linearGradient>
          <radialGradient id="rotor-wash-{{ $i }}" cx="50%" cy="50%" r="50%">
            <stop stop-color="#f4fbff" stop-opacity=".58"/>
            <stop offset=".56" stop-color="#a9dfff" stop-opacity=".18"/>
            <stop offset="1" stop-color="#a9dfff" stop-opacity="0"/>
          </radialGradient>
        </defs>
        <path d="M148 84 L82 144 H218 L153 84Z" fill="#80c7ff" opacity=".1"/>
        <g fill="none" stroke="#5f7485" stroke-width="7" stroke-linecap="round" stroke-linejoin="round">
          <path d="M126 64 L69 31"/>
          <path d="M174 64 L231 31"/>
          <path d="M122 83 L62 113"/>
          <path d="M178 83 L238 113"/>
          <path d="M113 107 C132 119 168 119 187 107"/>
        </g>
        <g fill="none" stroke="#dfe8ee" stroke-width="2.5" stroke-linecap="round" opacity=".72">
          <path d="M85 41 L116 58"/>
          <path d="M215 41 L184 58"/>
          <path d="M82 103 L116 86"/>
          <path d="M218 103 L184 86"/>
        </g>
        <g>
          <ellipse class="rotor-blur" cx="54" cy="28" rx="49" ry="11" fill="url(#rotor-wash-{{ $i }})"/>
          <ellipse class="rotor-blur" cx="246" cy="28" rx="49" ry="11" fill="url(#rotor-wash-{{ $i }})"/>
          <ellipse class="rotor-blur" cx="54" cy="118" rx="49" ry="11" fill="url(#rotor-wash-{{ $i }})"/>
          <ellipse class="rotor-blur" cx="246" cy="118" rx="49" ry="11" fill="url(#rotor-wash-{{ $i }})"/>
          <g class="rotor-blade" fill="#eef8ff" opacity=".8">
            <rect x="14" y="25" width="80" height="6" rx="3"/>
            <rect x="206" y="25" width="80" height="6" rx="3"/>
            <rect x="14" y="115" width="80" height="6" rx="3"/>
            <rect x="206" y="115" width="80" height="6" rx="3"/>
          </g>
        </g>
        <g fill="#18212b" stroke="#dce8ef" stroke-width="3">
          <circle cx="54" cy="28" r="12"/>
          <circle cx="246" cy="28" r="12"/>
          <circle cx="54" cy="118" r="12"/>
          <circle cx="246" cy="118" r="12"/>
        </g>
        <path d="M116 58 C119 39 132 29 150 29 C168 29 181 39 184 58 L193 86 C182 101 166 109 150 109 C134 109 118 101 107 86Z" fill="url(#drone-body-{{ $i }})" stroke="#f5f8fa" stroke-width="3"/>
        <path d="M129 57 C135 51 165 51 171 57" fill="none" stroke="#3e4b55" stroke-width="4" stroke-linecap="round" opacity=".75"/>
        <path d="M121 76 H179" stroke="#202a34" stroke-width="3" stroke-linecap="round" opacity=".62"/>
        <rect x="132" y="94" width="36" height="22" rx="8" fill="#101820" stroke="#e8f4ff" stroke-width="2.5"/>
        <circle cx="150" cy="105" r="7" fill="#080d12" stroke="#80c7ff" stroke-width="2.5"/>
        <text x="150" y="72" text-anchor="middle" font-size="12" font-weight="800" fill="#25313b" opacity=".82" font-family="Arial, sans-serif">DJI</text>
        <circle cx="117" cy="82" r="3.5" fill="#ef4444"/>
        <circle cx="183" cy="82" r="3.5" fill="#7dd3a7"/>
        <path d="M137 120 H163" stroke="#cbd7df" stroke-width="3" stroke-linecap="round"/>
      </svg>
    @endfor
  </div>
  <main>
    <span class="status">Onderhoud actief</span>
    <h1>Systeem wordt bijgewerkt</h1>
    <p>De operationele omgeving staat tijdelijk in onderhoud. Deze pagina ververst automatisch zodra de controle is afgerond.</p>
  </main>
</body>
</html>
