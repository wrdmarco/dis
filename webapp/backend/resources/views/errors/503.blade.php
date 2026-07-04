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
      left: -180px;
      top: 18%;
      width: 170px;
      height: 70px;
      animation: fly 13s linear infinite;
      opacity: .92;
      filter: drop-shadow(0 10px 22px rgba(0, 0, 0, .42));
    }

    .drone-lane:nth-child(2) { top: 42%; animation-duration: 17s; animation-delay: -7s; transform: scale(.78); opacity: .7; }
    .drone-lane:nth-child(3) { top: 70%; animation-duration: 19s; animation-delay: -12s; transform: scale(.92); opacity: .74; }

    .rotor {
      transform-origin: center;
      animation: rotor .36s linear infinite;
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
      to { translate: calc(100vw + 220px) 0; }
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
      .drone-lane, .rotor, .status::before { animation: none; }
      .drone-lane { translate: 18vw 0; }
      .drone-lane:nth-child(2), .drone-lane:nth-child(3) { display: none; }
    }
  </style>
</head>
<body>
  <div class="sky" aria-hidden="true">
    @for ($i = 0; $i < 3; $i++)
      <svg class="drone-lane" viewBox="0 0 170 70" role="img" aria-label="">
        <path d="M82 40 L52 70 H118 L88 40Z" fill="#80c7ff" opacity=".22"/>
        <g fill="none" stroke="#80c7ff" stroke-width="4" stroke-linecap="round">
          <path d="M50 34 H120"/>
          <path d="M85 22 V46"/>
          <path d="M62 34 L42 20"/>
          <path d="M108 34 L128 20"/>
        </g>
        <g fill="#101927" stroke="#d6f3ff" stroke-width="3">
          <ellipse cx="85" cy="34" rx="23" ry="11"/>
          <circle cx="42" cy="20" r="7"/>
          <circle cx="128" cy="20" r="7"/>
          <circle cx="42" cy="49" r="7"/>
          <circle cx="128" cy="49" r="7"/>
        </g>
        <g class="rotor" fill="none" stroke="#d6f3ff" stroke-width="2">
          <path d="M23 20 H61"/>
          <path d="M109 20 H147"/>
          <path d="M23 49 H61"/>
          <path d="M109 49 H147"/>
        </g>
        <circle cx="85" cy="34" r="4" fill="#7dd3a7"/>
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
