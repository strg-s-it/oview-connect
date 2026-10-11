# oView Connect

Connector-/Mapping-Plattform zwischen Shopify und Kassen-/ERP-Systemen (helloCash, MGM/AGILA).
Läuft unter **connect.oview.at** und ersetzt nach und nach die alte oView-App (oview.at, eigenes
Repo `strg-s-it/oView`, läuft aus – dort nichts aufräumen oder umbauen).

**Kommunikation mit dem Nutzer: immer auf Deutsch.**

## Wichtigste Regeln

- **Produktion bedient echte Shops und erstellt echte Rechnungen.** Rechnungs-, Lager- und
  helloCash-Code (`src/Sync/InvoiceService.php`, `Document/*`, `StockSyncRunner`, `PriceWriter`)
  ist der heikle Teil: vor riskanten Änderungen erklären, dann ändern.
- **Kein Auto-Deploy.** Auf den Server kommt nur, was der Nutzer per WinSCP hochlädt. Nach jeder
  Änderung die hochzuladenden Dateien auflisten; nie behaupten, etwas „ist live“, ohne Bestätigung.
  Gelöschte Dateien muss der Nutzer am Server von Hand löschen.
- **Commit + Push nach eigenem Ermessen erlaubt** (nur dieses Repo): je abgeschlossener Änderung,
  vorher `php -l` und PHPStan; nie force-pushen; nie `.env`, `.vscode/` oder Zugangsdaten committen.
  Commit-Nachrichten deutsch, mit `Co-Authored-By`-Zeile; per Datei übergeben (`git commit -F`).
- **Keine HTTP-Aufrufe gegen Job- oder Webhook-Endpunkte** zum Testen – sie lösen echte Aktionen aus.
  Live nur Status/Header von nebenwirkungsfreien URLs prüfen.
- Connect darf zur Laufzeit **nicht von der alten Welt abhängen** (eigene Crypto, eigene Mailer).
  Zugriff auf die alte DB nur über `src/Legacy/LegacyBridge.php`.

## Entwicklung

- **Server und Worker laufen mit PHP 8.3** (lokal ist 8.4 installiert) → keine 8.4-only-Syntax oder
  -Funktionen. PHPStan prüft gegen 8.3 (`phpVersion` in `phpstan.neon`), `php -l` lokal nicht.
- Lokal gibt es keinen Server und keine DB – Code wird nur statisch geprüft:
  - Syntax: `php -l <datei>`
  - Statische Analyse: `php tools/phpstan/vendor/bin/phpstan analyse --no-progress`
    (Level 5, bekannte Altlasten in `phpstan-baseline.neon`; es darf nichts **Neues** dazukommen.
    Beim Beheben einer Altlast `--generate-baseline` neu erzeugen.)
  - Einmalig pro Rechner: `composer install --working-dir=tools/phpstan`
- `vendor/` existiert nur am Server (PHPMailer). `tools/`, `phpstan*.neon`, `.phpstan-cache/` gehören
  nicht auf den Server.
- Migrationen: `migrations/NNNN_name.sql`, fortlaufend nummeriert, einspielen mit
  `php bin/install.php` (am Server, durch den Nutzer; überspringt bereits angewandte).
  Code muss eine **noch fehlende** Migration überleben (Tabellen-/Spaltenfehler abfangen, alter Weg).

## Architektur

```
bootstrap.php          Händler-Seiten (public/app/*):   Vertrauen = signierter Handoff + Session
admin_bootstrap.php    Admin-Seiten (public/admin/*):   Vertrauen = Admin-Login (AdminAuth)
webhook_bootstrap.php  Webhooks (public/webhooks/*):    Vertrauen = Shopify-HMAC über den Body
public/internal/*      Server-zu-Server von der alten Welt: Routing\InternalRequest (HMAC + ts)
bin/scheduler.php      Hintergrund-Jobs (CLI, atomarer Claim, 1 Job je Aufruf, mehrere Hosts)
```

