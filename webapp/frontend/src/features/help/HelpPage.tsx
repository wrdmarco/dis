import Link from 'next/link';
import {
  Archive,
  BellRing,
  BookOpen,
  BookUser,
  Boxes,
  CalendarDays,
  ChevronDown,
  CheckCircle2,
  CircleAlert,
  ClipboardCheck,
  ClipboardList,
  DatabaseBackup,
  FileChartColumn,
  FileText,
  Gauge,
  KeyRound,
  Map as MapIcon,
  Monitor,
  Network,
  Palette,
  QrCode,
  RadioTower,
  Search,
  Send,
  Settings,
  ShieldCheck,
  Smartphone,
  UserRound,
  Users,
  Workflow,
  type LucideIcon,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { useAuth } from '../auth/AuthContext';
import { manualGuides } from './manualContent';
import type { ManualGuide } from './manualTypes';

type HelpGroupId = 'account' | 'operation' | 'resources' | 'management';
type MobileAppAccess = 'operator' | 'admin' | 'any';

interface AccessRule {
  permissions?: readonly string[];
  anyPermission?: boolean;
  mobileApp?: MobileAppAccess;
}

interface HelpAction extends AccessRule {
  title: string;
  description: string;
}

interface HelpTopic extends AccessRule {
  id: string;
  group: HelpGroupId;
  title: string;
  summary: string;
  icon: LucideIcon;
  href?: string;
  actions: readonly HelpAction[];
  pairingGuide?: boolean;
}

interface AccessibleHelpTopic extends HelpTopic {
  actions: readonly HelpAction[];
  guides: readonly ManualGuide[];
}

interface NumberedManualGuide extends ManualGuide {
  chapterNumber: string;
}

interface VisibleHelpTopic extends HelpTopic {
  actions: readonly HelpAction[];
  guides: readonly NumberedManualGuide[];
  chapterNumber: string;
  groupNumber: number;
}

interface AccessContext {
  permissions: ReadonlySet<string>;
  operatorApp: boolean;
  adminApp: boolean;
}

const helpGroups: ReadonlyArray<{ id: HelpGroupId; label: string }> = [
  { id: 'account', label: 'Mijn account' },
  { id: 'operation', label: 'Meldkamer en inzetten' },
  { id: 'resources', label: 'Mensen en middelen' },
  { id: 'management', label: 'Beheer' },
];

const helpTopics: readonly HelpTopic[] = [
  {
    id: 'navigation',
    group: 'account',
    title: 'Wegwijs in de webapp',
    summary: 'Vind snel een onderdeel en houd het scherm rustig tijdens je werk.',
    icon: BookOpen,
    actions: [
      { title: 'Menu openen', description: 'Kies links een onderdeel. Op een telefoon open en sluit je dit menu met de menuknop bovenaan.' },
      { title: 'Accountmenu gebruiken', description: 'Klik rechtsboven op je naam voor je profiel, deze help, de lichte of donkere weergave en uitloggen.' },
      { title: 'Alleen toegestane functies zien', description: 'Menu, knoppen en uitleg worden aangepast aan jouw rechten. Ontbreekt een functie, vraag dan een beheerder om je rol te controleren.' },
      { title: 'Licht of donker kiezen', description: 'Wissel via het accountmenu van weergave. DIS onthoudt deze keuze voor je volgende bezoek.' },
    ],
  },
  {
    id: 'account-access',
    group: 'account',
    title: 'Aanmelden en je account',
    summary: 'Weet wat je moet doen bij een uitnodiging, beveiligingscontrole of tijdelijk geblokkeerd account.',
    icon: KeyRound,
    actions: [
      { title: 'Uitnodiging afronden', description: 'Open de uitnodigingslink, vul de gevraagde persoonsgegevens in en kies een sterk wachtwoord. Stel ook tweestapsverificatie in wanneer het scherm daarom vraagt.' },
      { title: 'Aanmelden', description: 'Gebruik je e-mailadres en wachtwoord. Vraagt DIS daarna om een code, open dan je beveiligingsapp en vul de actuele code in.' },
      { title: 'Tijdelijk geblokkeerd', description: 'Na vijf verkeerde wachtwoorden wacht je vijf minuten. Na vijf verkeerde beveiligingscodes meld je opnieuw aan. Neem bij een geschorst of geblokkeerd account contact op met een beheerder.' },
      { title: 'Profiel eerst aanvullen', description: 'Vul voornaam, achternaam, internationaal telefoonnummer, land en woonplaats in. Voor Nederland en België is ook provincie of regio nodig.' },
      { title: 'Onderhoud herkennen', description: 'Tijdens onderhoud blijft je account bewaard. De onderhoudspagina controleert vanzelf opnieuw; je hoeft niet steeds opnieuw aan te melden.' },
      { title: 'Uitloggen', description: 'Uitloggen sluit alleen deze websessie. Andere browsers en gekoppelde mobiele toestellen blijven aangemeld.' },
    ],
  },
  {
    id: 'profile',
    group: 'account',
    title: 'Profiel en beschikbaarheid',
    summary: 'Houd je contactgegevens, planning en beveiliging zelf bij.',
    icon: UserRound,
    href: '/profile',
    actions: [
      { title: 'Persoonsgegevens aanpassen', description: 'Wijzig je naam, internationaal telefoonnummer, woonplaats, land en provincie of regio. Je e-mailadres is hier alleen zichtbaar.' },
      { title: 'Twee weken vooruit plannen', description: 'Klik per datum op ochtend, middag of avond. Zo’n afwijking wordt direct opgeslagen en gaat voor je vaste patroon.' },
      { title: 'Vaste werkweek instellen', description: 'Leg per weekdag je normale ochtend, middag en avond vast en kies daarna Vaste dagdelen opslaan.' },
      { title: 'Eigen middelen bijhouden', description: 'Voeg je eigen drone of ander middel toe en werk de gereedheid bij wanneer er iets verandert.' },
      { title: 'Eigen certificaten bijhouden', description: 'Vul afgifte- en verloopdatum in en pas een certificaat aan wanneer het wordt verlengd.' },
      { title: 'Beveiliging bijhouden', description: 'Schakel tweestapsverificatie in. Krijg je herstelcodes in beeld, bewaar ze dan meteen op een veilige plek. Verplichte beveiliging kun je niet zelf uitzetten.' },
    ],
  },
  {
    id: 'mobile-app',
    group: 'account',
    title: 'Mobiele app koppelen',
    summary: 'Koppel de operator- of admin-app zonder gebruikersnaam en wachtwoord.',
    icon: Smartphone,
    href: '/profile',
    mobileApp: 'any',
    pairingGuide: true,
    actions: [
      { title: 'Operator-app koppelen', description: 'Koppel een Android-telefoon, iPhone of tablet waarmee je meldingen ontvangt en op inzetten reageert.', mobileApp: 'operator' },
      { title: 'Admin-app koppelen', description: 'Koppel de mobiele beheerapp wanneer jouw rol daar toegang toe heeft.', mobileApp: 'admin' },
      { title: 'Tijdelijke code gebruiken', description: 'De koppelcode is 30 seconden geldig en wordt op het scherm automatisch vernieuwd. Scan daarom altijd de code die nu zichtbaar is.' },
      { title: 'Toestellen beheren', description: 'Bekijk app, platform, laatste contact en status. Verwijder een oud toestel om toegang en meldingen direct in te trekken.' },
      { title: 'Laatste actieve toestel verwijderen', description: 'Wanneer er geen enkel actief app-toestel meer over is, schakelt DIS push uit en word je niet beschikbaar gezet.', mobileApp: 'operator' },
    ],
  },
  {
    id: 'calendar',
    group: 'account',
    title: 'Agenda',
    summary: 'Bekijk trainingen, open dagen en andere gezamenlijke afspraken.',
    icon: CalendarDays,
    href: '/calendar',
    actions: [
      { title: 'Afspraken bekijken', description: 'Bekijk algemene afspraken en afspraken voor jouw teams, met datum, tijd, soort, locatie en team.' },
      { title: 'Afspraken beheren', description: 'Voeg een afspraak toe of verwijder een afspraak die niet meer doorgaat. Een bestaande afspraak heeft geen aparte wijzigknop.', permissions: ['settings.manage'] },
    ],
  },
  {
    id: 'dashboard',
    group: 'operation',
    title: 'Dashboard',
    summary: 'Bekijk in een oogopslag wat er operationeel speelt.',
    icon: Gauge,
    href: '/dashboard',
    permissions: ['incidents.view', 'incidents.dispatch.view', 'status.view', 'assets.view'],
    actions: [
      { title: 'Actieve meldingen volgen', description: 'Zie welke incidenten openstaan en open een incident voor de volledige informatie.' },
      { title: 'Reacties volgen', description: 'Zie hoeveel mensen komen, nog moeten reageren of niet beschikbaar zijn.' },
      { title: 'Beschikbaarheid en middelen controleren', description: 'Zie hoeveel mensen inzetbaar zijn en welke middelen aandacht nodig hebben.' },
    ],
  },
  {
    id: 'incidents',
    group: 'operation',
    title: 'Incidenten',
    summary: 'Bekijk actieve meldingen en afgeronde inzetten in het archief.',
    icon: RadioTower,
    href: '/incidents',
    permissions: ['incidents.view'],
    actions: [
      { title: 'Incident bekijken', description: 'Open een melding voor de locatie, incidentgegevens, tijdlijn, dronevluchtinformatie en beschikbare rapporten.' },
      { title: 'Alarmering en opkomst bekijken', description: 'Bekijk gealarmeerde teams, ontvangers, reacties en opkomst op de incidentdetailpagina.', permissions: ['incidents.dispatch.view'] },
      { title: 'Archief gebruiken', description: 'Open afgeronde en geannuleerde incidenten vanuit het aparte archiefoverzicht.' },
      { title: 'Incident aanmaken', description: 'Kies Incident aanmaken, zoek het adres of een herkenbare locatie en vul alle verplichte velden en teams in.', permissions: ['incidents.manage'] },
      { title: 'Incident wijzigen', description: 'Open de melding en kies Aanpassen. Geef bij een statuswijziging een korte, duidelijke reden op.', permissions: ['incidents.manage'] },
      { title: 'Status veilig wijzigen', description: 'Let op: Concept naar Actief verstuurt een vooraankondiging. Actief naar Alarmeren verstuurt de echte melding.', permissions: ['incidents.manage'] },
      { title: 'Kladblokregel versturen', description: 'Schrijf één meldkamerregel en kies Versturen. De regel komt in de tijdlijn; het invoerveld wordt daarna weer leeg.', permissions: ['incidents.manage'] },
      { title: 'Dronevluchtinformatie controleren', description: 'Bekijk op de detailpagina weer, luchtruim, NOTAM, Aeret-kaart en de bewaarde vliegcheck bij de incidentlocatie.' },
      { title: 'Incident afronden of annuleren', description: 'Kies de passende eindstatus zodra de inzet klaar is of niet doorgaat. Het incident verhuist daarna naar het archief.', permissions: ['incidents.manage'] },
      { title: 'Rapport-PDF downloaden', description: 'Na afronden of annuleren kun je vanaf het incident de bewaarde rapport-PDF downloaden.' },
      { title: 'Incident verwijderen', description: 'Verwijder alleen een foutief incident. Opkomst, tijdlijn, live locaties en rapportgegevens worden dan ook gewist.', permissions: ['incidents.delete'] },
    ],
  },
  {
    id: 'dispatch',
    group: 'operation',
    title: 'Vooraankondigen en alarmeren',
    summary: 'Bereid een inzet voor, alarmeer de juiste mensen en volg hun reactie.',
    icon: BellRing,
    permissions: ['incidents.dispatch.view', 'incidents.manage', 'incidents.dispatch.manage', 'status.override'],
    anyPermission: true,
    actions: [
      { title: 'Alarmering bekijken', description: 'Bekijk geselecteerde teams, ontvangers, reacties, opkomst en verzonden berichten.', permissions: ['incidents.dispatch.view'] },
      { title: 'Ontvangers vooraf controleren', description: 'Bekijk vóór verzending welke teams leeg zijn en hoeveel geschikte, beschikbare en online gebruikers bereikt kunnen worden.', permissions: ['incidents.view', 'incidents.manage', 'incidents.dispatch.view'] },
      { title: 'Vooraankondiging sturen', description: 'Vraag eerst wie beschikbaar is. Een vooraankondiging telt niet als opkomst; bij de echte alarmering volgt opnieuw een reactie.', permissions: ['incidents.view', 'incidents.manage', 'incidents.dispatch.view'] },
      { title: 'Direct alarmeren', description: 'Stuur een echte alarmering wanneer direct reageren nodig is. Alleen geschikte en online gebruikers worden meegenomen.', permissions: ['incidents.view', 'incidents.manage', 'incidents.dispatch.view'] },
      { title: 'Aantal mensen en ETA-ringen kiezen', description: 'Vul het gewenste aantal in. DIS gebruikt de autoroute vanaf de globale woonplaats, begint bij 15 minuten en vergroot de ring per kwartier. Een terugvalschatting is herkenbaar gemarkeerd.', permissions: ['incidents.view', 'incidents.manage', 'incidents.dispatch.view'] },
      { title: 'Opschalen', description: 'Voeg extra operationele teams toe. Bij een urgente inzet kun je bewust ook niet-beschikbare teamleden meenemen.', permissions: ['incidents.dispatch.manage'] },
      { title: 'Heralarmeren', description: 'Stuur opnieuw naar ontvangers die nog op Wacht op reactie staan. Er worden geen nieuwe personen toegevoegd.', permissions: ['incidents.dispatch.manage'] },
      { title: 'Reactie corrigeren', description: 'Pas namens de meldkamer een reactie aan wanneer telefonisch iets anders is doorgegeven.', permissions: ['incidents.dispatch.manage'] },
      { title: 'Opkomststatus corrigeren', description: 'Zet een geaccepteerde gebruiker op Onderweg of Op locatie wanneer dit operationeel nodig is.', permissions: ['status.override'] },
      { title: 'Live locatie vragen', description: 'Vraag een betrokken gebruiker om live locatie te delen. De gebruiker beslist zelf; bij Op locatie stopt het delen.', permissions: ['incidents.dispatch.manage'] },
      { title: 'Nadere informatie sturen', description: 'Stuur een korte aanvulling naar mensen die al bij de inzet betrokken zijn.', permissions: ['incidents.dispatch.manage'] },
    ],
  },
  {
    id: 'test-alert',
    group: 'operation',
    title: 'Proefalarmering',
    summary: 'Controleer de meldingsketen zonder een echte inzet te starten.',
    icon: BellRing,
    href: '/test-alert',
    permissions: ['incidents.dispatch.view', 'incidents.dispatch.manage'],
    actions: [
      { title: 'Handmatig proefalarm sturen', description: 'Kies tussen je eigen gekoppelde toestellen en een bevestigde bereikbaarheidstest voor alle online operator-apps.' },
      { title: 'Automatisch proefalarm plannen', description: 'Kies dag, tijd en tekst voor een terugkerende controle bij actieve gebruikers met operator-app en push.' },
      { title: 'Ontvangst volgen', description: 'Bekijk live welke proefmelding is aangekomen. Een nieuw proefalarm vervangt een ouder, nog onbeantwoord proefalarm.' },
    ],
  },
  {
    id: 'map',
    group: 'operation',
    title: 'Operationele kaart',
    summary: 'Bekijk open meldingen, meldkamers en gedeelde locaties op één kaart.',
    icon: MapIcon,
    href: '/operational-map',
    permissions: ['operational-map.view', 'incidents.view'],
    actions: [
      { title: 'Kaartlagen kiezen', description: 'Meldkamers staan standaard aan. Zet eerdere inzetten zelf aan via het lagenmenu.' },
      { title: 'Symbolen herkennen', description: 'De kaart onderscheidt open meldingen, gebruikers, meldkamers en eerdere inzetten. Een lijn koppelt een gebruiker aan het bijbehorende incident.' },
      { title: 'Open melding volgen', description: 'Open een incident via de titel op de kaart en bekijk welke gebruikers vrijwillig hun locatie delen.' },
      { title: 'Automatisch verversen', description: 'Open meldingen en locaties worden ongeveer elke tien seconden bijgewerkt. Gebruik Vernieuwen om ook de gekozen kaartlagen opnieuw te laden.' },
      { title: 'Globale woonplaatsen tonen', description: 'Zet de woonplaatsen van operationele appgebruikers als globale positie aan. Dit is geen exact woonadres.', permissions: ['operational-map.pilot-homes.view'] },
      { title: 'Volledig scherm openen', description: 'Gebruik de knop voor volledig scherm wanneer de kaart het belangrijkste beeld is.' },
    ],
  },
  {
    id: 'status',
    group: 'operation',
    title: 'Operationele status',
    summary: 'Zie snel wie beschikbaar, online of al onderweg is.',
    icon: Workflow,
    href: '/operational-status',
    permissions: ['status.view'],
    actions: [
      { title: 'Gebruikers vergelijken', description: 'Bekijk beschikbaarheid, online moment en het volgende beschikbare tijdstip in één compacte tabel.' },
      { title: 'Beschikbaarheid per team bekijken', description: 'Bekijk per team hoeveel leden beschikbaar zijn, hoeveel leden het team heeft en hoeveel daarvan online zijn.' },
      { title: 'Automatisch bijwerken', description: 'De lijst wordt elke minuut en bij een live statuswijziging opnieuw bijgewerkt.' },
      { title: 'Status namens iemand wijzigen', description: 'Corrigeer de status wanneer dit operationeel nodig is en leg zo nodig een reden vast. Op locatie stopt het delen van de locatie en zet het inzetrapport klaar.', permissions: ['status.override'] },
    ],
  },
  {
    id: 'reports',
    group: 'operation',
    title: 'Rapporten',
    summary: 'Bekijk incidentrapporten en controleer welke inzetrapporten nog ontbreken.',
    icon: FileChartColumn,
    href: '/reports',
    permissions: ['incidents.view', 'incidents.dispatch.view'],
    actions: [
      { title: 'Rapportstatus controleren', description: 'Zie direct welke piloten hun inzetrapport al hebben ingediend of definitief gemaakt.' },
      { title: 'Incidentrapport downloaden', description: 'Download de bewaarde PDF met kaarten, opkomst, inzetinformatie en log. De rapportmomentopname wordt niet opnieuw opgebouwd bij iedere download.' },
      { title: 'Rapport namens een gebruiker invullen', description: 'Open de naam bij Ontbreekt en vul de informatie in na bijvoorbeeld telefonisch contact. Opslaan dient het rapport meteen in.', permissions: ['incidents.manage'] },
      { title: 'Ingediend rapport aanpassen', description: 'Een ingediend inzetrapport kan nog worden gewijzigd totdat het met de knop definitief is gemaakt.', permissions: ['incidents.manage'] },
      { title: 'Rapport definitief maken', description: 'Controleer de inhoud en kies Definitief maken. Dit kan niet worden teruggedraaid. Als alles definitief is, wordt het incidentrapport automatisch definitief.', permissions: ['incidents.manage'] },
      { title: 'Wie moet een rapport invullen', description: 'Een uniek gealarmeerd persoon die Komt heeft gekozen, krijgt een inzetrapport. Komt niet en geen reactie tellen niet mee.' },
      { title: 'Alarmeringscijfers bekijken', description: 'Kies de laatste 5, 10, 25 of 50 meldingen en vergelijk reacties, gemiste reacties en responstijden.' },
      { title: 'Gebruikers en incidenten vergelijken', description: 'Bekijk wie vaak niet reageert en controleer de cijfers per incident in de tabel.' },
    ],
  },
  {
    id: 'users',
    group: 'resources',
    title: 'Gebruikers',
    summary: 'Bekijk accounts en de operationele gegevens die daarbij horen.',
    icon: Users,
    href: '/users',
    permissions: ['users.view'],
    actions: [
      { title: 'Gebruiker bekijken', description: 'Bekijk contactgegevens, teams, toestellen, middelen en certificaten van een gebruiker.' },
      { title: 'Gebruiker uitnodigen', description: 'Maak het account aan en laat standaard een welkomstmail sturen. De registratielink is 60 minuten geldig.', permissions: ['users.manage'] },
      { title: 'Basisgegevens wijzigen', description: 'Beheer persoonsgegevens, accountstatus en het toegestane aantal operator-toestellen.', permissions: ['users.manage', 'roles.manage'] },
      { title: 'Rollen wijzigen', description: 'Koppel alleen rollen die bij het werk van de gebruiker horen.', permissions: ['users.manage', 'roles.manage'] },
      { title: 'Teams wijzigen', description: 'Koppel de gebruiker aan de juiste operationele teams.', permissions: ['users.manage', 'teams.manage'] },
      { title: 'Vakantie registreren', description: 'Voeg een vakantieperiode toe of trek een geplande of actieve periode in. Tijdens een actieve vakantie kan de gebruiker niet als beschikbaar worden gezet.', permissions: ['users.manage'] },
      { title: 'Uitnodiging opnieuw versturen', description: 'Deze knop staat alleen bij een actief account dat nog nooit heeft ingelogd.', permissions: ['users.manage'] },
      { title: 'E-mailadres of wachtwoord wijzigen', description: 'Pas inloggegevens alleen aan wanneer dit echt nodig is. Bestaande sessies worden daarna ingetrokken.', permissions: ['users.manage', 'users.credentials.manage'] },
      { title: 'Sessies intrekken', description: 'Log een gebruiker uit op web en mobiele toestellen wanneer toegang direct moet stoppen.', permissions: ['users.manage', 'users.sessions.revoke'] },
      { title: 'MFA opnieuw laten instellen', description: 'Wis de bestaande koppeling zodat de gebruiker tweestapsverificatie opnieuw moet instellen. Bestaande sessies vervallen.', permissions: ['users.manage', 'users.mfa.reset'] },
      { title: 'Inlogblokkade opheffen', description: 'Maak een tijdelijk geblokkeerd account weer toegankelijk.', permissions: ['users.manage', 'users.login-lock.reset'] },
      { title: 'Gebruiker verwijderen', description: 'Verwijder nooit jezelf of de laatste actieve systeembeheerder. Historische namen in rapporten blijven bewaard.', permissions: ['users.delete'] },
      { title: 'Middel koppelen', description: 'Koppel een bestaand middel vanuit de gebruikersdetailpagina. Aanpassen of verwijderen doe je op de pagina Middelen.', permissions: ['assets.view', 'assets.manage'] },
      { title: 'Certificaat koppelen', description: 'Koppel een bestaand certificaat vanuit de gebruikersdetailpagina. Het gekoppelde gebruikerscertificaat is daar daarna alleen te bekijken.', permissions: ['certifications.view', 'certifications.manage'] },
    ],
  },
  {
    id: 'address-book',
    group: 'resources',
    title: 'Adresboek',
    summary: 'Vind snel een collega op naam, telefoonnummer of woonplaats.',
    icon: BookUser,
    href: '/address-book',
    permissions: ['address-book.view'],
    actions: [
      { title: 'Direct zoeken', description: 'Begin met typen. De resultaten worden meteen aangepast zonder dat je op Enter hoeft te drukken.' },
      { title: 'Contactgegevens gebruiken', description: 'Bekijk naam, internationaal telefoonnummer en woonplaats. Klik op het telefoonnummer om direct te bellen wanneer je apparaat dit ondersteunt.' },
    ],
  },
  {
    id: 'roles',
    group: 'resources',
    title: 'Rollen en rechten',
    summary: 'Bepaal welke onderdelen en mobiele apps iemand mag gebruiken.',
    icon: KeyRound,
    href: '/roles',
    permissions: ['roles.manage'],
    actions: [
      { title: 'Rol maken of aanpassen', description: 'Geef de rol een duidelijke naam en vink alleen de rechten aan die bij het werk horen.' },
      { title: 'Rechten per taak kiezen', description: 'Geef bekijken, beheren, verwijderen en gevoelige acties alleen aan rollen die deze taken echt uitvoeren. Je kunt geen rechten doorgeven die je zelf niet hebt.' },
      { title: 'App-toegang instellen', description: 'Operator-app bepaalt operationeel gebruik. Admin-app bepaalt ook welke rolrechten in web en beheerapp gelden.' },
      { title: 'Systeembeheerder beschermen', description: 'De vaste rol Systeembeheerder kan niet worden aangepast of verwijderd.' },
      { title: 'Rol verwijderen', description: 'Verwijder alleen een rol waar geen gebruikers meer aan gekoppeld zijn.', permissions: ['roles.delete'] },
    ],
  },
  {
    id: 'teams',
    group: 'resources',
    title: 'Teams',
    summary: 'Beheer OCP, TUI en de groepen die bij een inzet gealarmeerd worden.',
    icon: Network,
    href: '/teams',
    permissions: ['teams.manage'],
    actions: [
      { title: 'Team maken en wijzigen', description: 'Stel code, naam, soort, ouderteam, operationele status en koppelingen met andere teams in. Teams kunnen niet worden verwijderd.' },
      { title: 'Leden beheren', description: 'Wijzig teamlidmaatschap bij Gebruikers > Aanpassen. Hiervoor zijn ook rechten voor gebruikersbeheer nodig.', permissions: ['users.view', 'users.manage'] },
      { title: 'OCP en TUI goed houden', description: 'OCP is het basisteam. Iemand in TUI moet ook in OCP staan. Verander de vaste codes en koppeling van OCP en TUI niet.' },
      { title: 'Alarmeerteams voorbereiden', description: 'Gebruik herkenbare groepen zodat de meldkamer tijdens een incident snel de juiste mensen kiest.' },
      { title: 'Vereiste certificaten kiezen', description: 'Bepaal welke geldige certificaten iemand nodig heeft voordat die voor dit team gealarmeerd kan worden.' },
    ],
  },
  {
    id: 'assets',
    group: 'resources',
    title: 'Middelen',
    summary: 'Bekijk drones, voertuigen en andere inzetmiddelen en hun gereedheid.',
    icon: Boxes,
    href: '/assets',
    permissions: ['assets.view'],
    actions: [
      { title: 'Middelen bekijken', description: 'Controleer soort, eigenaar, status en bijzonderheden van een middel.' },
      { title: 'Middel beheren', description: 'Voeg een middel toe, pas gegevens en status aan of verwijder het. Koppelen aan een gebruiker doe je bij die gebruiker.', permissions: ['assets.manage'] },
      { title: 'Dronetypen beheren', description: 'Beheer merk, model, beschikbare functies en actief of niet actief. Een type kan ook worden verwijderd.', permissions: ['assets.manage'] },
    ],
  },
  {
    id: 'certifications',
    group: 'resources',
    title: 'Certificaten',
    summary: 'Controleer bevoegdheden en voorkom inzet met verlopen certificaten.',
    icon: ClipboardCheck,
    href: '/certifications',
    permissions: ['certifications.view'],
    actions: [
      { title: 'Geldigheid bekijken', description: 'Bekijk certificaatsoorten en actieve gebruikerscertificaten. Een vereist certificaat telt alleen als het actief en niet verlopen is.' },
      { title: 'Certificaatsoorten beheren', description: 'Voeg een soort toe of pas code, naam, omschrijving en de regel Dispatch vereist aan. Certificaatsoorten kunnen hier niet worden verwijderd.', permissions: ['certifications.manage'] },
    ],
  },
  {
    id: 'expiry',
    group: 'resources',
    title: 'Verloop',
    summary: 'Zie welke middelen of certificaten binnenkort aandacht nodig hebben.',
    icon: Archive,
    href: '/expiry',
    permissions: ['assets.view', 'certifications.view'],
    anyPermission: true,
    actions: [
      { title: 'Verloop bekijken', description: 'Kies 30, 60, 90 of 180 dagen vooruit. Kritiek betekent al verlopen of binnen zeven dagen. Dit overzicht heeft geen wijzigknoppen.' },
    ],
  },
  {
    id: 'manual-push',
    group: 'management',
    title: 'Handmatige pushmelding',
    summary: 'Stuur een losse mededeling die niet bij een incident hoort.',
    icon: Send,
    href: '/push',
    permissions: ['settings.push.manual.send'],
    actions: [
      { title: 'Ontvangers kiezen', description: 'Kies teams, rollen of losse gebruikers. De teller toont je keuzes, niet hoeveel mensen op dat moment bereikbaar zijn.' },
      { title: 'Bereikbare gebruikers', description: 'DIS neemt alleen actieve gebruikers mee met push aan en een recent online Operator-app. Dubbele selecties worden samengevoegd.' },
      { title: 'Bericht versturen', description: 'Schrijf een korte titel en duidelijke tekst. Gebruik incidentalarmering voor echte inzetten. Klaargezet betekent nog niet dat ieder toestel het bericht al heeft ontvangen.' },
      { title: 'Afleverpogingen bekijken', description: 'Bekijk onder het formulier de laatste afleverpogingen van pushmeldingen en eventuele fouten.' },
    ],
  },
  {
    id: 'forms',
    group: 'management',
    title: 'Formulieren',
    summary: 'Bepaal welke velden gebruikers en meldkamer in formulieren zien.',
    icon: FileText,
    href: '/forms',
    permissions: ['settings.manage'],
    actions: [
      { title: 'Kies het juiste formulier', description: 'De pagina opent met Inzetrapport. Gebruik de andere tab voor het Incidentformulier.' },
      { title: 'Velden en tussenkoppen opbouwen', description: 'Klik of sleep onderdelen naar het canvas en zet ze in de gewenste volgorde. Het canvas is een voorbeeld; de uiteindelijke mobiele weergave kan anders zijn.' },
      { title: 'Veldsoort kiezen', description: 'Kies uit tekst, groot tekstvak, getal, Nederlands of Belgisch telefoonnummer, vluchttijd, keuzelijst, keuzerondjes, vinkvak of een tussenkop.' },
      { title: 'Veldinstellingen aanpassen', description: 'Kies naam, verplichting, breedte, zichtbaarheid en antwoorden bij een keuzeveld. Een keuzeveld heeft minimaal twee antwoorden nodig.' },
      { title: 'Incidentformulier indelen', description: 'Verplaats vaste webonderdelen en voeg eigen velden toe. De vergrendelde onderdelen voor incident, melder en locatie blijven nodig om incidenten goed te laten werken.' },
      { title: 'Velden beschikbaar maken voor berichten', description: 'Een incidentveld kan als variabele in een pushbericht worden gebruikt. Velden uit het inzetrapport worden niet in pushberichten gebruikt.' },
      { title: 'Inzetrapport voor de operator-app', description: 'Bepaal per inzetrapportveld of het in de operator-app beschikbaar is. Er moet altijd minimaal één zichtbaar invoerveld overblijven.' },
      { title: 'Wijzigingen opslaan', description: 'Aanpassingen worden pas actief na Formulier opslaan. Er is geen automatisch opslaan of oude versie om terug te zetten. Verwijder gebruikte velden daarom voorzichtig.' },
    ],
  },
  {
    id: 'branding',
    group: 'management',
    title: 'Branding en berichten',
    summary: 'Pas naam, logo en teksten van meldingen aan.',
    icon: Palette,
    href: '/branding',
    permissions: ['settings.manage'],
    actions: [
      { title: 'Algemene teksten instellen', description: 'Pas namen, inlogteksten, Authenticatornaam en de afzender van e-mail aan via de tab Algemeen.' },
      { title: 'Logo beheren', description: 'Upload of verwijder het logo via de tab Logo. Een logowijziging wordt meteen opgeslagen; andere tekstwijzigingen pas met Branding opslaan.' },
      { title: 'Pushteksten aanpassen', description: 'Stel vooraankondiging, alarmering, opschaling, nadere info en annulering samen. Deze sjablonen gelden alleen voor incidentmeldingen, niet voor handmatige push.' },
      { title: 'Variabelen gebruiken', description: 'Voeg alleen variabelen toe die bij het gekozen bericht staan. DIS vult deze bij verzending met de gegevens van het incident.' },
      { title: 'E-mailteksten aanpassen', description: 'Beheer de uitnodigingsmail en de afzonderlijke verloopmails voor certificaten en middelen.' },
      { title: 'Verloopwaarschuwingen instellen', description: 'Kies per certificaat of middel hoeveel dagen vooraf wordt gewaarschuwd. Op de verloopdatum wordt ook een mail gestuurd.' },
    ],
  },
  {
    id: 'admin',
    group: 'management',
    title: 'Instellingen en appbeheer',
    summary: 'Beheer alleen de systeemonderdelen waarvoor jouw account toestemming heeft.',
    icon: Settings,
    href: '/admin',
    permissions: ['settings.manage', 'settings.push.tokens.manage', 'system.health.view', 'system.developer-access.manage'],
    anyPermission: true,
    actions: [
      { title: 'Push en mail instellen', description: 'Controleer de verbinding voor pushmeldingen en de e-mailinstellingen. Sla mailinstellingen eerst op; de testmail gaat daarna naar je eigen e-mailadres.', permissions: ['settings.manage'] },
      { title: 'Heartbeat en beveiliging instellen', description: 'Kies het heartbeat-interval, de algemene MFA-regel en de wachtwoordeisen voor gebruikers.', permissions: ['settings.manage'] },
      { title: 'Kaart en incidentlog instellen', description: 'Beheer meldkamers, de bewaartermijn van locaties en welke incidentlogregels mobiele gebruikers mogen zien. Verwijderde meldkamers verdwijnen pas na opslaan.', permissions: ['settings.manage'] },
      { title: 'Store-review koppeling maken', description: 'Maak alleen voor een appstore-controle een reviewcode. De code kan zes uur meermaals worden gebruikt en een gekoppelde reviewtoegang blijft maximaal 24 uur geldig. Een nieuwe code trekt oudere toegang niet in.', permissions: ['settings.manage'] },
      { title: 'Mobiele toestellen beheren', description: 'Bekijk de maximaal 100 actieve toestelregistraties. Intrekken gebeurt direct, meldt de app af en kan push en beschikbaarheid van de gebruiker aanpassen.', permissions: ['settings.push.tokens.manage'] },
      { title: 'Systeemversie bekijken', description: 'Bekijk de huidige versie en de laatste bekende updategegevens.', permissions: ['system.health.view'] },
      { title: 'Systeem bijwerken', description: 'Start alleen een update op een gepland moment. Er kan maar één update tegelijk lopen; Applicatie bijwerken slaat systeemupdates over.', permissions: ['system.health.view', 'system.update.execute'] },
      { title: 'Server herstarten', description: 'De knop is alleen beschikbaar wanneer een herstart nodig is en geen update loopt. Gebruik hem alleen op een operationeel verantwoord moment.', permissions: ['system.health.view', 'system.reboot.execute'] },
      { title: 'Tijdelijke ontwikkeltoegang beheren', description: 'Een nieuwe sleutel wordt één keer getoond en vervangt de vorige. Kies zo weinig mogelijk toegang, een einddatum en zo nodig toegestane IP-adressen.', permissions: ['system.developer-access.manage'] },
    ],
  },
  {
    id: 'audit',
    group: 'management',
    title: 'Auditlog',
    summary: 'Zoek terug wie een belangrijke wijziging heeft uitgevoerd.',
    icon: ShieldCheck,
    href: '/audit',
    permissions: ['audit.view', 'status.audit.view'],
    anyPermission: true,
    actions: [
      { title: 'Beheer- en incidentacties bekijken', description: 'Filter op gebruiker, een deel van de actie of periode. Je ziet maximaal 150 recente regels met uitvoerder, doel, IP-adres en reden.', permissions: ['audit.view'] },
      { title: 'Statuswijzigingen bekijken', description: 'Filter op gebruiker en periode. Je ziet maximaal 150 wijzigingen met oude en nieuwe status, uitvoerder en reden.', permissions: ['status.audit.view'] },
    ],
  },
  {
    id: 'backups',
    group: 'management',
    title: 'Backups',
    summary: 'Controleer of reservekopieën worden gemaakt en bruikbaar zijn.',
    icon: DatabaseBackup,
    href: '/backups',
    permissions: ['backups.manage'],
    actions: [
      { title: 'Opslaglocatie kiezen', description: 'Kies de vaste lokale map of een netwerkmap. Paden ophalen toont beschikbare mappen, maar test niet of er echt naar geschreven kan worden.' },
      { title: 'Automatische backups instellen', description: 'Kies dagelijks of wekelijks, een dag en tijd en hoeveel backups bewaard blijven. Nul betekent onbeperkt bewaren.' },
      { title: 'Rapportontvangers kiezen', description: 'Kies afzonderlijk welke actieve gebruikers bericht krijgen bij een geslaagde of mislukte backup.' },
      { title: 'Backup maken en controleren', description: 'Start een handmatige reservekopie en controleer in het overzicht of deze zonder fouten is afgerond.' },
      { title: 'Backup herstellen', description: 'Herstellen vervangt database en bestanden; nieuwere gegevens kunnen verdwijnen. Gebruik alleen een gecontroleerde backup, de juiste encryptiesleutel en een onderhoudsmoment.' },
      { title: 'ZIP-backup uploaden', description: 'Een volledige ZIP-backup wordt eerst gecontroleerd en daarna direct hersteld.' },
    ],
  },
  {
    id: 'system',
    group: 'management',
    title: 'Systeemstatus',
    summary: 'Bekijk de interne zelfcontrole, live serverbelasting en de belangrijkste ingestelde systeemonderdelen.',
    icon: Monitor,
    href: '/system',
    permissions: ['system.health.view'],
    actions: [
      { title: 'Zelfcontrole bekijken', description: 'Bekijk de algemene status, de tijd dat de server actief is en het controletijdstip. DIS test de database, cache en lokale opslag.' },
      { title: 'Instellingen controleren', description: 'Bekijk welke achtergrond- en liveverbinding zijn ingesteld en of de gegevens voor pushmeldingen aanwezig zijn.' },
      { title: 'Grenzen van de controle', description: 'Dit scherm bewijst niet dat achtergrondtaken worden verwerkt of dat een liveverbinding echt werkt. Herlaad de browserpagina voor een nieuwe controle.' },
    ],
  },
];

export function HelpPage() {
  const { user } = useAuth();
  const [query, setQuery] = useState('');
  const access = useMemo<AccessContext>(() => {
    const roles = user?.roles ?? [];
    const webRoles = roles.filter((role) => role.can_use_admin_app);
    return {
      permissions: new Set(webRoles.flatMap((role) => role.permissions?.map((permission) => permission.name) ?? [])),
      operatorApp: roles.some((role) => role.can_use_operator_app),
      adminApp: roles.some((role) => role.can_use_admin_app),
    };
  }, [user]);

  const accessibleTopics = useMemo(() => {
    return helpTopics.flatMap((topic) => {
      if (!hasAccess(topic, access)) {
        return [];
      }

      const actions = topic.actions.filter((action) => hasAccess(action, access));
      if (actions.length === 0) {
        return [];
      }

      const guides = (manualGuides[topic.id] ?? []).filter((guide) => hasAccess(guide, access));
      return [{ ...topic, actions, guides } satisfies AccessibleHelpTopic];
    });
  }, [access]);

  const numberedTopics = useMemo(() => {
    let groupNumber = 0;

    return helpGroups.flatMap((group) => {
      const groupTopics = accessibleTopics.filter((topic) => topic.group === group.id);
      if (groupTopics.length === 0) {
        return [];
      }

      groupNumber += 1;
      return groupTopics.map((topic, topicIndex) => {
        const chapterNumber = `${groupNumber}.${topicIndex + 1}`;

        return {
          ...topic,
          chapterNumber,
          groupNumber,
          guides: topic.guides.map((guide, guideIndex) => ({
            ...guide,
            chapterNumber: `${chapterNumber}.${guideIndex + 1}`,
          })),
        } satisfies VisibleHelpTopic;
      });
    });
  }, [accessibleTopics]);

  const visibleTopics = useMemo(() => {
    const normalizedQuery = query.trim().toLocaleLowerCase('nl-NL');

    return numberedTopics.flatMap((topic) => {
      const topicAndActionText = [
        topic.title,
        topic.summary,
        ...topic.actions.flatMap((action) => [action.title, action.description]),
      ]
        .join(' ')
        .toLocaleLowerCase('nl-NL');
      const topicMatches = normalizedQuery === '' || topicAndActionText.includes(normalizedQuery);
      const matchingGuides = normalizedQuery === ''
        ? topic.guides
        : topic.guides.filter((guide) => manualGuideSearchText(guide).includes(normalizedQuery));

      if (!topicMatches && matchingGuides.length === 0) {
        return [];
      }

      return [{
        ...topic,
        guides: normalizedQuery !== '' && !topicMatches ? matchingGuides : topic.guides,
      } satisfies VisibleHelpTopic];
    });
  }, [numberedTopics, query]);

  const groupedTopics = helpGroups.flatMap((group) => {
    const topics = visibleTopics.filter((topic) => topic.group === group.id);
    if (topics.length === 0) {
      return [];
    }

    return [{ ...group, chapterNumber: topics[0].groupNumber, topics }];
  });
  const visibleGuideCount = visibleTopics.reduce((total, topic) => total + topic.guides.length, 0);

  return (
    <div className="page-stack help-page">
      <section className="help-intro" aria-labelledby="help-title">
        <div className="help-intro__icon" aria-hidden><BookOpen size={24} /></div>
        <div>
          <span className="help-intro__eyebrow">Handleiding voor jouw toegang</span>
          <h2 id="help-title">Gebruikershandleiding</h2>
          <p>Volg concrete stappen met de echte knopnamen. Je ziet {visibleGuideCount} werkwijzen voor de onderdelen die jij mag gebruiken.</p>
        </div>
        <label className="help-search">
          <span className="sr-only">Zoeken in help</span>
          <Search aria-hidden size={18} />
          <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Zoek in de handleiding" type="search" />
        </label>
      </section>

      {visibleTopics.length > 0 ? (
        <div className="help-layout">
          <aside className="help-index" aria-label="Help onderwerpen">
            <span>Onderwerpen</span>
            <nav>
              {visibleTopics.map((topic) => {
                const Icon = topic.icon;
                return (
                  <a href={`#help-${topic.id}`} key={topic.id}>
                    <Icon aria-hidden size={16} />
                    <span className="help-index__label">
                      <span className="help-chapter-number">{topic.chapterNumber}</span>
                      <span>{topic.title}</span>
                    </span>
                  </a>
                );
              })}
            </nav>
          </aside>

          <div className="help-content">
            {groupedTopics.map((group) => (
              <section className="help-group" aria-labelledby={`help-group-${group.id}`} key={group.id}>
                <h2 id={`help-group-${group.id}`}>
                  <span className="help-chapter-number">{group.chapterNumber}</span>
                  <span>{group.label}</span>
                </h2>
                <div className="help-topic-list">
                  {group.topics.map((topic) => <HelpTopicCard access={access} topic={topic} key={topic.id} />)}
                </div>
              </section>
            ))}
          </div>
        </div>
      ) : (
        <section className="help-empty" aria-live="polite">
          <Search aria-hidden size={24} />
          <h2>Geen uitleg gevonden</h2>
          <p>Probeer een korter woord, zoals <strong>status</strong>, <strong>incident</strong> of <strong>toestel</strong>.</p>
          <button className="secondary-button" type="button" onClick={() => setQuery('')}>Zoekopdracht wissen</button>
        </section>
      )}
    </div>
  );
}

function HelpTopicCard({ access, topic }: { access: AccessContext; topic: VisibleHelpTopic }) {
  const Icon = topic.icon;

  return (
    <article className="help-topic" id={`help-${topic.id}`}>
      <header className="help-topic__header">
        <span className="help-topic__icon" aria-hidden><Icon size={20} /></span>
        <div>
          <h3>
            <span className="help-chapter-number">{topic.chapterNumber}</span>
            <span>{topic.title}</span>
          </h3>
          <p>{topic.summary}</p>
        </div>
        {topic.href ? <Link className="help-topic__link" href={topic.href}>Open onderdeel</Link> : null}
      </header>

      {topic.pairingGuide ? <PairingGuide access={access} /> : null}

      {topic.guides.length > 0 ? <ManualSection guides={topic.guides} topicId={topic.id} /> : null}

      <section className="help-quick-reference" aria-labelledby={`help-quick-${topic.id}`}>
        <h4 id={`help-quick-${topic.id}`}>Kort overzicht</h4>
        <ul className="help-action-list">
          {topic.actions.map((action) => (
            <li key={action.title}>
              <CheckCircle2 aria-hidden size={17} />
              <div>
                <strong>{action.title}</strong>
                <span>{action.description}</span>
              </div>
            </li>
          ))}
        </ul>
      </section>
    </article>
  );
}

function ManualSection({ guides, topicId }: { guides: readonly NumberedManualGuide[]; topicId: string }) {
  return (
    <section className="help-manual" aria-labelledby={`help-manual-${topicId}`}>
      <header className="help-manual__header">
        <span className="help-manual__icon" aria-hidden><ClipboardList size={20} /></span>
        <div>
          <span>Volledige handleiding</span>
          <h4 id={`help-manual-${topicId}`}>Stap voor stap</h4>
        </div>
      </header>
      <div className="help-manual__guides">
        {guides.map((guide, guideIndex) => (
          <ManualGuideDisclosure guide={guide} initiallyOpen={guideIndex === 0} key={guide.id} />
        ))}
      </div>
    </section>
  );
}

function ManualGuideDisclosure({ guide, initiallyOpen }: { guide: NumberedManualGuide; initiallyOpen: boolean }) {
  const [open, setOpen] = useState(initiallyOpen);

  return (
    <details className="help-guide" open={open} id={`guide-${guide.id}`} onToggle={(event) => setOpen(event.currentTarget.open)}>
      <summary>
        <span>
          <strong>
            <span className="help-chapter-number">{guide.chapterNumber}</span>
            <span>{guide.title}</span>
          </strong>
          <small>{guide.intro} · {guide.steps.length} stappen</small>
        </span>
        <ChevronDown aria-hidden size={19} />
      </summary>
      <div className="help-guide__body">
        {guide.prerequisites && guide.prerequisites.length > 0 ? (
          <div className="help-guide__before">
            <strong>Voor je begint</strong>
            <ul>{guide.prerequisites.map((item) => <li key={item}>{item}</li>)}</ul>
          </div>
        ) : null}
        <ol className="help-guide__steps">
          {guide.steps.map((step, stepIndex) => (
            <li key={`${guide.id}-${stepIndex}-${step.label}`}>
              <span className="help-guide__step-number" aria-hidden>{stepIndex + 1}</span>
              <div>
                <strong>{step.label}</strong>
                <p>{step.description}</p>
              </div>
            </li>
          ))}
        </ol>
        <div className="help-guide__result">
          <CheckCircle2 aria-hidden size={18} />
          <div><strong>Daarna</strong><p>{guide.result}</p></div>
        </div>
        {guide.warning ? (
          <div className="help-guide__warning">
            <CircleAlert aria-hidden size={18} />
            <div><strong>Let op</strong><p>{guide.warning}</p></div>
          </div>
        ) : null}
      </div>
    </details>
  );
}

function manualGuideSearchText(guide: ManualGuide): string {
  return [
    guide.title,
    guide.intro,
    ...(guide.prerequisites ?? []),
    ...guide.steps.flatMap((step) => [step.label, step.description]),
    guide.result,
    guide.warning ?? '',
  ]
    .join(' ')
    .toLocaleLowerCase('nl-NL');
}

function PairingGuide({ access }: { access: AccessContext }) {
  const availableApps = [
    access.operatorApp ? 'Operator-app' : null,
    access.adminApp ? 'Admin-app' : null,
  ].filter((value): value is string => value !== null);

  return (
    <div className="pairing-help">
      <ol className="pairing-steps">
        <li><strong>Open Profiel.</strong><span>Ga naar <b>Mijn toestellen</b> en kies <b>Toestel toevoegen</b>.</span></li>
        <li><strong>Kies de app.</strong><span>Selecteer {availableApps.join(' of ')}. Je ziet alleen apps waarvoor je toegang hebt.</span></li>
        <li><strong>Scan of open.</strong><span>Scan de QR-code vanuit de mobiele app. Zit je op dezelfde telefoon, kies dan <b>Open app en koppel dit toestel</b>.</span></li>
        <li><strong>Controleer de koppeling.</strong><span>Na het koppelen staat het toestel bij <b>Mijn toestellen</b>. Geef het toestel een herkenbare naam wanneer de app daarom vraagt.</span></li>
      </ol>

      <div className="pairing-pictures">
        <figure>
          <div className="pairing-web-picture" role="img" aria-label="Voorbeeld van Profiel, Mijn toestellen en de knop Toestel toevoegen">
            <div className="pairing-picture__bar"><span /><span /><span /><b>Profiel</b></div>
            <div className="pairing-picture__page">
              <span className="pairing-picture__label">Mijn toestellen</span>
              <div className="pairing-picture__row">
                <span className="pairing-picture__device"><Smartphone size={17} /> Gekoppelde toestellen</span>
                <span className="pairing-picture__button">Toestel toevoegen</span>
              </div>
              <div className="pairing-picture__modal">
                <b>Toestel toevoegen</b>
                <span>{availableApps.join(' / ')}</span>
                <div className="pairing-picture__qr"><QrCode size={62} /></div>
              </div>
            </div>
          </div>
          <figcaption>In de webapp maak je de tijdelijke koppelcode.</figcaption>
        </figure>

        <figure>
          <div className="pairing-phone-picture" role="img" aria-label="Voorbeeld van het mobiele scherm met de knop QR-code scannen en handmatig koppelen">
            <div className="pairing-phone-picture__speaker" />
            <div className="pairing-phone-picture__app-icon"><RadioTower size={21} /></div>
            <strong>Koppel je app</strong>
            <span>Scan de code uit je profiel</span>
            <div className="pairing-phone-picture__scanner">
              <i /><i /><i /><i />
              <QrCode size={54} />
            </div>
            <div className="pairing-phone-picture__primary">QR-code scannen</div>
            <div className="pairing-phone-picture__secondary">Handmatig koppelen</div>
          </div>
          <figcaption>In de mobiele app scan je de code of vul je server en koppelcode in.</figcaption>
        </figure>
      </div>
    </div>
  );
}

function hasAccess(rule: AccessRule, access: AccessContext): boolean {
  if (rule.mobileApp === 'operator' && !access.operatorApp) {
    return false;
  }
  if (rule.mobileApp === 'admin' && !access.adminApp) {
    return false;
  }
  if (rule.mobileApp === 'any' && !access.operatorApp && !access.adminApp) {
    return false;
  }

  const permissions = rule.permissions ?? [];
  if (permissions.length === 0) {
    return true;
  }

  return rule.anyPermission
    ? permissions.some((permission) => access.permissions.has(permission))
    : permissions.every((permission) => access.permissions.has(permission));
}
