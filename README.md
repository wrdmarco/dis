# D.I.S. — Drone Inzet Systeem

D.I.S. ondersteunt het volledige operationele proces van een droneorganisatie: van aanvraag en inzetvoorbereiding tot alarmering, respons, live operationeel beeld en rapportage. Deze repository is de bron van waarheid voor de webapp, Laravel-API, productie-infrastructuur en beheer-/herstelscripts.

De native Operator-apps staan afzonderlijk in [`wrdmarco/dis-mobile`](https://github.com/wrdmarco/dis-mobile). Beheerders werken uitsluitend in de webapp; er wordt geen mobiele Admin-app gebouwd of gepubliceerd.

De actuele webversie staat in [`VERSION`](VERSION). Web- en mobiele versies hebben ieder hun eigen releasecyclus.

## Functionaliteit

De belangrijkste productgebieden zijn:

- aanvragen registreren, beoordelen en voorbereiden als inzet;
- teams selecteren, vooraankondigingen versturen en definitief alarmeren;
- beschikbaarheid, planning, vakanties en operationele status beheren;
- gebruikers, rollen, teams, certificeringen en middelen beheren;
- live locatie, route-ETA en het operationele kaartbeeld tonen;
- agenda, groepsinschrijvingen, productverzoeken en rapportages afhandelen;
- operationeel weer, UAV Forecast en radarproducten tonen;
- meerdere wallboards, playlists, media, nieuws, ticker en inzetfocus beheren;
- Android via FCM en iOS via APNs alarmeren;
- systeemupdates, logs, queues, back-ups en optionele OSRM-routering beheren.

Een vereenvoudigde operationele keten:

```text
Aanvraag
    -> prioriteitsbesluit en inzetvoorstel
    -> conceptinzet
    -> vooraankondiging of definitieve alarmering
    -> operatorrespons en inzetstatus
    -> optionele live locatie en ETA
    -> rapportage en afsluiting
```

Laravel blijft in iedere stap het systeem van waarheid. Browser- of appstatus is nooit gezaghebbend voor identiteit, rechten, beschikbaarheid, pushbereikbaarheid, certificaatgeldigheid, inzetstatus of locatie-toestemming.

## Terminologie

| Nederlandse term | Domeinmodel | Betekenis |
|---|---|---|
| Aanvraag | `DeploymentRequest` | pre-operationele intake en besluitvorming voordat een inzet bestaat |
| Inzet | `Deployment` | operationele opdracht met teams, status, log en rapportage |
| Alarmering | `DispatchRequest` | vooraankondiging, definitieve oproep of technische testmelding |
| Productverzoek | `ProductRequest` | niet-operationeel verzoek dat via een eigen workflow wordt afgehandeld |

### Proefalarmrapportage

De beheerpagina **Proefalarmering** combineert de losse dispatches van de laatste automatische weekrun via `GET /api/test-alert/runs/latest`. Deze web-only rapportage scheidt de geselecteerde doelgroep, het duurzaam klaarzetten van dispatches, acceptatie door FCM/APNs en de bevestiging **Ontvangen** in de operator-app. De historische doelgroep bevat alleen gebruikers die bij de start actief waren, push hadden ingeschakeld en minimaal één actieve operatorkoppeling hadden; gebruikers die toen buiten die selectie vielen worden niet achteraf aan de run toegevoegd. Provideracceptatie is geen bewijs dat een toestel de melding heeft getoond; alleen een geregistreerde gebruikersbevestiging bewijst ontvangst binnen het huidige contract. Ontbrekend of door retentie verwijderd providerbewijs wordt daarom als onbekend getoond en nooit als mislukte aflevering.

De canonieke webpaden zijn `/aanvragen`, `/inzetten` en `/verzoeken`. Historische API-aliases kunnen voor bestaande clients aanwezig blijven, maar nieuwe code gebruikt de actuele deploymentterminologie.

## Architectuur

| Laag | Technologie | Rol |
|---|---|---|
| Reverse proxy | Nginx | lokale HTTP-routering, securityheaders, byte ranges en onderhoudsgrens |
| Backend | PHP 8.5, Laravel 13 en Sanctum | API, validatie, autorisatie, workflows, queues en scheduling |
| Frontend | Node.js 22+, Next.js 15 en React 19 | webportaal, beheer en wallboardruntime |
| Database | PostgreSQL | duurzame operationele en configuratiestatus |
| Cache en queues | Redis | cache, rate limits, queuewerk en coördinatie |
| Realtime web | Laravel Reverb | private, servergeautoriseerde webkanalen via loopback |
| Procesbeheer | systemd | onafhankelijk herstartbare frontend-, queue-, push-, media- en schedulerdiensten |
| Routering | optioneel OSRM via Podman | lokale weg-ETA en routegeometrie op `127.0.0.1:5000` |

De kernapplicatie draait bare metal. Alleen de optionele, vastgepinde OSRM-runtime gebruikt een container. Er is geen Docker-gebaseerde productie-installatie.

### API en authenticatie

De actuele routes staan in [`webapp/backend/routes/api.php`](webapp/backend/routes/api.php) en hebben `/api` als basis. Normale JSON-responses gebruiken deze vaste vormen:

```json
{ "data": {} }
```

```json
{ "error": { "code": "example", "message": "...", "details": {} } }
```

De webapp gebruikt een versleutelde, database-backed `__Host-dis_session`-cookie met CSRF- en origincontrole. Native clients gebruiken expirerende Sanctum-bearertokens met een afgebakend clienttype. Deze authenticatievormen zijn niet uitwisselbaar.

Na een geldige webaanmelding met wachtwoord kan de server een kortlevend goedkeuringsverzoek met het standaard toestelgeluid sturen naar bereikbare Operator-apps die de capability `web_login_approval_v1` expliciet hebben geregistreerd. De push bevat alleen een opaque verzoek-ID en de bestaande sessiebinding; browser-, IP- en verificatiegegevens worden pas na authenticatie door de doelapp opgehaald. Goedkeuren vereist nummervergelijking en toestelbevestiging, waarna uitsluitend de oorspronkelijke browsersessie de login kan afronden. Afwijzen blokkeert die loginpoging. Een authenticatorcode of herstelcode blijft gedurende de hele flow als onafhankelijke 2FA-methode beschikbaar.

API-contracten worden handmatig gespiegeld in [`webapp/frontend/src/types/api.ts`](webapp/frontend/src/types/api.ts) en beide mobiele clients. Er is geen OpenAPI-generator of gedeeld contractpakket. Maak contractwijzigingen daarom eerst additief en backwards-compatible aan de serverkant.

## Productievereisten

- Ubuntu 26.04 LTS;
- root- of `sudo`-toegang;
- een root-gecontroleerde checkout rechtstreeks onder `/opt/dis`;
- een stabiele publieke hostname met correcte DNS;
- HTTPS-terminatie vóór de lokale Nginx-server;
- uitgaand netwerkverkeer naar de geconfigureerde officiële Ubuntu-pakketbronnen en HTTPS naar GitHub, Composer, npm en ingeschakelde providerdiensten;
- voldoende opslag voor PostgreSQL, uploads, back-ups en eventuele wallboardmedia;
- afzonderlijk beoordeelde capaciteit wanneer OSRM wordt gebruikt.

Deze repository vraagt of vernieuwt geen TLS-certificaten. De publieke edge beheert TLS en HSTS en moet inkomende `X-Forwarded-*`-headers overschrijven in plaats van clientwaarden aan te vullen. `TRUSTED_PROXIES` bevat uitsluitend de echte proxyadressen of -CIDR's en nooit een wildcard. De interne Nginx-configuratie luistert op poort 80; publiceer die niet rechtstreeks als onbeveiligde productie-origin.

## Installatie

Kloon de repository op een schone Ubuntu 26.04-host naar het canonieke pad en start setup:

```bash
sudo git clone https://github.com/wrdmarco/dis.git /opt/dis
cd /opt/dis
sudo bash setup.sh --domain dis.example.nl
```

`setup.sh`:

- valideert Ubuntu, paden en root-eigenaarschap;
- installeert Nginx, PostgreSQL, Redis, PHP 8.5, Node.js/npm, Composer en benodigde systeempakketten;
- maakt de afgeschermde systeemidentiteiten en persistente mappen;
- genereert applicatie-, database- en Reverbgeheimen wanneer die nog ontbreken;
- maakt en migreert de database en seedt alleen de initiële systeemconfiguratie;
- installeert backenddependencies en bouwt de frontend reproduceerbaar uit lockfiles;
- installeert Nginx-, PHP- en systemd-configuratie;
- start de runtime en voert lokale readinesschecks uit.

Na een geslaagde installatie opent u:

```text
https://dis.example.nl/setup
```

De wizard configureert de tenantnaam, publieke URL, eerste systeembeheerder, optionele mailinstellingen en optionele Android/Firebase-configuratie. APNs voor iOS wordt niet in deze eerste wizard ingesteld; dat gebeurt daarna in het beheerpaneel. De setup sluit zodra de eerste configuratie is afgerond of al een gebruiker bestaat.

OSRM blijft optioneel en meldt een gedegradeerde routeringsstatus totdat een bevoegde beheerder bewust de runtime en kaartdata installeert.

### Setupopties

```text
--domain <host>       verplichte publieke hostname, tenzij DIS_DOMAIN is gezet
--skip-healthcheck    sla alleen de extra afsluitende healthcheck over
```

De ondersteunde productie-installatie gebruikt uitsluitend `/opt/dis` en `/opt/dis-data`. Hoewel interne lifecyclehelpers deze paden als variabelen behandelen, gebruiken de normale Nginx-, systemd- en opruimcontracten vaste canonieke paden. Overschrijf `DIS_INSTALL_PATH` of `DIS_DATA_PATH` daarom niet.

## Configuratie en blijvende gegevens

Broncode en persistente status zijn bewust gescheiden:

| Doel | Pad |
|---|---|
| applicatiecheckout | `/opt/dis` |
| blijvende gegevens | `/opt/dis-data` |
| canonieke productieconfiguratie | `/opt/dis-data/.env` |
| compatibele applicatiesymlink | `/opt/dis/.env` |
| Laravel-storage | `/opt/dis-data/webapp/backend/storage` |
| lokale back-ups | `/opt/dis-data/backup` |
| back-upencryptiesleutel | `/opt/dis-data/secrets/backup-encryption.key` |
| sleutelgeneratiemarkering | `/opt/dis-data/secrets/backup-encryption.key.generation-v2` |
| gegenereerde Nginx-configuratie | `/opt/dis-data/storage/generated/nginx/dis.conf` |
| OSRM-data | `/opt/dis-data/osrm` |
| gesaneerde opslagmeting | `/var/lib/dis-system-metrics/storage-usage.json` |
| update-runnerlog | `/var/log/dis/system-update-runner.log` |

De root-[`.env.example`](.env.example) documenteert de lifecycle- en basisproductieconfiguratie. Aanvullende Laraveldefaults staan in [`webapp/backend/.env.example`](webapp/backend/.env.example); veel daarvan worden in productie database-backed via het beheerpaneel ingesteld. Controleer in ieder geval:

- `APP_URL`, `CORS_ALLOWED_ORIGINS` en `SESSION_TRUSTED_ORIGINS`;
- `TRUSTED_PROXIES` voor de echte proxyketen;
- `SECURITY_CONTACT` voor de publieke RFC 9116-contactvermelding;
- database-, Redis-, Reverb- en mailinstellingen;
- providerinstellingen voor geocoding, weer en routing;
- opslag- en back-upinstellingen.

Veel functionele instellingen worden database-backed vanuit het beheerpaneel beheerd, waaronder rollen, formulieren, pushproviders, storelinks, retentie, wallboards en back-ups. Bewaar secrets nooit in Git, documentatie, issues of onbeveiligde logs.

### Gesaneerd opslagoverzicht

Een afgeschermde root-service meet elk uur de toegewezen schijfblokken van een vaste lijst directe mappen onder `/opt/dis-data`. De eerste meting start kort nadat de timer is geactiveerd. Alleen `backup`, `backup-imports`, `backup-requests`, `backup-request-work`, `legacy-backup-state`, `osrm`, `osrm-admin`, `playwright-browsers`, `storage` en `webapp` kunnen als vaste, veilige identifiers in het versie-1-snapshot verschijnen. Ontbrekende mappen worden overgeslagen.

De collector volgt geen symbolische links, verlaat het filesystem van `/opt/dis-data` niet en publiceert uitsluitend een UTC-meettijd plus byte-aantallen. De map met secrets, onbekende directe mappen, onderliggende namen, paden, eigenaren en foutdetails worden nooit gepubliceerd. Het resultaat wordt atomisch als root aangemaakt en is voor PHP-FPM alleen-lezen. Omdat dit toegewezen blokken binnen `/opt/dis-data` zijn, hoeft de som niet gelijk te zijn aan het totale gebruik van het onderliggende volume.

## Beveiligingsmodel

- Server-side policies, middleware en requestvalidatie zijn gezaghebbend; frontendchecks zijn alleen UX.
- RBAC beschermt alle bevoorrechte handelingen.
- 2FA is verplicht voor beheerder- en coördinatorrollen.
- Bevoorrechte acties schrijven auditregels.
- Auth- en schrijfintensieve routes zijn begrensd met rate limits.
- Browsermutaties vereisen CSRF en een vertrouwde origin.
- Wallboards gebruiken een eigen, beperkte HttpOnly-sessie en erven nooit een beheerdersessie.
- Push- en locatiegegevens worden alleen via de bestaande afgeschermde workflows verwerkt.
- Securityheaders en CSP worden centraal gegenereerd; verruim providerorigins niet zonder review.
- Updates, restores en OSRM-mutaties lopen via afgeschermde rootbrokers en exclusieve operationele locks.

Timestamps worden als UTC opgeslagen. Nederlandse operationele weergaven converteren expliciet naar `Europe/Amsterdam`.

### Effectieve inzetbaarheid van middelen

De opgeslagen middelstatus blijft beschikbaar voor beheer en backwards compatibility. API-responses voegen daar `effective_status`, `is_effectively_ready` en `maintenance_overdue` aan toe. Een middel met status `ready` of `assigned` is alleen effectief inzetbaar wanneer de onderhoudsdatum ontbreekt, vandaag is of in de toekomst ligt volgens `Europe/Amsterdam`; een eerdere datum geldt direct als verlopen onderhoud.

Een ontvanger komt alleen in aanmerking voor een nieuwe vooraankondiging of inzet wanneer die een actieve, eenduidige gebruikerstoewijzing aan ten minste één effectief inzetbaar middel heeft. Een middel met meerdere open toewijzingen maakt uit veiligheidsoverwegingen niemand inzetgerechtigd. Deze fail-safe regel geldt ook voor opnieuw alarmeren en het verzenden van bestaande conceptvooraankondigingen; een gewijzigde conceptselectie wordt opnieuw gevuld binnen de ingestelde ontvangerslimiet. Annuleringen, uitsluitend informatieve meldingen en proefalarmen behouden hun bestaande bereik. Controleer daarom vóór uitrol de actuele middeltoewijzingen en onderhoudsdata; er vindt bewust geen automatische backfill plaats.

## Runtime en services

Naast Nginx, PHP-FPM, PostgreSQL en Redis installeert D.I.S. deze normale systemd-units:

| Unit | Functie |
|---|---|
| `dis-frontend` | Next.js-runtime op loopback |
| `dis-websocket` | Laravel Reverb voor de webapp |
| `dis-queue` | algemene asynchrone jobs |
| `dis-push@1` t/m `dis-push@4` | geïsoleerde pushworkers |
| `dis-media` | media-inspectie en transcodering |
| `dis-wallboard-live-ingress` | geauthenticeerde OBS RTMPS-ingress met een afzonderlijke Stream Key |
| `dis-wallboard-live` | lokale omzetting van de gevalideerde OBS-feed naar browsergeschikte HLS |
| `dis-scheduler` | Laravel-scheduler |
| `dis-deployment-enrichment` | locatieclassificatie van inzetten |
| `dis-storage-metrics.timer` | ieder uur een gesaneerde, alleen-lezen opslagmeting publiceren |
| `dis-backup-request.path` en `.timer` | afgeschermde back-up-/restorebroker |
| `dis-osrm-admin-request.path` en `.timer` | afgeschermde OSRM-beheerbroker |
| `dis-osrm` | optionele lokale routeringsservice |

Controleer de publieke applicatiestatus lokaal met:

```bash
sudo bash /opt/dis/scripts/healthcheck.sh
```

Handige diagnosecommando's:

```bash
sudo systemctl --failed
sudo systemctl status dis-frontend dis-websocket dis-queue dis-scheduler dis-wallboard-live
sudo journalctl -u dis-frontend.service -u dis-queue.service -u dis-websocket.service -u dis-wallboard-live.service
```

De beheerinterface biedt daarnaast afgeschermde systeemstatus, queuebeheer en een geredigeerde logviewer. Neem nooit tokens, wachtwoorden, volledige pushpayloads of secrets over in een supportmelding.

## Updates

Een normale server- en applicatie-update:

```bash
sudo update
```

Zonder opties maakt de updater eerst een back-up, actualiseert Ubuntu-pakketten, haalt de beheerde `main`-checkout op, bouwt backend en frontend opnieuw, voert alleen openstaande migraties uit, vernieuwt infrastructuurconfiguratie en opent productie pas na verplichte readinesschecks.

| Optie | Effect |
|---|---|
| `--skip-system` | sla Ubuntu package update/upgrade over |
| `--skip-app` | sla applicatiedeploy over |
| `--skip-source` | deploy de huidige checkout zonder upstream op te halen |
| `--skip-backup` | sla de normale pre-updateback-up over; alleen verantwoord met een recente, extern bewaarde en geverifieerde herstelkopie |
| `--skip-healthcheck` | sla alleen de extra eindcontrole over; verplichte readinesschecks blijven actief |

Voorbeelden:

```bash
sudo update --skip-system
sudo update --skip-system --skip-source
sudo update --skip-app
```

De productiecheckout is een beheerde deploycheckout, geen werkmap. Bij een bronupdate worden tracked bestanden gelijkgetrokken met upstream en worden niet-beheerde checkoutbestanden buiten expliciete runtime-uitzonderingen opgeruimd. Bewaar lokale wijzigingen in een afzonderlijke clone en push beoordeelde commits voordat productie wordt bijgewerkt.

Database-seeders draaien alleen tijdens de eerste setup. Gebruik `RUN_SEEDERS=1` uitsluitend bij een expliciet beoordeeld reseedmoment; normale updates mogen beheerinstellingen en operationele data niet overschrijven.

Een Git-push is geen productiedeploy. Productie verandert pas wanneer de bevoegde updateflow op de server wordt uitgevoerd.

## Onderhoudsmodus

Handmatig inschakelen:

```bash
sudo bash /opt/dis/scripts/maintenance.sh enable
```

Uitschakelen na controle:

```bash
sudo bash /opt/dis/scripts/maintenance.sh disable
```

Deploys en restores beheren deze grens automatisch. Tijdens onderhoud sluiten operationele API-, frontend- en websocketpaden gecontroleerd en krijgen gekoppelde wallboards vooraf een onderhoudsstatus. Alleen de updater probeert bij een fout vóór package- of bronmutatie de geverifieerde huidige release opnieuw te openen. Een mislukte directe deploy blijft fail-closed. Restore hanteert een eigen mutatiegrens en blijft na een fout die live state heeft geraakt eveneens in onderhoud, met betrokken services gestopt totdat herstel slaagt.

## Back-up en herstel

Maak een versleutelde back-up:

```bash
sudo bash /opt/dis/scripts/backup.sh
```

Het standaarddoel is `/opt/dis-data/backup/<timestamp>`. Vanuit beheer kan ook een gevalideerd Samba-doel actief zijn. Een lokale back-up op dezelfde schijf is geen volwaardige disaster-recoverykopie.

Nieuwe back-ups bevatten een versleutelde payload, checksummanifest en keyed HMAC. Verificatie en herstel weigeren plaintext-, niet-geauthenticeerde of structureel onveilige archieven. Bewaar een offline escrowkopie van zowel deze bestanden:

```text
/opt/dis-data/secrets/backup-encryption.key
/opt/dis-data/secrets/backup-encryption.key.generation-v2
```

Bewaar die escrowkopie nooit naast de back-uparchieven. Zonder de bijbehorende sleutel en generatiemarkering kan een vervangende server de back-up niet herstellen.

Verifieer vóór herstel:

```bash
sudo bash /opt/dis/scripts/verify-backup.sh /opt/dis-data/backup/<timestamp>
```

Herstel is destructief voor de huidige applicatiestatus: `pg_restore --clean` vervangt de bestaande databaseobjecten en de actuele storage-, backend-storage- en secretsbomen worden door de geverifieerde back-up vervangen. Start dit alleen na een gecontroleerd herstelbesluit, een geslaagde verificatie en bevestiging dat de gekozen back-up de bedoelde toestand bevat.

Herstel daarna met:

```bash
sudo bash /opt/dis/scripts/restore.sh /opt/dis-data/backup/<timestamp>
```

Herstel gebruikt de huidige applicatiecode, weigert een back-up van een nieuwere applicatieversie, voert de huidige migraties uit en trekt herstelde browser-, API-, pairing- en device-authenticatiestatus in. Na een mislukking die live state heeft geraakt, blijft onderhoud bewust actief voor handmatige diagnose.

De broker serialiseert back-up, verificatie, restore, update en andere kritieke mutaties. Start geen tweede handmatige operatie om een lopende operatie te omzeilen.

## Routering met OSRM

OSRM is optioneel. Wanneer het actief is:

- luistert het alleen op `127.0.0.1:5000`;
- gebruikt D.I.S. geen publieke OSRM-demo;
- draait een officiële image die aan een immutable digest is vastgepind;
- worden Nederland en België uit gecontroleerde Geofabrik-bronnen opgebouwd;
- vindt activatie atomair plaats met readinessprobes en rollback;
- blijft de backend bij storing beschikbaar met een begrensde fallback-ETA.

Installatie en kaartupdates worden bewust vanuit de afgeschermde pagina **Routering** gestart. Een normale D.I.S.-update downloadt geen kaartdata en wijzigt de vastgepinde image niet.

Diagnose:

```bash
sudo /usr/local/lib/dis/osrm-admin/osrm.sh status
sudo /usr/local/lib/dis/osrm-admin/osrm.sh health
sudo journalctl -u dis-osrm.service
sudo journalctl -u dis-osrm-admin-request.service
```

Lees vóór installatie, sizing, een handmatige import of herstel de volledige [OSRM-documentatie](infrastructure/osrm/README.md).

## Mobiele apps en store-only updates

Ondersteunde eindgebruikersinstallatie en reguliere updates lopen via Google Play en de Apple App Store. Interne testdistributie verloopt vanuit de mobiele repository via de Google Play-interne testtrack en TestFlight. De D.I.S.-server bewaart alleen configureerbare storelinks die onder meer tijdens registratie kunnen worden getoond en levert zelf geen mobiele binaries.

De runtime heeft geen:

- publieke `/download`-pagina;
- mobiele versiecatalogus of minimumversiegate;
- APK- of IPA-uploadendpoint;
- route voor het leveren van mobiele binaries.

Android controleert updates via Google Play Core. iOS gebruikt het normale App Store-updategedrag. Een DIS-versiebeleidendpoint wordt door geen van beide clients gebruikt.

Deploy en restore verwijderen de oude map `/opt/dis-data/webapp/backend/storage/app/android-apks` wanneer die nog bestaat; back-ups sluiten deze uitgefaseerde opslag uit. GitHub-releaseartefacten uit de mobiele repository zijn geen productiedownloadroute van deze webapp.

## Wallboards en media

Wallboards koppelen met een korte code en krijgen een afzonderlijke, scoped kiosk-sessie. Een tijdelijke contentfout verbreekt de pairing niet. Playlists, pagina's, overgangen, ticker, inzetfocus en displayprofiel worden per scherm of gedeelde playlist beheerd.

Beheerde afbeeldingen worden gevalideerd, opnieuw als WebP opgeslagen en zonder upscaling binnen Full HD passend gemaakt. Video wordt gecontroleerd en zo nodig naar een browserveilig H.264/AAC-profiel tot 1080p getranscodeerd. Lokale video ondersteunt geauthenticeerde byte ranges. Media- en playlistrevisies zorgen dat gekoppelde schermen gewijzigde bestanden niet onbeperkt blijven cachen.

### OBS Custom Service via RTMPS

Dit gebruikt hetzelfde bedieningsmodel als YouTube: OBS krijgt een vast **Server**-adres en een afzonderlijke geheime **Stream Key**. `dis-wallboard-live-ingress` beëindigt RTMPS, valideert de Stream Key voordat media wordt toegelaten en weigert een tweede publisher. Alleen de lokale `dis-wallboard-live`-worker mag de gevalideerde feed via loopback lezen. Die worker publiceert korte tijdelijke HLS-segmenten onder `/run/dis-wallboard-live/hls`; Laravel autoriseert ieder manifest en segment per gekoppeld wallboard. De tijdelijke segmenten worden niet geback-upt.

De ingest staat standaard uit. Genereer een unieke, willekeurige Stream Key van 32-79 URL-veilige tekens (`A-Z`, `a-z`, `0-9`, `.`, `_`, `~` en `-`); een code die uit steeds hetzelfde teken bestaat wordt geweigerd. Neem de echte key nooit op in documentatie, logs of supportberichten. Gebruik een eigen DNS-naam met een publiek vertrouwd certificaat. D.I.S. vraagt of vernieuwt dat certificaat niet zelf. Configureer in de beheerde serveromgeving bijvoorbeeld:

```dotenv
WALLBOARD_LIVE_STREAM_ENABLED=true
WALLBOARD_LIVE_STREAM_PUBLIC_HOST=ingest.example.nl
WALLBOARD_LIVE_STREAM_RTMPS_BIND_ADDRESS=0.0.0.0
WALLBOARD_LIVE_STREAM_RTMPS_PORT=1936
WALLBOARD_LIVE_STREAM_STREAM_KEY=
WALLBOARD_LIVE_STREAM_TLS_CERTIFICATE_PATH=/etc/letsencrypt/live/ingest.example.nl/fullchain.pem
WALLBOARD_LIVE_STREAM_TLS_PRIVATE_KEY_PATH=/etc/letsencrypt/live/ingest.example.nl/privkey.pem
WALLBOARD_LIVE_STREAM_STALE_SECONDS=12
```

Vul `WALLBOARD_LIVE_STREAM_STREAM_KEY` voor de eerste installatie buiten versiebeheer met de gegenereerde key. Daarna kan een beheerder met `wallboards.manage` en voltooide 2FA de actuele Stream Key alleen via een expliciete actie in het wallboardportaal ophalen. De gewone statuscontrole bevat nooit de key. Wisselen vereist een afzonderlijke gevaarsbevestiging, trekt de oude key direct in, activeert de nieuwe credential atomisch en meldt duidelijk dat OBS opnieuw moet verbinden. Als activatie of de bevestiging aan het portaal niet aantoonbaar slaagt, wordt de vorige key en runtime teruggezet of stoppen de live-streamservices fail-closed.

`PUBLIC_HOST` moet exact overeenkomen met de certificaathostnaam. De certificaat- en private-keypaden mogen naar door root beheerde certificaatbestanden verwijzen; de private-keybron moet uitsluitend voor root leesbaar zijn. Deployment volgt eventuele symlinks en controleert eigenaar, rechten, geldigheidsperiode, publieke vertrouwensketen, serverdoel, hostnaam en of certificaat en sleutel bij elkaar horen. Kies in OBS bij **Stream** de service **Custom...** (Custom Service) en gebruik:

- Server: `rtmps://<PUBLIC_HOST>:1936/live`;
- Stream Key: exact de apart beheerde code van 32-79 tekens;
- video: H.264, maximaal 1920x1080, keyframe-interval 2 seconden;
- videobitrate: maximaal 20 Mbit/s, bij voorkeur CBR;
- audio: AAC wanneer audio nodig is.

RTMPS versleutelt de verbinding; de Stream Key is een afzonderlijk, hoog-entropisch publish-token. Sta als defense-in-depth uitsluitend TCP-poort `1936` toe vanaf het vaste OBS-adres of het vertrouwde VPN. Plain RTMP luistert alleen op `127.0.0.1:19350` en mag nooit via firewall, NAT of proxy publiek worden gemaakt. Bij een andere `WALLBOARD_LIVE_STREAM_RTMPS_PORT` wordt alleen die ene TCP-poort voor dezelfde beperkte bron geopend.

Deployment installeert checksum-vastgepinde MediaMTX 1.20.0 voor amd64 of arm64 en verifieert zowel release-archief als binary. De Stream Key wordt alleen als SHA-256-hash aan de loopback-authenticator gegeven; de raw key zit uitsluitend in de aparte FFmpeg-inputcredential. Certificaat, private key, configuratie, hash en input-URL worden als root-only systemd-credentials geladen. MediaMTX- en FFmpeg-uitvoer die een mediapad zou kunnen bevatten wordt niet gelogd; de authenticator schrijft alleen begrensde, gesaneerde publish-resultaten met een tijdelijke bronhash naar journald en begrenst pogingen per bron. Beide services draaien als afzonderlijke niet-inlogbare gebruikers zonder toegang tot Laravel of `.env`.

Een Stream-Keywissel vanuit het portaal start de beveiligde refresh automatisch; daarvoor is geen handmatig rootcommando nodig. Voer na een certificaatvernieuwing of een handmatige wijziging van de beheerde live-streamconfiguratie als root `/usr/local/sbin/dis-wallboard-live-refresh` uit. De helper gebruikt dezelfde exclusieve onderhoudslock als deployment, bewaart de vorige credentialset, valideert en vernieuwt de nieuwe set en vereist daarna meerdere stabiele servicecontroles plus een echte lokale TLS-handshake. Bij mislukking wordt automatisch teruggerold. Koppel dit commando als deploy-hook aan de gebruikte certificaatbeheerder. De HLS-worker blijft stabiel wachten wanneer OBS offline is, weigert niet-H.264-video via de H.264-bitstreamcontrole, normaliseert eventuele audio naar AAC en houdt ieder segment onder 6 MiB. Een stille verbinding krijgt een begrensde read/write-time-out en de watchdog stopt een onafgerond segment na 12 seconden of bij overschrijding van de vaste bestands- en runtimegrenzen.

De runtime geeft een echte inzet prioriteit boven een vooraankondiging of testmelding. Tijdens onderhoud toont het wallboard de serverstatus en herstelt het automatisch nadat de health-gated heropening is voltooid.

## Ontwikkeling

### Repository-indeling

| Pad | Inhoud |
|---|---|
| [`webapp/backend`](webapp/backend) | Laravel-API, database, jobs, events en tests |
| [`webapp/frontend`](webapp/frontend) | Next.js-webapp, featurecomponenten en browsertests |
| [`infrastructure`](infrastructure) | Nginx, PHP, systemd, sudoers en OSRM-documentatie |
| [`scripts`](scripts) | setup, deploy, update, back-up, restore en contracttests |

### Backend

Gebruik een geïsoleerde PostgreSQL-testdatabase; voer tests nooit tegen productie uit.

```bash
cd webapp/backend
composer install
composer test
composer lint
```

Controllers blijven dun, Form Requests valideren en autoriseren, services bezitten workflows en repositories bezitten persistencequeries. Gebruik [`app/Http/Responses/ApiResponse.php`](webapp/backend/app/Http/Responses/ApiResponse.php) voor consistente responses.

### Frontend

```bash
cd webapp/frontend
npm ci
npm run typecheck
npm run lint
npm run test:security
npm run build
```

Playwright verwacht standaard een draaiende frontend op `http://127.0.0.1:3000`:

```bash
npm run test:e2e
```

Stel `DIS_E2E_BASE_URL` in voor een andere testorigin. De frontend gebruikt de bestaande `apiClient`, authcontext en realtimebridge; voeg geen tweede fetch- of sessielaag toe.

### Shellcontracten

Gerichte infrastructuurcontracten staan onder [`scripts/tests`](scripts/tests). Voer de relevante tests met Bash uit wanneer installatie-, update-, back-up-, wallboard- of OSRM-scripts wijzigen.

### Versieconsistentie

Bij iedere gepushte webbatch moeten deze versies gelijk blijven:

- `VERSION`;
- `webapp/frontend/package.json`;
- het rootpakket en de rootvermelding in `webapp/frontend/package-lock.json`.

`ApplicationVersionConsistencyTest` bewaakt dit contract. Deze repository heeft momenteel geen ingecheckte GitHub Actions-workflow; rapporteer lokale verificatie daarom afzonderlijk en claim geen CI-resultaat dat niet bestaat.

## Verwijderen

De standaardopdracht verwijdert service- en configuratie-integratie, maar bewaart applicatiedata:

```bash
cd /opt/dis
sudo bash uninstall.sh
```

| Optie | Extra effect |
|---|---|
| `--yes` | geen interactieve bevestiging |
| `--remove-app-dir` | verwijder ook `/opt/dis` |
| `--remove-database` | verwijder de lokale database |
| `--remove-db-user` | verwijder ook de databaserol; impliceert `--remove-database` |
| `--remove-system-user` | verwijder de D.I.S.-systeemidentiteit wanneer die niet meer nodig is |
| `--all` | activeer alle verwijderopties behalve package purge |
| `--purge-packages` | verwijder door setup geïnstalleerde pakketten; uitsluitend op een dedicated host |

`--all` verwijdert niet automatisch `/opt/dis-data` en voert geen package purge uit. Archiveer of verwijder blijvende data alleen via een afzonderlijk, goedgekeurd beheerproces.

## Beveiligingsmeldingen en licentie

Configureer `SECURITY_CONTACT` met een bewaakte `mailto:`- of HTTPS-URI die bewust openbaar gemaakt mag worden; deze waarde verschijnt letterlijk in `/.well-known/security.txt`. Deel een kwetsbaarheid niet via een openbaar issue wanneer daarbij operationele details, persoonsgegevens of credentials betrokken zijn.

Deze repository bevat geen `LICENSE`-bestand en de backendmetadata markeert het project als `proprietary`. Behandel broncode, configuratie en operationele documentatie daarom als bedrijfseigen totdat een expliciete licentie anders bepaalt.
