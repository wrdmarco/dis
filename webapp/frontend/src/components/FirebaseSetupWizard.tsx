import { CheckCircle2, ClipboardList, KeyRound, Send, Settings2, Smartphone } from 'lucide-react';

interface FirebaseSetupWizardProps {
  androidApplicationId?: string;
  compact?: boolean;
}

const consoleUrl = 'https://console.firebase.google.com/';
const serviceAccountPath = '/opt/dis/secrets/firebase-service-account.json';

export function FirebaseSetupWizard({ androidApplicationId = 'nl.wrdmarco.dis', compact = false }: FirebaseSetupWizardProps) {
  const steps = [
    {
      title: 'Firebase project',
      icon: Settings2,
      body: 'Maak of kies een Firebase project. Gebruik dezelfde project id voor FCM server push en voor de mobiele appconfiguratie.',
      checks: [
        'Open Firebase Console en maak een project aan.',
        'Noteer de project id, niet alleen de weergavenaam.',
        'Zet Google Analytics alleen aan als je dit operationeel nodig hebt.',
      ],
    },
    {
      title: 'Android app',
      icon: Smartphone,
      body: `Registreer een Android app met package name ${androidApplicationId}. Deze waarde moet exact gelijk zijn aan de APK applicationId.`,
      checks: [
        `Android package name: ${androidApplicationId}`,
        'Download google-services.json alleen als referentie; de DIS app haalt tenantconfiguratie op via de API.',
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
      body: `Plaats de Firebase service account JSON op de server als ${serviceAccountPath}. Dit bestand hoort niet in git en niet in de webroot.`,
      checks: [
        'Firebase Console > Project settings > Service accounts > Generate new private key.',
        `Upload het JSON bestand naar ${serviceAccountPath}.`,
        'Zorg dat alleen de DIS backend gebruiker het bestand kan lezen.',
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
  ];

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
      <div className="firebase-wizard__steps">
        {steps.map((step, index) => {
          const Icon = step.icon;
          return (
            <section className="firebase-step" key={step.title}>
              <div className="firebase-step__heading">
                <span>{index + 1}</span>
                <Icon size={18} />
                <strong>{step.title}</strong>
              </div>
              <p>{step.body}</p>
              <ul>
                {step.checks.map((check) => (
                  <li key={check}>{check}</li>
                ))}
              </ul>
            </section>
          );
        })}
      </div>
    </div>
  );
}
