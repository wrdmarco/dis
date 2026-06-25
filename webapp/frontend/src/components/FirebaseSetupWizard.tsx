import { CheckCircle2, ChevronLeft, ChevronRight, ClipboardList, KeyRound, Send, Settings2, Smartphone } from 'lucide-react';
import { useMemo, useState } from 'react';

interface FirebaseSetupWizardProps {
  androidApplicationId?: string;
  compact?: boolean;
}

const consoleUrl = 'https://console.firebase.google.com/';

export function FirebaseSetupWizard({ androidApplicationId = 'nl.wrdmarco.dis', compact = false }: FirebaseSetupWizardProps) {
  const steps = useMemo(() => [
    {
      title: 'Firebase project',
      icon: Settings2,
      body: 'Maak of kies een Firebase project. De project id gebruik je straks in de DIS velden.',
      checks: [
        'Open Firebase Console en maak een project aan.',
        'Noteer de project id, niet alleen de weergavenaam.',
        'Zet Google Analytics alleen aan als je dit operationeel nodig hebt.',
      ],
    },
    {
      title: 'Android app',
      icon: Smartphone,
      body: `Registreer een Android app met package name ${androidApplicationId}. Dit moet exact overeenkomen met de APK.`,
      checks: [
        `Android package name: ${androidApplicationId}`,
        'Neem application id, API key, project id, sender id en storage bucket over in DIS.',
        'Vul application id, API key, project id, sender id en storage bucket in de DIS velden hieronder in.',
      ],
    },
    {
      title: 'Cloud Messaging',
      icon: Send,
      body: 'Pushmeldingen lopen via Firebase Cloud Messaging HTTP v1. De backend gebruikt hiervoor een service account.',
      checks: [
        'Controleer dat Firebase Cloud Messaging API actief is in het Google Cloud project.',
        'Gebruik de sender id uit de Firebase Android app configuratie.',
        'Test push pas nadat een gebruiker de Android app heeft geopend en een token heeft geregistreerd.',
      ],
    },
    {
      title: 'Service account',
      icon: KeyRound,
      body: 'Push vanaf de backend gebruikt service-accountgegevens die je via deze webpagina opslaat.',
      checks: [
        'Open Project settings > Service accounts in Firebase Console.',
        'Maak een private key aan en open die alleen lokaal op je eigen computer.',
        'Kopieer client_email, private_key, private_key_id, client_id en cert URL naar de DIS velden.',
      ],
    },
    {
      title: 'Afronden',
      icon: CheckCircle2,
      body: 'Sla de configuratie op, open de Android app opnieuw en verstuur daarna een handmatige pushmelding naar jezelf.',
      checks: [
        'Mobiele app opent met alleen de server URL, zonder /api.',
        'Admin > Firebase tokens toont een actief token voor je toestel.',
        'Admin > Handmatige push melding geeft queued tokens groter dan 0.',
      ],
    },
  ], [androidApplicationId]);
  const [activeStep, setActiveStep] = useState(0);
  const step = steps[activeStep];
  const Icon = step.icon;

  return (
    <div className={compact ? 'firebase-wizard firebase-wizard--compact' : 'firebase-wizard'}>
      <div className="firebase-wizard__intro">
        <div className="firebase-wizard__icon"><ClipboardList size={22} /></div>
        <div>
          <strong>Firebase setup wizard</strong>
          <p>Volg deze stappen voordat je pushmeldingen in productie gebruikt.</p>
        </div>
        <a className="secondary-button" href={consoleUrl} target="_blank" rel="noreferrer">Firebase Console</a>
      </div>
      <div className="firebase-wizard__nav" role="tablist" aria-label="Firebase setup stappen">
        {steps.map((step, index) => {
          const Icon = step.icon;
          return (
            <button
              className={index === activeStep ? 'firebase-wizard__tab firebase-wizard__tab--active' : 'firebase-wizard__tab'}
              key={step.title}
              type="button"
              role="tab"
              aria-selected={index === activeStep}
              onClick={() => setActiveStep(index)}
            >
              <span>{index + 1}</span>
              <Icon size={16} />
              <strong>{step.title}</strong>
            </button>
          );
        })}
      </div>
      <section className="firebase-step" aria-live="polite">
        <div className="firebase-step__heading">
          <span>{activeStep + 1}</span>
          <Icon size={18} />
          <strong>{step.title}</strong>
        </div>
        <p>{step.body}</p>
        <ul>
          {step.checks.map((check) => (
            <li key={check}>{check}</li>
          ))}
        </ul>
        <div className="firebase-step__actions">
          <button className="secondary-button" type="button" disabled={activeStep === 0} onClick={() => setActiveStep((current) => Math.max(0, current - 1))}>
            <ChevronLeft size={16} /> Vorige
          </button>
          <button className="primary-button" type="button" disabled={activeStep === steps.length - 1} onClick={() => setActiveStep((current) => Math.min(steps.length - 1, current + 1))}>
            Volgende <ChevronRight size={16} />
          </button>
        </div>
      </section>
    </div>
  );
}
