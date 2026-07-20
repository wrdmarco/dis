<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#090d12">
  <meta http-equiv="refresh" content="20">
  <title>D.I.S. onderhoud</title>
  <style>
    :root {
      color-scheme: dark;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      --dis-bg: #090d12;
      --dis-text: #f5f9ff;
      --dis-blue: #38bdf8;
      --dis-blue-soft: rgba(14, 165, 233, .16);
      --dis-green: #7dd3a7;
    }

    * { box-sizing: border-box; }
    [hidden] { display: none !important; }

    html, body { min-height: 100%; }

    body {
      margin: 0;
      min-width: 280px;
      min-height: 100vh;
      min-height: 100svh;
      overflow-x: hidden;
      background:
        radial-gradient(circle at 50% 42%, rgba(56, 189, 248, .13), transparent 29rem),
        radial-gradient(circle at 12% 90%, rgba(125, 211, 167, .055), transparent 26rem),
        linear-gradient(145deg, #070a0e 0%, var(--dis-bg) 48%, #0c141d 100%);
      color: var(--dis-text);
    }

    body::before {
      content: "";
      position: fixed;
      z-index: 3;
      inset: 0 0 auto;
      height: 5px;
      background: linear-gradient(90deg, transparent, var(--dis-blue) 22%, #80c7ff 50%, var(--dis-blue) 78%, transparent);
      box-shadow: 0 0 30px rgba(56, 189, 248, .36);
      pointer-events: none;
    }

    body::after {
      content: "";
      position: fixed;
      inset: 0;
      background-image:
        linear-gradient(rgba(128, 199, 255, .025) 1px, transparent 1px),
        linear-gradient(90deg, rgba(128, 199, 255, .025) 1px, transparent 1px);
      background-size: 72px 72px;
      mask-image: linear-gradient(90deg, transparent, #000 18%, #000 82%, transparent);
      pointer-events: none;
    }

    .maintenance-shell {
      position: relative;
      z-index: 1;
      min-height: 100vh;
      min-height: 100svh;
      display: grid;
      grid-template-rows: auto 1fr;
      gap: clamp(22px, 4vh, 48px);
      padding: clamp(22px, 3.4vw, 58px);
    }

    .topbar {
      width: min(100%, 1600px);
      margin: 0 auto;
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 18px;
    }

    .state-pill {
      display: inline-flex;
      flex: 0 0 auto;
      align-items: center;
      gap: 9px;
      border: 1px solid rgba(56, 189, 248, .3);
      border-radius: 999px;
      background: rgba(8, 20, 30, .72);
      padding: 9px 14px;
      color: #bce9ff;
      font-size: 12px;
      font-weight: 800;
      letter-spacing: .08em;
      text-transform: uppercase;
    }

    .state-pill::before {
      content: "";
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: var(--dis-blue);
      box-shadow: 0 0 0 5px rgba(56, 189, 248, .12);
    }

    .takeover {
      width: min(100%, 920px);
      margin: auto;
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
    }

    .update-icon {
      width: clamp(68px, 6vw, 90px);
      height: clamp(68px, 6vw, 90px);
      display: grid;
      place-items: center;
      margin-bottom: clamp(22px, 3vh, 36px);
      border: 1px solid rgba(56, 189, 248, .34);
      border-radius: 50%;
      background: var(--dis-blue-soft);
      color: #8cdbff;
      box-shadow: 0 0 54px rgba(56, 189, 248, .12), inset 0 1px 0 rgba(255, 255, 255, .05);
    }

    .update-icon svg {
      width: 45%;
      height: 45%;
      animation: rotate-update 2.8s linear infinite;
    }

    h1 {
      max-width: 16ch;
      margin: 0;
      font-size: clamp(36px, 5.7vw, 84px);
      font-weight: 760;
      letter-spacing: -.045em;
      line-height: .98;
      text-wrap: balance;
    }

    .countdown {
      position: relative;
      width: clamp(152px, 12vw, 190px);
      aspect-ratio: 1;
      display: grid;
      place-items: center;
      margin-top: clamp(30px, 4vh, 48px);
    }

    .countdown svg {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      filter: drop-shadow(0 0 11px rgba(56, 189, 248, .13));
    }

    .countdown-track,
    .countdown-progress {
      fill: none;
      stroke-width: 2.2;
    }

    .countdown-track { stroke: rgba(148, 173, 201, .14); }

    .countdown-progress {
      stroke: var(--dis-blue);
      stroke-linecap: round;
      transition: stroke-dashoffset .45s linear, stroke .2s ease;
    }

    .countdown[data-state="waiting"] .countdown-progress { stroke: #80c7ff; }

    .countdown-copy {
      position: relative;
      z-index: 1;
      width: 74%;
      display: grid;
      gap: 6px;
      place-items: center;
    }

    .countdown-copy strong {
      font-size: clamp(29px, 3vw, 43px);
      font-variant-numeric: tabular-nums;
      letter-spacing: -.04em;
      line-height: 1;
    }

    .countdown[data-state="waiting"] .countdown-copy strong {
      font-size: clamp(16px, 1.35vw, 20px);
      letter-spacing: 0;
      line-height: 1.16;
    }

    .countdown-copy span {
      color: #8fa2b7;
      font-size: clamp(10px, .72vw, 12px);
      font-weight: 750;
      letter-spacing: .07em;
      line-height: 1.25;
      text-transform: uppercase;
    }

    .recovery {
      display: inline-flex;
      align-items: center;
      gap: 9px;
      margin-top: clamp(24px, 3.2vh, 38px);
      border: 1px solid rgba(125, 211, 167, .2);
      border-radius: 999px;
      background: rgba(125, 211, 167, .06);
      padding: 8px 13px 8px 9px;
      color: #b9d8ca;
    }

    .recovery-icon {
      position: relative;
      width: 24px;
      height: 24px;
      flex: 0 0 24px;
      display: grid;
      place-items: center;
      border-radius: 50%;
      background: rgba(125, 211, 167, .11);
    }

    .recovery-icon::before {
      content: "";
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: var(--dis-green);
      box-shadow: 0 0 14px rgba(125, 211, 167, .55);
    }

    .recovery strong { font-size: 12px; line-height: 1.2; }

    @keyframes rotate-update { to { transform: rotate(360deg); } }

    @media (max-width: 620px) {
      .maintenance-shell { gap: 20px; padding: 20px 18px 18px; }
      .state-pill { padding: 8px 11px; font-size: 10px; }
      .takeover { padding: 12px 0; }
      .update-icon { margin-bottom: 20px; }
      h1 { max-width: 13ch; font-size: clamp(34px, 11vw, 52px); }
      .countdown { width: 146px; margin-top: 28px; }
      .recovery { margin-top: 20px; }
    }

    @media (max-height: 700px) and (min-width: 621px) {
      .maintenance-shell { gap: 16px; padding-top: 20px; padding-bottom: 18px; }
      .update-icon { width: 58px; height: 58px; margin-bottom: 14px; }
      h1 { font-size: clamp(34px, 5.4vw, 58px); }
      .countdown { width: 128px; margin-top: 22px; }
      .recovery { margin-top: 16px; }
    }

    @media (prefers-reduced-motion: reduce) {
      .update-icon svg { animation: none; }
      .countdown-progress { transition: none; }
    }
  </style>
</head>
<body data-maintenance-kind="maintenance" data-started-epoch-seconds="0" data-estimated-duration-seconds="0" data-estimated-completion-epoch-seconds="0">
  <div class="maintenance-shell">
    <header class="topbar">
      <div class="state-pill">Onderhoud</div>
    </header>

    <main class="takeover" aria-labelledby="maintenance-title">
      <div class="update-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <path d="M20 11a8.1 8.1 0 0 0-15.5-2M4 4v5h5"/>
          <path d="M4 13a8.1 8.1 0 0 0 15.5 2M20 20v-5h-5"/>
        </svg>
      </div>
      <h1 id="maintenance-title">D.I.S. is tijdelijk in onderhoud</h1>

      <section class="countdown" id="maintenance-countdown" role="timer" aria-atomic="true" aria-label="Geschatte resterende duur" hidden>
        <svg viewBox="0 0 52 52" aria-hidden="true">
          <circle class="countdown-track" cx="26" cy="26" r="22"/>
          <circle class="countdown-progress" id="countdown-progress" cx="26" cy="26" r="22" pathLength="100" stroke-dasharray="100" stroke-dashoffset="0" transform="rotate(-90 26 26)"/>
        </svg>
        <div class="countdown-copy">
          <strong id="countdown-value">--:--</strong>
          <span id="countdown-label">Geschat resterend</span>
        </div>
      </section>

      <div class="recovery">
        <span class="recovery-icon" aria-hidden="true"></span>
        <strong>Automatisch herstel is actief</strong>
      </div>
    </main>
  </div>
  <script>
    (() => {
      'use strict';

      const body = document.body;
      const kind = body.dataset.maintenanceKind;
      const startedAt = Number(body.dataset.startedEpochSeconds);
      const duration = Number(body.dataset.estimatedDurationSeconds);
      const completion = Number(body.dataset.estimatedCompletionEpochSeconds);
      const title = document.getElementById('maintenance-title');
      const countdown = document.getElementById('maintenance-countdown');
      const value = document.getElementById('countdown-value');
      const label = document.getElementById('countdown-label');
      const progress = document.getElementById('countdown-progress');

      if (kind === 'update') {
        title.textContent = 'Systeem wordt bijgewerkt';
      }

      const hasEstimate = kind === 'update'
        && Number.isInteger(startedAt)
        && Number.isInteger(duration)
        && Number.isInteger(completion)
        && startedAt > 0
        && duration >= 180
        && duration <= 2700
        && completion === startedAt + duration;

      if (!hasEstimate) {
        return;
      }

      const formatDuration = (seconds) => {
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const remainder = seconds % 60;
        if (hours > 0) {
          return `${hours}:${String(minutes).padStart(2, '0')}:${String(remainder).padStart(2, '0')}`;
        }
        return `${minutes}:${String(remainder).padStart(2, '0')}`;
      };

      const updateCountdown = () => {
        const remaining = Math.max(0, Math.ceil(completion - Date.now() / 1000));
        const percentage = Math.max(0, Math.min(100, remaining / duration * 100));
        progress.setAttribute('stroke-dashoffset', String(100 - percentage));

        if (remaining === 0) {
          countdown.dataset.state = 'waiting';
          value.textContent = 'Nog even geduld';
          label.textContent = 'Serverstatus controleren';
          return;
        }

        countdown.dataset.state = 'counting';
        value.textContent = formatDuration(remaining);
        label.textContent = 'Geschat resterend';
      };

      countdown.hidden = false;
      updateCountdown();
      window.setInterval(updateCountdown, 1000);
    })();
  </script>
</body>
</html>