- `src/Connector/` – `ConnectorFactory` baut aus einer `connect_connections`-Zeile einen
  `GenericHttpConnector` (einzige Implementierung von `ConnectorInterface`; REST/GraphQL/XML,
  Pagination, Rate-Limit, Detail-Nachladen). Erneuert Shopify-Tokens über `Shopify\TokenRefresher`
  – immer als `new ConnectorFactory($pdo, $crypto, new HttpClient(), TokenRefresher::fromConfig($config))`.
- **Begriffe (Connector Builder):** Connector Type (Shopify/helloCash/MGM) → Objects → Fields →
  Operations. Pro Shop eine Connection (Zugangsdaten verschlüsselt, `Security\Crypto`).
  **Mapping-Vorlage** = globales Feld-Mapping für ein Objektpaar; pro Shop nur eine **Aktivierung**
  (`connect_sync_mappings`). **Sync-Job-Vorlagen** analog (zentral definieren, pro Shop aktivieren).
- Sonderläufe hängen an `connect_sync_mappings.mapping_kind` (`invoice`, `stock_sync`, …) und
  zweigen in `SyncRunner::translateAndWrite()` ab – Webhook- und Zeitplan-Pfad laufen beide dort durch.
- Dedup: `connect_translation_log` mit **atomarem Claim** (eine Quell-Bestellung pro Aktivierung
  genau einmal; `error` darf erneut, `pending`/`success` blockieren). Lager ist absichtlich ohne Dedup.
- Cache: `ObjectCache` / `connect_object_records`; Bestellungen nur 90 Tage (`withAgeWindow`).
- Rechnungen: `Sync\Document\OrderPreparer` → internes Object „Bestellung (aufbereitet)“ →
  Mapping-Vorlage per Maske → `DocumentPipeline`; Zielsystem-Eigenheiten im „Belegprofil“.
  Kontrollen: Steuer, Betragssumme vor dem Anlegen, Nach-Kontrolle gegen `gross_total`.

## Zwei Welten und Routing

- Pro Shop schaltet `connect_shops.live_in_connect` (1 = Connect macht alles, 0 = alte Welt).
  Bewusst expliziter Schalter, nicht aus dem Verbindungsstatus abgeleitet.
- Shopify-URLs zeigen weiter auf oview.at. Die alte Welt prüft die Signatur und **reicht** Webhooks
  unverändert an `connect.oview.at/webhooks/…` weiter (`inc/connect_routing.php` im oView-Repo).
  Betriebs-Webhooks: entweder/oder. Datenschutz-/Lebenszyklus-Webhooks: beide Welten.
- Einstieg: Shopify-Admin → oview.at `index.php` → `connect_beta.php` (prüft Shopifys Query-HMAC,
  erzeugt Handoff-Link) → `bootstrap.php` prüft die Handoff-Signatur.

## Sicherheit (Stand 2026-10-11)

- Webhook gilt nur bei gültigem HMAC **und** JSON-Objekt-Body (`WebhookVerifier`). Grund: dasselbe
  App-Secret signiert auch Dinge, die Händler sehen (Admin-`hmac=`, Handoff). Datenschutz-Webhooks
  gleichen zusätzlich `shop_domain` aus dem Body mit dem Header ab.
- Handoff-Signatur nutzt einen **abgeleiteten** Schlüssel
  `hash_hmac('sha256', 'oview-connect-handoff-v1', APP_SECRET)` – gleich in `connect_beta.php`.
- Zugangsdaten aus einer fremden Connection nur per Whitelist übernehmen
  (`TargetAccessCheck::buildCredentials`).
- Ausgehende Requests: `Http\OutboundGuard` (nur https, öffentliche IPs, DNS gepinnt);
  Shopify-`base_url` muss `<shop>.myshopify.com` sein, bevor das App-Secret zum Token-Refresh geht.
