<?php

declare(strict_types=1);

namespace Connect\Sync;

use Connect\Connector\ConnectorInterface;
use Connect\Connector\FieldDefinition;
use Connect\Connector\ObjectDefinition;
use PDO;

/**
 * Generische Zwischenspeicherung ("Inventarisierung") von read()-Ergebnissen
 * in connect_object_records -- damit nicht jede Ansicht/jeder Zugriff live
 * bei der externen API landen muss (siehe Diskussion: Shopify-Rate-Limits,
 * Geschwindigkeit). Grundlage fuer den spaeteren automatischen Sync (Phase 4,
 * SyncRunner) -- der ruft dieselbe refresh()-Methode nur zeitgesteuert statt
 * per Knopfdruck auf.
 *
 * Bewusst EIN generischer Cache statt einer Tabelle pro Connector Type/
 * Object (wie in der alten Welt shopify_products, hellocash_products, ...).
 */
final class ObjectCache
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Fuehrt read() aus und speichert jede Zeile (upsert nach externer ID).
     * Gibt die Anzahl der verarbeiteten Datensaetze zurueck.
     *
     * $maxRecords begrenzt bewusst optional die Menge: die manuelle
     * "Daten ansehen"-Vorschau soll schnell bleiben und nicht versehentlich
     * einen kompletten, u.U. sehr grossen Katalog synchron im Browser-
     * Request laden -- das ist Aufgabe des Hintergrund-Schedulers (naechster
     * Baustein), der ohne Limit (null) arbeitet.
     *
     * Verwaiste Datensaetze (in der Quelle nicht mehr vorhanden, z.B.
     * geloescht/archiviert, oder Karteileichen durch eine geaenderte
     * "Eindeutige Kennung") werden NUR bei einem vollstaendigen Durchlauf
     * erkannt (maxRecords = null) -- bei einer begrenzten Vorschau waeren
     * sonst faelschlich alle nicht abgerufenen, aber weiterhin existierenden
     * Datensaetze betroffen.
     *
     * $markOrphaned zusaetzlich abschaltbar (Default: an) -- fuer einen
     * bewusst EINGESCHRAENKTEN, aber trotzdem vollstaendigen Durchlauf
     * (z.B. webhooks/product_update.php: nur die Varianten EINES Shopify-
     * Produkts via $params, nicht der ganze Katalog). markOrphaned() kennt
     * nur connection_id+object_key, nicht "welche Datensaetze waren Teil
     * DIESES Laufs" -- ohne Abschaltung wuerde ein produktbezogener Refresh
     * faelschlich den gesamten UEBRIGEN Katalog-Cache als verwaist markieren.
     *
     * @param array<string, mixed> $params
     * @return array{processed: int, orphaned: int} orphaned ist immer 0, wenn
     *         $maxRecords gesetzt oder $markOrphaned=false ist (siehe oben --
     *         Verwaisten-Erkennung laeuft dann gar nicht erst).
     */
    public function refresh(ConnectorInterface $connector, int $connectionId, ObjectDefinition $object, array $params = [], ?int $maxRecords = null, bool $markOrphaned = true): array
    {
        $identifierField = self::requireIdentifierField($object);

        // Referenzzeitpunkt VOR dem Abruf: alles, was danach noch den
        // Zeitstempel von davor traegt, wurde in diesem Durchlauf nicht
        // gesehen (siehe markOrphaned()). BEWUSST aus der Datenbank
        // abgefragt (nicht PHP date()) -- fetched_at wird per SQL
        // CURRENT_TIMESTAMP gesetzt (MariaDB-eigene Uhr/Zeitzone). Weicht die
        // PHP-Zeitzone (date.timezone in php.ini) davon ab, wirkt der
        // Vergleich "fetched_at < :sync_started_at" in markOrphaned()
        // systematisch falsch -- je nach Richtung des Versatzes wird NIE
        // etwas verwaist markiert (live beobachtet: 0 trotz tatsaechlich in
        // Shopify geloeschter Produkte) oder faelschlich ALLES sofort.
        // Beide Seiten des Vergleichs aus derselben Uhr zu nehmen macht das
        // unabhaengig von jeder Zeitzonen-Konfiguration korrekt.
        $syncStartedAt = (string) $this->pdo->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();

        // Zeitfenster (Datenminimierung, siehe Migration 0051): z.B. nur Bestellungen der
        // letzten 90 Tage holen statt aller seit Beginn.
        $params = $this->withAgeWindow($connectionId, $object->key, $params);
        // Primaerer Standort des Shops (Migration 0061): siehe withLocationFilter().
        $params = $this->withLocationFilter($connectionId, $params);
        $fetchedLocation = isset($params['locationId']) ? (string) $params['locationId'] : null;
        // Shop-Felder (Migration 0074): Metafield-Plaetze der Abfrage je Shop belegen.
        $shopFields = ShopFields::forConnection($this->pdo, $connectionId, $object->key);
        $params = ShopFields::paramsForConnection($this->pdo, $connectionId, $object->key, $params);

        // Objects mit Detail-Nachladen (z.B. helloCash-Artikel: Bestand nur per Einzelabruf): die
        // Liste liefert diese Felder leer. Ohne Abgleich ueberschriebe jeder Listen-Lauf die zuvor
        // nachgeladenen Werte -- dann bliebe nur der jeweils letzte Stapel befuellt und der Katalog
        // wuerde nie vollstaendig. Deshalb: was die Liste leer liefert, behaelt den letzten Wert.
        // Gleiches bei einer Zusatzliste (z.B. helloCash-Bestand aus v1): sie laeuft erst NACH diesem
        // Abruf -- ohne das stuende der Bestand dazwischen leer (ein paralleler Lagerabgleich saehe
        // "Bestand unbekannt") und jede Zeile zaehlte als "aktualisiert".
        $keepEmpty = $object->hydrateDetailsAfterList
            || ($connector instanceof \Connect\Connector\Generic\GenericHttpConnector && $connector->supplementConfig($object->key) !== null);
        $previous = $keepEmpty ? $this->cachedDataByExternalId($connectionId, $object->key) : [];

        $count = 0;
        foreach ($connector->read($object->key, $params) as $row) {
            $row = ShopFields::applyValues($row, $shopFields);
            if ($fetchedLocation !== null) {
                $row['_location_id'] = $fetchedLocation; // siehe ProductComparisonService (Standort-Filter)
            }
            if ($previous !== []) {
                $old = $previous[(string) ($row[$identifierField->key] ?? '')] ?? null;
                if ($old !== null) {
                    foreach ($old as $key => $value) {
                        if ($value !== null && (!array_key_exists($key, $row) || $row[$key] === null)) {
                            $row[$key] = $value;
                        }
                    }
                }
            }
            if (!$this->upsertRecord($connectionId, $object->key, $identifierField->key, $row)) {
                continue;
            }
            $count++;

            if ($maxRecords !== null && $count >= $maxRecords) {
                break;
            }
        }

        $orphaned = 0;
        if ($maxRecords === null && $markOrphaned) {
            $orphaned = $this->markOrphaned($connectionId, $object->key, $syncStartedAt);
        }

        return ['processed' => $count, 'orphaned' => $orphaned];
    }

    /**
     * Gecachte Daten aller Datensaetze eines Objects, je external_id (fuer refresh(), siehe dort).
     *
     * @return array<string, array<string, mixed>>
     */
    private function cachedDataByExternalId(int $connectionId, string $objectKey): array
    {
        $stmt = $this->pdo->prepare('SELECT external_id, data FROM connect_object_records WHERE connection_id = :connection_id AND object_key = :object_key');
        $stmt->execute(['connection_id' => $connectionId, 'object_key' => $objectKey]);
        $out = [];
        while (($record = $stmt->fetch()) !== false) {
            $data = json_decode((string) $record['data'], true);
            if (is_array($data)) {
                $out[(string) $record['external_id']] = $data;
            }
        }
        $stmt->closeCursor();

        return $out;
    }

    /**
     * Ergaenzt den Listen-Abruf um das konfigurierte Zeitfenster des Objects
     * (connect_objects.max_age_days / age_filter_param / age_filter_format,
     * Migration 0051). Ein vom Aufrufer bereits gesetzter Parameter gewinnt
     * (z.B. der gezielte Einzelabruf per Webhook). Ohne Konfiguration -- oder
     * solange die Migration noch nicht eingespielt ist -- bleiben die Parameter
     * unveraendert, der Abruf verhaelt sich dann wie bisher.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function withAgeWindow(int $connectionId, string $objectKey, array $params): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT o.max_age_days, o.age_filter_param, o.age_filter_format
                 FROM connect_objects o
                 JOIN connect_connections c ON c.connector_type_id = o.connector_type_id
                 WHERE c.id = :connection_id AND o.object_key = :object_key
                 LIMIT 1'
            );
            $stmt->execute(['connection_id' => $connectionId, 'object_key' => $objectKey]);
            $config = $stmt->fetch();
        } catch (\Throwable $e) {
            return $params; // Spalten fehlen (Migration 0051 noch nicht eingespielt)
        }

        if ($config === false
            || $config['max_age_days'] === null
            || $config['age_filter_param'] === null
            || $config['age_filter_format'] === null
        ) {
            return $params;
        }

        $param = (string) $config['age_filter_param'];
        $days = (int) $config['max_age_days'];
        if ($days < 1 || array_key_exists($param, $params)) {
            return $params;
        }

        $since = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-' . $days . ' days')
            ->format('Y-m-d');
        $params[$param] = str_replace('{date}', $since, (string) $config['age_filter_format']);

        return $params;
    }

    /**
     * Ergaenzt den Lese-Aufruf um den primaeren Standort des Shops dieser
     * Connection (connect_shops.stock_primary_location_id), falls gesetzt --
     * generisch fuer JEDE Connection/JEDES Object, nicht auf Shopify-Produkte
     * beschraenkt: harmlos fuer Operationen, deren Query "locationId"/
     * "hasLocation" gar nicht deklariert (siehe GenericHttpConnector::
     * buildReadBody() -- ungenutzte GraphQL-Variablen sind kein Fehler, ein
     * REST-path_template ignoriert unbenutzte {{platzhalter}} ebenso).
     *
     * Zentral HIER statt in jedem Aufrufer einzeln, damit sowohl der
     * planmaessige Katalog-Refresh (Connect\Sync\SyncRunner::run()) als auch
     * der Webhook-getriggerte Einzelabruf (webhooks/product_update.php, der
     * ebenfalls refresh() nutzt) automatisch profitieren.
     *
     * Konkret genutzt von Shopifys Produkt-Object (Migration 0061,
     * "location_stock_quantity" statt der Alle-Standorte-Summe
     * "inventoryQuantity") -- Nutzer-Entscheidung (2026-09-27): "immer nur
     * Produkte vom gewaehlten Standort ... verwenden". Ein vom Aufrufer
     * bereits gesetzter "locationId"-Parameter gewinnt (z.B. ein kuenftiger
     * gezielter Abruf fuer einen ANDEREN als den primaeren Standort).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function withLocationFilter(int $connectionId, array $params): array
    {
        if (array_key_exists('locationId', $params)) {
            return $params;
        }

        try {
            $stmt = $this->pdo->prepare(
                'SELECT s.stock_primary_location_id FROM connect_connections c
                 JOIN connect_shops s ON s.shop = c.shop
                 WHERE c.id = :connection_id'
            );
            $stmt->execute(['connection_id' => $connectionId]);
            $locationId = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return $params; // z.B. Spalte/Tabelle (noch) nicht lesbar -- Abruf verhaelt sich wie bisher
        }

        if ($locationId === false || $locationId === null || $locationId === '') {
            return $params;
        }

        $params['locationId'] = (string) $locationId;
        $params['hasLocation'] = true;

        return $params;
    }

    /**
     * Aktualisiert GENAU EINEN Datensatz gezielt (z.B. bei einem Webhook-
     * getriggerten Einzel-Update statt eines kompletten Listen-Refreshs).
     * Nutzt ConnectorInterface::readOne() statt read() -- kein
     * Verwaisten-Abgleich (der ergibt bei einem Einzel-Datensatz keinen
     * Sinn, siehe refresh()).
     *
     * @param array<string, mixed> $params
     * @return bool true, wenn ein Datensatz gefunden und gespeichert wurde
     */
    public function refreshOne(ConnectorInterface $connector, int $connectionId, ObjectDefinition $object, array $params): bool
    {
        $identifierField = self::requireIdentifierField($object);

        $shopFields = ShopFields::forConnection($this->pdo, $connectionId, $object->key);
        // Standort wie bei refresh() -- sonst fehlt beim Einzelabruf ein standortbezogener
        // Lagerbestand (location_stock_quantity, Migration 0061) und gilt als "nicht gefuehrt"
        $params = $this->withLocationFilter($connectionId, $params);
        $fetchedLocation = isset($params['locationId']) ? (string) $params['locationId'] : null;
        $row = $connector->readOne($object->key, ShopFields::paramsForConnection($this->pdo, $connectionId, $object->key, $params));
        if ($row === null) {
            return false;
        }
        $row = ShopFields::applyValues($row, $shopFields);
        if ($fetchedLocation !== null) {
            $row['_location_id'] = $fetchedLocation; // siehe ProductComparisonService (Standort-Filter)
        }

        return $this->upsertRecord($connectionId, $object->key, $identifierField->key, $row);
    }

    /**
     * Laedt fuer eine BEGRENZTE Stapelmenge bereits gecachter Datensaetze
     * per readOne() Detail-Felder nach, die read_list nicht liefert (z.B.
     * HelloCash "stocks" -- der Lagerbestand steht nur in der Einzel-Antwort
     * "GET /articles/{id}", nicht in der Liste, und HelloCash hat weder ein
     * Bulk- noch ein Webhook-Verfahren dafuer). Fuer Kataloge mit tausenden
     * Datensaetzen ist EIN read_one-Aufruf pro Datensatz und Lauf zu teuer/
     * zu langsam -- deshalb pro Aufruf nur $batchSize Datensaetze, mit
     * $cursorState (JSON, siehe connect_sync_jobs.cursor_state) als
     * Fortschrittsmarke: der naechste zeitgesteuerte Lauf setzt genau dort
     * fort, wo dieser aufgehoert hat, bis der komplette Katalog einmal
     * durch ist -- danach beginnt der naechste Durchlauf wieder von vorne
     * (rollierende Aktualisierung, kein einmaliger Abschluss).
     *
     * @return array{hydrated: int, cursorState: ?string} cursorState ist
     *         null, wenn dieser Batch das Ende des Katalogs erreicht hat
     *         (naechster Lauf faengt wieder bei vorne an).
     */
    public function hydrateDetails(ConnectorInterface $connector, int $connectionId, ObjectDefinition $object, ?string $cursorState, int $batchSize): array
    {
        $identifierField = self::requireIdentifierField($object);

        $decodedCursor = $cursorState !== null ? json_decode($cursorState, true) : null;
        $afterId = is_array($decodedCursor) ? (int) ($decodedCursor['after_id'] ?? 0) : 0;

        $stmt = $this->pdo->prepare(
            "SELECT id, external_id FROM connect_object_records
             WHERE connection_id = :connection_id AND object_key = :object_key AND status = 'active' AND id > :after_id
             ORDER BY id ASC LIMIT " . $batchSize
        );
        $stmt->execute(['connection_id' => $connectionId, 'object_key' => $object->key, 'after_id' => $afterId]);
        $batch = $stmt->fetchAll();

        $hydrated = 0;
        $lastId = $afterId;
        foreach ($batch as $record) {
            $lastId = (int) $record['id'];

            try {
                if ($this->refreshOne($connector, $connectionId, $object, [$identifierField->key => $record['external_id']])) {
                    $hydrated++;
                }
            } catch (\Throwable $e) {
                // Ein fehlgeschlagener Einzel-Abruf (z.B. voruebergehender
                // Netzwerkfehler) soll nicht den ganzen Stapel abbrechen --
                // der Datensatz wird beim naechsten vollen Durchlauf erneut
                // versucht.
                continue;
            }
        }

        $reachedEnd = count($batch) < $batchSize;

        return [
            'hydrated' => $hydrated,
            'cursorState' => $reachedEnd ? null : json_encode(['after_id' => $lastId]),
        ];
    }

    /**
     * Traegt einen Datensatz direkt mit BEKANNTEN Werten in den Cache ein --
     * OHNE Live-Abruf, kann also (anders als refreshOne()) nicht an einem
     * Netzwerk-/API-Fehler scheitern. Fuer Connect\Sync\ProductImportService:
     * unmittelbar nach einem erfolgreichen Schreibzugriff (neues Shopify-
     * Produkt/neue Variante) sind Kennung + die gerade geschriebenen Felder
     * bereits sicher bekannt -- ein sofortiges "Nachziehen" dieser Werte in
     * den Cache darf nicht von einem zusaetzlichen, potenziell scheiternden
     * Folge-Request abhaengen, sonst zeigt die "Produkte"-Seite den frisch
     * importierten Datensatz faelschlich weiter als "fehlt" (bis zum
     * naechsten regulaeren Sync). Ergaenzend dazu bleibt ein anschliessender
     * refreshOne()-Versuch sinnvoll (holt weitere, hier nicht bekannte Felder
     * wie Status/Lagerbestand nach) -- diese Methode liefert nur die
     * garantierte Mindest-Grundlage.
     *
     * @param array<string, mixed> $row Feldwerte, wie sie auch aus
     *        ConnectorInterface::read()/readOne() kaemen (field_key => Wert)
     *        -- muss mindestens das Identifier-Feld enthalten.
     */
    public function seedRecord(int $connectionId, string $objectKey, ObjectDefinition $object, array $row): bool
    {
        $identifierField = self::requireIdentifierField($object);

        return $this->upsertRecord($connectionId, $objectKey, $identifierField->key, $row);
    }

    /**
     * Public+static, damit SyncRunner::translateAndWrite() dieselbe Logik
     * nutzen kann (dort wird die externe Quell-ID fuer connect_translation_log
     * gebraucht) -- eine zentrale Stelle statt einer zweiten Kopie dieser
     * Suche.
     */
    public static function requireIdentifierField(ObjectDefinition $object): FieldDefinition
    {
        foreach ($object->fields as $field) {
            if ($field->isIdentifier) {
                return $field;
            }
        }

        throw new \RuntimeException("Object \"{$object->key}\" hat kein als Identifier markiertes Feld -- Cache nicht moeglich.");
    }

    /**
     * @param array<string, mixed> $row
     * @return bool true, wenn gespeichert wurde (false nur bei fehlender/leerer externer ID)
     */
    private function upsertRecord(int $connectionId, string $objectKey, string $identifierFieldKey, array $row): bool
    {
        $externalId = (string) ($row[$identifierFieldKey] ?? '');
        if ($externalId === '') {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO connect_object_records (connection_id, object_key, external_id, status, data, fetched_at)
             VALUES (:connection_id, :object_key, :external_id, \'active\', :data, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE data = :data2, status = \'active\', fetched_at = CURRENT_TIMESTAMP'
        );

        $json = json_encode($row, JSON_THROW_ON_ERROR);
        $stmt->execute([
            'connection_id' => $connectionId,
            'object_key' => $objectKey,
            'external_id' => $externalId,
            'data' => $json,
            'data2' => $json,
        ]);

        return true;
    }

    /**
     * Markiert Cache-Zeilen als "orphaned", die vor $syncStartedAt zuletzt
     * aktualisiert wurden -- sie wurden im gerade abgeschlossenen
     * vollstaendigen Durchlauf nicht mehr gesehen. Wird bewusst NUR nach
     * einem erfolgreich abgeschlossenen refresh() aufgerufen (nicht bei
     * einem Abbruch durch einen Fehler mitten im Abruf) -- sonst wuerden
     * bei einem z.B. durch einen Netzwerkfehler abgebrochenen Sync
     * faelschlich noch vorhandene Datensaetze als verwaist markiert.
     *
     * @return int Anzahl der dabei als verwaist markierten Zeilen -- fuer
     *         Connect\Sync\SyncRunner, um es im Lauf-Log sichtbar zu machen
     *         (sonst nicht nachvollziehbar, OB/WIE VIELE Zeilen ein Lauf
     *         ueberhaupt verwaist hat).
     */
    private function markOrphaned(int $connectionId, string $objectKey, string $syncStartedAt): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE connect_object_records
             SET status = 'orphaned'
             WHERE connection_id = :connection_id AND object_key = :object_key
               AND status = 'active' AND fetched_at < :sync_started_at"
        );
        $stmt->execute([
            'connection_id' => $connectionId,
            'object_key' => $objectKey,
            'sync_started_at' => $syncStartedAt,
        ]);

        return $stmt->rowCount();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCached(int $connectionId, string $objectKey): array
    {
        // ORDER BY id DESC statt aufsteigend: die Zeilen-ID spiegelt wider,
        // wann ein Datensatz ERSTMALS gecacht wurde (bleibt bei einem
        // spaeteren Update via ON DUPLICATE KEY unveraendert) -- bei einer
        // vollstaendigen Erstsynchronisierung liefert Shopify Bestellungen
        // standardmaessig aeltestes zuerst, wodurch aufsteigende Sortierung
        // hier genau die aeltesten Datensaetze zuoberst zeigt. DESC zeigt
        // stattdessen die zuletzt neu hinzugekommenen (i.d.R. juengsten)
        // Datensaetze zuerst -- kein echtes Sortieren nach dem eigentlichen
        // Datum aus den Rohdaten moeglich, da MariaDB 5.x kein JSON_EXTRACT
        // kennt (siehe Architektur-Einschraenkung), aber eine deutlich
        // bessere Annaeherung als die bisherige Einfuege-Reihenfolge.
        $stmt = $this->pdo->prepare(
            'SELECT data, status, fetched_at FROM connect_object_records
             WHERE connection_id = :connection_id AND object_key = :object_key
             ORDER BY id DESC'
        );
        $stmt->execute(['connection_id' => $connectionId, 'object_key' => $objectKey]);

        $rows = [];
        // Zeilenweise statt fetchAll(): sonst liegen Rohtext und dekodierte Daten
        // gleichzeitig im Speicher (bei grossen Katalogen > memory_limit).
        while (($record = $stmt->fetch()) !== false) {
            $decoded = json_decode((string) $record['data'], true);
            $rows[] = is_array($decoded) ? $decoded + [
                '_fetched_at' => $record['fetched_at'],
                '_status' => $record['status'],
            ] : [];
        }
        $stmt->closeCursor();

        return $rows;
    }

    /**
     * EIN gecachter Datensatz per externer ID (null = nicht im Cache).
     *
     * @return array<string, mixed>|null
     */
    public function getCachedOne(int $connectionId, string $objectKey, string $externalId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT data, status, fetched_at FROM connect_object_records
             WHERE connection_id = :connection_id AND object_key = :object_key AND external_id = :external_id'
        );
        $stmt->execute(['connection_id' => $connectionId, 'object_key' => $objectKey, 'external_id' => $externalId]);
        $record = $stmt->fetch();
        if ($record === false) {
            return null;
        }
        $decoded = json_decode((string) $record['data'], true);

        return is_array($decoded) ? $decoded + ['_fetched_at' => $record['fetched_at'], '_status' => $record['status']] : null;
    }

    /**
     * Sucht EINEN gecachten Datensatz anhand eines Feldwerts (z.B. der
     * shop-weite Vergleichswert aus connect_shops.comparison_key) --
     * fuer Connect\Sync\ProductImportService, das fuer einen konkreten
     * Vergleichswert (aus der "Produkte"-Vergleichsuebersicht) den
     * vollstaendigen Quell-Datensatz zum Uebersetzen braucht. Laedt wie
     * getCached() alle Zeilen und filtert in PHP (kein JSON_EXTRACT auf
     * MariaDB 5.x, siehe getCached()-Kommentar) -- fuer einen Einzel-Lookup
     * pro Klick vertretbar.
     *
     * @return array<string, mixed>|null
     */
    public function findOneByFieldValue(int $connectionId, string $objectKey, string $fieldKey, string $value): ?array
    {
        foreach ($this->getCached($connectionId, $objectKey) as $record) {
            if (($record['_status'] ?? 'active') === 'orphaned') {
                continue;
            }
            if (array_key_exists($fieldKey, $record) && (string) $record[$fieldKey] === $value) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Aktualisiert EINZELNE Felder eines bereits gecachten Datensatzes, OHNE
     * einen neuen Live-Abruf zu machen -- fuer Faelle, in denen der Aufrufer
     * den neuen Wert bereits sicher kennt (z.B. Connect\Sync\StockSyncRunner
     * nach einem erfolgreichen Schreibzugriff: der Lagerbestand im Zielsystem
     * IST jetzt exakt der geschriebene Wert, ein sofortiges Neu-Lesen waere
     * nur ein unnoetiger zusaetzlicher API-Aufruf). Ohne das wuerde der
     * naechste Lagerbestand-Abgleich mit dem alten, gecachten Wert erneut ein
     * Delta berechnen und den bereits korrekten Zielbestand ein zweites Mal
     * veraendern (siehe Diskussion: 100 -> 199 -> faelschlich weiter -> -1).
     * Kein Effekt, wenn der Datensatz (noch) nicht gecacht ist.
     *
     * @param array<string, mixed> $fields field_key => neuer Wert, werden in
     *        die bestehenden gecachten Daten gemischt (andere Felder bleiben
     *        unveraendert).
     */
    public function updateCachedFields(int $connectionId, string $objectKey, string $externalId, array $fields): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT data FROM connect_object_records
             WHERE connection_id = :connection_id AND object_key = :object_key AND external_id = :external_id'
        );
        $stmt->execute(['connection_id' => $connectionId, 'object_key' => $objectKey, 'external_id' => $externalId]);
        $record = $stmt->fetch();

        if ($record === false) {
            return;
        }

        $data = json_decode((string) $record['data'], true);
        if (!is_array($data)) {
            return;
        }

        $update = $this->pdo->prepare(
            'UPDATE connect_object_records SET data = :data
             WHERE connection_id = :connection_id AND object_key = :object_key AND external_id = :external_id'
        );
        $update->execute([
            'data' => json_encode(array_merge($data, $fields), JSON_THROW_ON_ERROR),
            'connection_id' => $connectionId,
            'object_key' => $objectKey,
            'external_id' => $externalId,
        ]);
    }

    /**
     * Zusatzliste (GenericHttpConnector::readSupplement(), Operationstyp "read_list_supplement")
     * in die bereits gecachten Datensaetze eintragen: je Zeile die Kennung (Pfad "match") mit
     * external_id vergleichen und die konfigurierten Felder setzen. Andere Felder bleiben.
     * Muss NACH refresh() im selben Lauf laufen -- refresh() ueberschreibt die Datensaetze komplett
     * (die v2-Liste von helloCash hat z.B. keinen Bestand).
     *
     * @return array{rows: int, matched: int, updated: int}
     */
    public function mergeSupplement(\Connect\Connector\Generic\GenericHttpConnector $connector, int $connectionId, ObjectDefinition $object): array
    {
        $config = $connector->supplementConfig($object->key);
        if ($config === null) {
            return ['rows' => 0, 'matched' => 0, 'updated' => 0];
        }

        // Alle Zeilen der Zusatzliste einsammeln: Kennung => Feldwerte
        $values = [];
        $rows = 0;
        foreach ($connector->readSupplement($object->key) as $row) {
            $rows++;
            if (!is_array($row)) {
                continue;
            }
            $key = $connector::valueAtPath($row, $config['match']);
            if ($key === null || $key === '' || is_array($key)) {
                continue;
            }
            $fields = [];
            foreach ($config['fields'] as $fieldKey => $path) {
                $value = $connector::valueAtPath($row, $path);
                if (is_string($value) && is_numeric($value)) {
                    $value = $value + 0;
                }
                $fields[$fieldKey] = $value;
            }
            $values[(string) $key] = $fields;
        }

        $select = $this->pdo->prepare(
            "SELECT id, external_id, data FROM connect_object_records WHERE connection_id = :connection_id AND object_key = :object_key AND status = 'active'"
        );
        $select->execute(['connection_id' => $connectionId, 'object_key' => $object->key]);
        $update = $this->pdo->prepare('UPDATE connect_object_records SET data = :data WHERE id = :id');

        $matched = 0;
        $updated = 0;
        $this->pdo->beginTransaction();
        try {
            foreach ($select->fetchAll() as $record) {
                $fields = $values[(string) $record['external_id']] ?? null;
                if ($fields === null) {
                    continue;
                }
                $matched++;
                $data = json_decode((string) $record['data'], true);
                if (!is_array($data)) {
                    continue;
                }
                $merged = array_merge($data, $fields);
                if ($merged !== $data) {
                    $update->execute(['data' => json_encode($merged, JSON_THROW_ON_ERROR), 'id' => (int) $record['id']]);
                    $updated++;
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return ['rows' => $rows, 'matched' => $matched, 'updated' => $updated];
    }

    /**
     * Zeitpunkt der letzten Aktualisierung fuer ein Object (fuer die
     * Anzeige "zuletzt aktualisiert: ..."), oder null falls noch nie
     * synchronisiert.
     */
    public function lastRefreshedAt(int $connectionId, string $objectKey): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT MAX(fetched_at) FROM connect_object_records WHERE connection_id = :connection_id AND object_key = :object_key'
        );
        $stmt->execute(['connection_id' => $connectionId, 'object_key' => $objectKey]);
        $value = $stmt->fetchColumn();

        return is_string($value) ? $value : null;
    }
}
