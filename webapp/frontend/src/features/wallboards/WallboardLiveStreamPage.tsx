'use client';

import { useEffect, useRef, useState } from 'react';
import type Hls from 'hls.js';
import { Loader2, RadioTower, WifiOff } from 'lucide-react';
import { wallboardLiveStreamManifestPath } from './wallboardLiveStream';
import styles from './WallboardLiveStreamPage.module.css';

const RETRY_DELAYS_MILLISECONDS = [1_000, 2_000, 4_000, 8_000, 15_000] as const;
const STALL_RESTART_MILLISECONDS = 10_000;

type PlaybackStatus = 'idle' | 'connecting' | 'live' | 'reconnecting' | 'unsupported';

interface WallboardLiveStreamPageProps {
  name: string;
  running: boolean;
  adminPreview: boolean;
  demoMode: boolean;
}

export function WallboardLiveStreamPage({
  name,
  running,
  adminPreview,
  demoMode,
}: WallboardLiveStreamPageProps) {
  const videoRef = useRef<HTMLVideoElement>(null);
  const [status, setStatus] = useState<PlaybackStatus>('idle');
  const manifestUrl = wallboardLiveStreamManifestPath(adminPreview);

  useEffect(() => {
    if (!running || demoMode) {
      setStatus('idle');
      return undefined;
    }
    const video = videoRef.current;
    if (video === null) return undefined;

    let disposed = false;
    let hls: Hls | null = null;
    let retryTimer: number | null = null;
    let stallTimer: number | null = null;
    let retryAttempt = 0;
    let networkRecoveryAttempts = 0;
    let mediaRecoveryAttempts = 0;
    let hasPlayed = false;
    let playbackGeneration = 0;

    const clearStallTimer = () => {
      if (stallTimer === null) return;
      window.clearTimeout(stallTimer);
      stallTimer = null;
    };

    const releasePlayback = () => {
      playbackGeneration += 1;
      clearStallTimer();
      video.pause();
      if (hls !== null) {
        hls.stopLoad();
        hls.destroy();
        hls = null;
      }
      video.removeAttribute('src');
      video.load();
    };

    const setPlaybackStatus = (nextStatus: PlaybackStatus) => {
      if (!disposed) setStatus(nextStatus);
    };

    const scheduleRestart = () => {
      if (disposed || retryTimer !== null) return;
      const delay = RETRY_DELAYS_MILLISECONDS[
        Math.min(retryAttempt, RETRY_DELAYS_MILLISECONDS.length - 1)
      ];
      retryAttempt += 1;
      retryTimer = window.setTimeout(() => {
        retryTimer = null;
        void startPlayback(video);
      }, delay);
      setPlaybackStatus('reconnecting');
      releasePlayback();
    };

    const armStallRestart = () => {
      clearStallTimer();
      stallTimer = window.setTimeout(scheduleRestart, STALL_RESTART_MILLISECONDS);
    };

    const playVideo = () => {
      if (disposed) return;
      void video.play().catch(scheduleRestart);
    };

    const handlePlaying = () => {
      hasPlayed = true;
      retryAttempt = 0;
      networkRecoveryAttempts = 0;
      mediaRecoveryAttempts = 0;
      clearStallTimer();
      setPlaybackStatus('live');
    };

    const handleWaiting = () => {
      setPlaybackStatus(hasPlayed ? 'reconnecting' : 'connecting');
      armStallRestart();
    };

    const handleNativeError = () => {
      if (hls === null) scheduleRestart();
    };

    video.addEventListener('playing', handlePlaying);
    video.addEventListener('waiting', handleWaiting);
    video.addEventListener('stalled', handleWaiting);
    video.addEventListener('error', handleNativeError);

    async function startPlayback(player: HTMLVideoElement) {
      if (disposed) return;
      releasePlayback();
      const generation = playbackGeneration;
      networkRecoveryAttempts = 0;
      mediaRecoveryAttempts = 0;
      setPlaybackStatus(retryAttempt === 0 ? 'connecting' : 'reconnecting');

      if (player.canPlayType('application/vnd.apple.mpegurl') !== '') {
        player.src = manifestUrl;
        player.load();
        playVideo();
        return;
      }

      try {
        const hlsModule = await import('hls.js');
        if (disposed || generation !== playbackGeneration) return;
        const HlsConstructor = hlsModule.default;
        if (!HlsConstructor.isSupported()) {
          setPlaybackStatus('unsupported');
          return;
        }

        const instance = new HlsConstructor({
          enableWorker: false,
          lowLatencyMode: true,
          backBufferLength: 30,
          maxBufferLength: 12,
          xhrSetup: (xhr) => {
            xhr.withCredentials = true;
          },
        });
        hls = instance;

        instance.on(hlsModule.Events.MEDIA_ATTACHED, () => {
          if (!disposed) instance.loadSource(manifestUrl);
        });
        instance.on(hlsModule.Events.MANIFEST_PARSED, playVideo);
        instance.on(hlsModule.Events.ERROR, (_event, data) => {
          if (disposed || !data.fatal) return;

          if (
            data.type === hlsModule.ErrorTypes.NETWORK_ERROR
            && networkRecoveryAttempts < 2
          ) {
            networkRecoveryAttempts += 1;
            setPlaybackStatus('reconnecting');
            instance.startLoad(-1);
            armStallRestart();
            return;
          }

          if (
            data.type === hlsModule.ErrorTypes.MEDIA_ERROR
            && mediaRecoveryAttempts < 1
          ) {
            mediaRecoveryAttempts += 1;
            setPlaybackStatus('reconnecting');
            instance.recoverMediaError();
            armStallRestart();
            return;
          }

          scheduleRestart();
        });
        instance.attachMedia(player);
      } catch {
        scheduleRestart();
      }
    }

    void startPlayback(video);

    return () => {
      disposed = true;
      if (retryTimer !== null) window.clearTimeout(retryTimer);
      video.removeEventListener('playing', handlePlaying);
      video.removeEventListener('waiting', handleWaiting);
      video.removeEventListener('stalled', handleWaiting);
      video.removeEventListener('error', handleNativeError);
      releasePlayback();
    };
  }, [demoMode, manifestUrl, running]);

  if (demoMode) {
    return (
      <div className={`${styles.root} ${styles.demo}`} role="status">
        <span className={styles.demoSignal} aria-hidden><RadioTower size={54} strokeWidth={1.45} /></span>
        <span className={styles.eyebrow}>Demomodus</span>
        <strong>Live-uitzending niet verbonden</strong>
        <small>Een demoplaylist maakt nooit verbinding met het echte OBS-signaal.</small>
      </div>
    );
  }

  return (
    <div className={styles.root} data-playback-status={status}>
      <video
        ref={videoRef}
        aria-label={name}
        autoPlay={running}
        muted
        playsInline
        preload="none"
        controls={false}
        disablePictureInPicture
        disableRemotePlayback
        controlsList="nodownload nofullscreen noremoteplayback"
      />

      {status === 'live' ? (
        <span className={styles.tally} aria-label="Live-uitzending actief">
          <i aria-hidden /> LIVE
        </span>
      ) : status === 'idle' ? null : (
        <div className={styles.status} role="status" aria-live="polite">
          <span className={styles.statusIcon} aria-hidden>
            {status === 'unsupported'
              ? <WifiOff size={28} />
              : <Loader2 className={styles.spinner} size={28} />}
          </span>
          <span className={styles.eyebrow}>Live-uitzending</span>
          <strong>{playbackStatusTitle(status)}</strong>
          <small>{playbackStatusDetail(status)}</small>
        </div>
      )}
    </div>
  );
}

function playbackStatusTitle(status: Exclude<PlaybackStatus, 'idle' | 'live'>): string {
  switch (status) {
    case 'connecting': return 'Livebeeld verbinden';
    case 'reconnecting': return 'Beeld onderbroken';
    case 'unsupported': return 'Livevideo niet ondersteund';
  }
}

function playbackStatusDetail(status: Exclude<PlaybackStatus, 'idle' | 'live'>): string {
  switch (status) {
    case 'connecting': return 'Wachten op het OBS-signaal…';
    case 'reconnecting': return 'De verbinding wordt automatisch opnieuw opgebouwd.';
    case 'unsupported': return 'Gebruik een recente wallboardbrowser met HLS- of Media Source-ondersteuning.';
  }
}