- Admin-Login mit Sperre (5 Fehlversuche/Konto, 20/IP). Händler-POSTs brauchen CSRF-Token.

## Deploy und Server

- Upload nach `httpdocs/connect` → Root-Crontab kopiert jede Minute per `rsync --delete --exclude=.env`
  nach `/var/www/vhosts/oview.at/connect` (nur dort `.env` + `vendor/`) → connect.oview.at zeigt auf
  `…/connect/public`. `oview.at/connect/` ist per nginx gesperrt.
- Worker 1–3 (eigene IPs, gleicher Server) bekommen Connect per rsync, eigene `.env` mit
  `CONNECT_WORKER_NAME` und `CONNECT_OUTBOUND_IP`; Scheduler läuft dort per Root-Crontab mit PHP 8.3.
  Ändert sich die Haupt-`.env`, müssen die Worker-`.env` nachgezogen werden.
- DB: MariaDB 5.5 (alt!) – keine JSON-Funktionen, keine Window-Functions, kein `RETURNING`.

## Konventionen und Fallen

- UI-Texte und Kommentare deutsch; Kommentare im Code ohne Umlaute (ae/oe/ue) wie im Bestand.
- helloCash- und MGM-Objects/Operationen entstehen **per Connector Builder (UI)**, nicht per
  Migration. Migrationen nur für das eingebaute Shopify-Object und echte Schemaänderungen.
- Nie `?>` in `//`- oder `#`-Kommentaren – beendet den PHP-Block mitten in der Datei.
- GraphQL-Mutationen als `result:` aliasieren, sonst greift die `userErrors`-Prüfung nicht.
- Formulare mit Zugangsdaten: `autocomplete="off"` / `"new-password"` (Browser-Autofill-Falle).
- helloCash v2: Lagerbestand nur in der Einzel-Antwort (`GET /articles/{id}`), Bestand schreiben nur
  als Delta (`change-stock`), `discount_value` bei `percent` wird auf ganze Zahlen gerundet,
  `absolute` gilt für die ganze Position. Doku: https://myhellocash.com/docs/api (OpenAPI eingebettet).
- `_…_body.php`-Dateien werden eingebunden und bekommen `$app` von der aufrufenden Seite.
- „Token übernehmen“ setzt Connections auf `pending` zurück → danach „Verbindung testen“.

## Offene Themen

- **Shopify: ablaufende Offline-Tokens, Pflicht ab 1.1.2027.** Connect erneuert Tokens bereits
  (`TokenRefresher`), tauscht den alten unbefristeten Token aber nie um. Der Tausch geht serverseitig
  (`subject_token` = alter Token, `expiring=1`), ist unumkehrbar und **widerruft den Token der alten
  Welt sofort**. Mehrere Stellen lesen `header_value` ohne Refresh (ScopeCheck, LiveSwitch,
  ShopActivations, prepare.php). Entscheidung bis Anfang Dezember 2026: erst nach Umzug aller Shops
  oder gemeinsame Token-Quelle beider Welten. Erneuern braucht eine Sperre pro Shop.
- Verschlüsselung: getrennter Schlüssel für Link-Signaturen (`MessageService`) statt
  `CONNECT_ENCRYPTION_KEY`, AES-GCM statt CBC (braucht Neu-Verschlüsselung aller Credentials).
- Typ-Kommentare teils ungenau (z. B. Rückgabe von `ProductComparisonService::compare()` ohne
  `buffer`/`flags`/`problems`) – steht in der PHPStan-Baseline, nach und nach korrigieren.
- Viele Funktionen sind laut `HANDOFF.md` „nicht live getestet“.

## Weitere Doku

- `HANDOFF.md` – **Arbeitstagebuch** (chronologisch, mit Tests, Rechnungsnummern, Kundenfällen).
  Für Hintergründe einzelner Features; nicht als aktueller Stand lesen.
