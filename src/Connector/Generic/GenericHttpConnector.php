<?php

declare(strict_types=1);

namespace Connect\Connector\Generic;

use Connect\Connector\ConnectorInterface;
use Connect\Connector\FieldDefinition;
use Connect\Connector\ObjectDefinition;
use Connect\Connector\OperationDefinition;
use Connect\Connector\TestResult;
use Connect\Connector\WriteResult;
use Connect\Http\HttpClient;
use Connect\Http\RateLimitConfig;
use Connect\Http\RateLimiter;

/**
 * Die EINZIGE Implementierung von ConnectorInterface. Gilt fuer Shopify,
 * HelloCash, MGM/AGILA und jeden kuenftigen, per No-Code-Builder definierten
 * Connector Type gleichermassen -- der Unterschied zwischen ihnen steckt
 * ausschliesslich in den Daten (Objects/Operations/Felder aus der DB), nie
 * im Code (siehe Plan, Abschnitt "Architektur-Konzepte").
 *
 * JSON-Anfragen/-Antworten werden direkt unterstuetzt. XML (fuer das
 * MGM-typische "dataset"/FieldDefs-Schema und XML-Schreib-Templates) wird an
 * XmlDatasetParser bzw. XmlTemplateRenderer delegiert.
 */
final class GenericHttpConnector implements ConnectorInterface
{
    /** @var array<string, ObjectDefinition> objectKey => Definition */
    private array $objectsByKey;

    /** @var array<string, OperationDefinition> "objectKey:operationType" => Definition */
    private array $operationsByObjectAndType;

    /**
     * @param array<string, mixed> $credentials Bereits entschluesselt, Form abhaengig von $authType:
     *                                            bearer: ['token' => '...']
     *                                            basic: ['username' => '...', 'password' => '...']
     *                                            api_key: ['header_name' => '...', 'header_value' => '...']
     *                                            custom_headers: ['headers' => ['X-Foo' => 'bar', ...]]
     * @param ObjectDefinition[] $objects
     * @param OperationDefinition[] $operations
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $authType,
        private readonly array $credentials,
        private readonly string $requestFormat,
        array $objects,
        array $operations,
        private readonly HttpClient $httpClient,
        private readonly ?RateLimiter $rateLimiter = null,
        private readonly ?RateLimitConfig $rateLimitConfig = null,
        private readonly ?\Connect\Http\ApiUsageRecorder $usageRecorder = null,
        private readonly ?int $connectionId = null
    ) {
        $this->objectsByKey = [];
        foreach ($objects as $object) {
            $this->objectsByKey[$object->key] = $object;
        }

        $this->operationsByObjectAndType = [];
        foreach ($operations as $operation) {
            $this->operationsByObjectAndType[$operation->objectKey . ':' . $operation->operationType] = $operation;
        }
    }

    public function testConnection(): TestResult
    {
        // Bewusst einfach gehalten: der erste verfuegbare read_list-Aufruf
        // gilt als Verbindungstest. Ein eigener "test"-Operationstyp waere
        // Overengineering, solange jeder Connector Type ohnehin mindestens
        // ein lesbares Object hat.
        // Das optionale Object "account_info" (Firmendaten, Connect\Setup\ConnectionAccount) erst
        // zuletzt: es braucht oft eine Zusatzberechtigung (helloCash: company:read), die aeltere
        // Tokens nicht haben -- sonst gilt eine funktionierende Verbindung als fehlgeschlagen.
        $objectKeys = array_keys($this->objectsByKey);
        usort($objectKeys, static fn (string $a, string $b): int => ($a === 'account_info') <=> ($b === 'account_info'));

        foreach ($objectKeys as $objectKey) {
            $operation = $this->operationsByObjectAndType[$objectKey . ':read_list'] ?? null;
            if ($operation === null) {
                continue;
            }

            try {
                // offset=0 wie in read() -- siehe dortiger Kommentar, damit ein
                // REST-path_template mit {{offset}} auch beim Verbindungstest
                // einen gueltigen Wert bekommt.
                $body = $this->buildReadBody($operation, ['offset' => 0]);
                $response = $this->executeRequest($operation, ['offset' => 0], $body);
            } catch (\Throwable $e) {
                return TestResult::failure('Verbindung fehlgeschlagen: ' . $e->getMessage());
            }

            if ($response->isSuccess()) {
                return TestResult::success("Verbindung erfolgreich (getestet ueber Object \"{$objectKey}\").");
            }

            // Response-Body mit ausgeben (nicht nur den Status) -- bei
            // Shopify steht dort z.B. die konkrete GraphQL-Fehlermeldung,
            // ohne die ein 400/422 kaum diagnostizierbar ist.
            $bodyExcerpt = mb_substr($response->body, 0, 500);
            return TestResult::failure("Verbindung fehlgeschlagen: HTTP {$response->statusCode} - {$bodyExcerpt}");
        }

        return TestResult::failure('Kein lesbares Object mit read_list-Operation definiert, Verbindungstest nicht moeglich.');
    }

    /**
     * Lesezugriff auf JEDES Object einzeln pruefen (je eine Seite read_list, nur lesend) --
     * anders als testConnection(), das beim ersten lesbaren Object aufhoert. Zeigt, wofuer
     * einem Token Berechtigungen fehlen (helloCash v2: "Insufficient scope", HTTP 403).
     *
     * $stopAtFirstFailure: Schnellpruefung -- beim ersten nicht lesbaren Object (ausser denen in
     * $optionalKeys) aufhoeren. Reicht fuer "vollstaendig ja/nein" und spart bei alten Tokens
     * die uebrigen Aufrufe (Rate-Limit).
     *
     * @param list<string> $optionalKeys
     * @return array<string, array{label: string, ok: bool, status: ?int, error: ?string}>
     */
    public function checkReadAccess(bool $stopAtFirstFailure = false, array $optionalKeys = []): array
    {
        $result = [];
        foreach ($this->objectsByKey as $objectKey => $object) {
            $operation = $this->operationsByObjectAndType[$objectKey . ':read_list'] ?? null;
            if ($operation === null) {
                continue;
            }
            try {
                $body = $this->buildReadBody($operation, ['offset' => 0]);
                $response = $this->executeRequest($operation, ['offset' => 0], $body);
                $result[$objectKey] = [
                    'label' => $object->label,
                    'ok' => $response->isSuccess(),
                    'status' => $response->statusCode,
                    'error' => $response->isSuccess() ? null : mb_substr($response->body, 0, 300),
                ];
            } catch (\Throwable $e) {
                $result[$objectKey] = ['label' => $object->label, 'ok' => false, 'status' => null, 'error' => $e->getMessage()];
            }
            if ($stopAtFirstFailure && !$result[$objectKey]['ok'] && !in_array($objectKey, $optionalKeys, true)) {
                break;
            }
        }

        return $result;
    }

    /**
     * @return ObjectDefinition[]
     */
    public function getObjects(): array
    {
        return array_values($this->objectsByKey);
    }

    /**
     * Sicherheitsgrenze gegen Endlosschleifen bei fehlerhaftem pageInfo
     * (nicht gegen echte, grosse Kataloge -- 200 Seiten a 100 Datensaetze
     * sind bereits 20.000 Stueck, weit mehr als ein Verbindungstest/eine
     * manuelle Vorschau je braucht).
     */
    private const MAX_PAGES = 200;

    public function read(string $objectKey, array $params = []): iterable
    {
        yield from $this->readPages($this->requireOperation($objectKey, 'read_list'), $objectKey, $params, true);
    }

    /**
     * Zusatzliste (Operationstyp "read_list_supplement"): eine weitere Liste, deren Zeilen ROH
     * zurueckkommen und per Connect\Sync\ObjectCache::mergeSupplement() in bereits gecachte
     * Datensaetze eingetragen werden. Anlass: helloCash v2 liefert den Lagerbestand nur einzeln
     * (GET /articles/{id}), die v1-Liste dagegen fuer alle Artikel (article_stock) -- ein
     * Durchgang ueber 4600 Artikel braucht so 5 statt 4600 Aufrufe.
     * Konfiguration in static_params: "_merge" (siehe supplementConfig()), optional "_page_size"
     * (Blaettern per offset, solange eine volle Seite kommt -- fuer Listen ohne pagination-Info).
     *
     * @return iterable<array<string, mixed>>
     */
    public function readSupplement(string $objectKey): iterable
    {
        yield from $this->readPages($this->requireOperation($objectKey, 'read_list_supplement'), $objectKey, [], false);
    }

    /**
     * "_merge" der Zusatzliste: {"match": "<Pfad der Kennung in der Zeile>", "fields": {"<field_key>": "<Pfad>"}}
     * oder null, wenn das Object keine Zusatzliste hat.
     *
     * @return array{match: string, fields: array<string, string>}|null
     */
    public function supplementConfig(string $objectKey): ?array
    {
        $operation = $this->operationsByObjectAndType[$objectKey . ':read_list_supplement'] ?? null;
        $merge = $operation?->staticParams['_merge'] ?? null;
        if (!is_array($merge) || trim((string) ($merge['match'] ?? '')) === '' || !is_array($merge['fields'] ?? null) || $merge['fields'] === []) {
            return null;
        }

        return ['match' => (string) $merge['match'], 'fields' => array_map('strval', $merge['fields'])];
    }

    /** Pfad-Zugriff fuer die Zusatzliste (gleiche Schreibweise wie field_path/response_records_path). */
    public static function valueAtPath(array $row, string $path): mixed
    {
        return self::extractByPath($row, $path);
    }

    /**
     * @param array<string, mixed> $params
     * @return \Generator<array<string, mixed>>
     */
    private function readPages(OperationDefinition $operation, string $objectKey, array $params, bool $mapRows): \Generator
    {
        $object = $this->requireObject($objectKey);

        // offset=0 wird IMMER vorbelegt (auch fuer Seite 1) -- harmlos fuer
        // GraphQL-Quellen (eine ungenutzte GraphQL-Variable ist kein Fehler)
        // und fuer Systeme ohne {{offset}} im path_template (renderPathTemplate
        // ignoriert unbenutzte Platzhalter-Werte einfach), aber noetig, damit
        // ein REST-path_template wie "articles?limit=100&offset={{offset}}"
        // schon bei der ersten Seite einen gueltigen Wert bekommt statt einer
        // leeren Zeichenkette.
        // "_offset_mode":"page" (static_params): offset ist die Seitennummer ab 1 statt der Anzahl
        // uebersprungener Datensaetze ab 0 -- z.B. helloCash v1 (offset=1,2,3 bei limit=1000).
        $offsetByPage = ($operation->staticParams['_offset_mode'] ?? '') === 'page';
        $nextPageParams = ['offset' => $offsetByPage ? 1 : 0];
        $page = 0;

        do {
            $page++;
            if ($page > self::MAX_PAGES) {
                throw new \RuntimeException("Lesen von \"{$objectKey}\" abgebrochen: mehr als " . self::MAX_PAGES . ' Seiten (moeglicher Pagination-Fehler).');
            }

            $requestParams = array_merge($params, $nextPageParams);
            $body = $this->buildReadBody($operation, $requestParams);
            $response = $this->executeRequest($operation, $requestParams, $body);

            if (!$response->isSuccess()) {
                throw new \RuntimeException("Lesen von \"{$objectKey}\" fehlgeschlagen: HTTP {$response->statusCode} - {$response->body}");
            }

            $hasNextPage = false;
            $nextPageParams = [];

            if ($this->requestFormat === 'xml') {
                // MGM/XML-Dataset hat aktuell keine Pagination.
                $rows = $operation->responseRecordsPath !== null
                    ? XmlDatasetParser::parseNamedRows($response->body, $operation->responseRecordsPath)
                    : XmlDatasetParser::parseRows($response->body);
            } else {
                $decoded = json_decode($response->body, true);
                if (!is_array($decoded)) {
                    throw new \RuntimeException("Antwort von \"{$objectKey}\" ist kein gueltiges JSON.");
                }
                // GraphQL-Fehler (z.B. Shopify THROTTLED auch nach der Wiederholung, Syntax-
                // oder Rechtefehler) kommen mit HTTP 200. Ohne Datensaetze an der erwarteten
                // Stelle ist das ein Fehler, keine leere Seite -- sonst wuerde ein voller
                // Cache-Abruf alles Nicht-Gelesene als "verwaist" markieren.
                if (isset($decoded['errors']) && is_array($decoded['errors'])
                    && ($operation->responseRecordsPath === null || self::extractByPath($decoded, $operation->responseRecordsPath) === null)
                ) {
                    $messages = array_map(static fn ($e) => is_array($e) ? (string) ($e['message'] ?? ($e['extensions']['code'] ?? 'unbekannt')) : (string) $e, $decoded['errors']);
                    throw new \RuntimeException("Lesen von \"{$objectKey}\" fehlgeschlagen: " . implode('; ', $messages));
                }
                $rows = $operation->responseRecordsPath !== null
                    ? (self::extractByPath($decoded, $operation->responseRecordsPath) ?? [])
                    : $decoded;

                // Manche Objects sind kein Datensatz-Array, sondern ein
                // einzelnes Objekt (z.B. Shopify "shop": data.shop liefert
                // direkt die Shop-Felder, kein edges-Array). array_is_list()
                // erkennt den Unterschied: eine GraphQL-edges-Liste hat
                // fortlaufende numerische Keys (0,1,2,...), ein einzelnes
                // Objekt hat String-Keys (name, email, ...). In dem Fall
                // gibt es auch keine Pagination.
                if (is_array($rows) && !array_is_list($rows)) {
                    $rows = [$rows];
                } elseif ($operation->responseRecordsPath !== null) {
                    // Die Pagination-Info liegt als Geschwister neben der
                    // Liste, z.B. "data.products.pageInfo" neben
                    // "data.products.edges" -- letztes Pfadsegment (i.d.R.
                    // "edges"/"data") durch "pageInfo" bzw. "pagination"
                    // ersetzen. Zwei grundverschiedene Stile werden erkannt:
                    //   - GraphQL-Cursor (Shopify): {pageInfo:{hasNextPage,endCursor}}
                    //   - REST offset/limit (HelloCash): {pagination:{limit,offset,has_more,total}}
                    // Objects ohne beides an dieser Stelle (z.B. shopLocales,
                    // einfache REST-Listen ohne Pagination) liefern einfach
                    // null zurueck -> $hasNextPage bleibt false.
                    $segments = explode('.', $operation->responseRecordsPath);
                    array_pop($segments);
                    $pageInfo = self::extractByPath($decoded, implode('.', array_merge($segments, ['pageInfo'])));
                    $pagination = self::extractByPath($decoded, implode('.', array_merge($segments, ['pagination'])));

                    if (is_array($pageInfo) && !empty($pageInfo['hasNextPage'])) {
                        $cursor = (string) ($pageInfo['endCursor'] ?? '');
                        if ($cursor !== '') {
                            $hasNextPage = true;
                            $nextPageParams = ['cursor' => $cursor];
                        }
                    } elseif (is_array($pagination) && isset($pagination['limit'], $pagination['offset'])) {
                        $limit = (int) $pagination['limit'];
                        $offset = (int) $pagination['offset'];
                        $total = isset($pagination['total']) ? (int) $pagination['total'] : null;
                        // has_more direkt uebernehmen, wenn vorhanden -- sonst
                        // aus offset+limit gegen total herleiten (falls auch
                        // das fehlt, gilt die Seite als letzte: sicherer
                        // Default gegen eine Endlosschleife).
                        $hasMore = array_key_exists('has_more', $pagination)
                            ? (bool) $pagination['has_more']
                            : ($total !== null && ($offset + $limit) < $total);

                        if ($hasMore && $limit > 0) {
                            $hasNextPage = true;
                            $nextPageParams = ['offset' => $offset + $limit];
                        }
                    }
                }
            }

            // Listen ohne pagination-Info (z.B. helloCash v1): mit "_page_size" weiterblaettern,
            // solange eine volle Seite kommt.
            $pageSize = (int) ($operation->staticParams['_page_size'] ?? 0);
            if (!$hasNextPage && $pageSize > 0 && is_array($rows) && count($rows) >= $pageSize) {
                $hasNextPage = true;
                $nextPageParams = ['offset' => (int) ($requestParams['offset'] ?? 0) + ($offsetByPage ? 1 : $pageSize)];
            }

            foreach ($rows as $row) {
                yield $mapRows ? self::mapRowToFields($row, $object, $this->requestFormat) : $row;
            }
        } while ($hasNextPage);
    }

    /**
     * Gezielter Einzel-Datensatz-Abruf (Operationstyp "read_one"), z.B. fuer
     * ein Webhook-getriggertes Update. Keine Pagination -- ein Request, eine
     * Zeile. response_records_path muss so gewaehlt sein, dass die extrahierte
     * Zeile dieselbe Form hat wie eine Zeile aus read() (siehe Migration
     * 0017_shopify_product_read_one.sql fuer das GraphQL-Alias-Muster dafuer).
     */
    public function readOne(string $objectKey, array $params): ?array
    {
        $operation = $this->requireOperation($objectKey, 'read_one');
        $object = $this->requireObject($objectKey);

        $body = $this->buildReadBody($operation, $params);
        $response = $this->executeRequest($operation, $params, $body);

        if (!$response->isSuccess()) {
            throw new \RuntimeException("Lesen von \"{$objectKey}\" (einzeln) fehlgeschlagen: HTTP {$response->statusCode} - {$response->body}");
        }

        if ($this->requestFormat === 'xml') {
            $row = XmlDatasetParser::parseFlat($response->body);
        } else {
            $decoded = json_decode($response->body, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException("Antwort von \"{$objectKey}\" ist kein gueltiges JSON.");
            }
            $row = $operation->responseRecordsPath !== null ? self::extractByPath($decoded, $operation->responseRecordsPath) : $decoded;
        }

        if (!is_array($row)) {
            return null;
        }

        // "node" ist die GraphQL-Alias-Konvention (siehe Migration 0017/0018,
        // Shopify/MGM): per Alias umbenanntes, nicht gefundenes Feld liefert
        // null (z.B. Produkt in Shopify geloescht) -- als "nicht gefunden"
        // behandeln statt einer Exception, das ist ein normaler, erwartbarer
        // Fall. Eine normale REST-Antwort (z.B. HelloCash "GET /articles/{id}"
        // -> {"data": {...}}, kein "node"-Schluessel) hat diese Konvention
        // nicht -- dort ist $row nach der responseRecordsPath-Extraktion
        // bereits der gesuchte Datensatz selbst.
        if (array_key_exists('node', $row) && !is_array($row['node'])) {
            return null;
        }

        return self::mapRowToFields($row, $object, $this->requestFormat);
    }

    /**
     * Holt einen EINZELNEN, rohen Beispiel-Datensatz (die erste Seite der
     * read_list-Operation, erstes Element) -- bewusst OHNE mapRowToFields(),
     * damit auch Felder sichtbar werden, die noch gar nicht als
     * connect_object_fields definiert sind. Fuer den "Felder erkennen"-
     * Assistenten im Connector Builder (object_edit.php), NICHT fuer den
     * normalen Sync-Betrieb gedacht.
     *
     * Nur moeglich, wenn eine read_list-Operation existiert -- schreibende
     * Objects ohne Lesezugriff (z.B. der MGM-Auftrag) haben keine
     * Beispieldaten zum Entdecken, liefern hier bewusst null statt eines
     * Fehlers (das ist ein normaler, erwartbarer Fall).
     *
     * @return array<string, mixed>|null
     */
    public function fetchRawSample(string $objectKey, array $params = []): ?array
    {
        $operation = $this->operationsByObjectAndType[$objectKey . ':read_list'] ?? null;
        if ($operation === null) {
            return null;
        }

        // offset=0 wie in read() -- siehe dortiger Kommentar. $params z.B. fuer Pflicht-
        // Variablen der Abfrage (Shop-Felder, Connect\Sync\ShopFields::paramsForConnection()).
        $params = array_merge($params, ['offset' => 0]);
        $body = $this->buildReadBody($operation, $params);
        $response = $this->executeRequest($operation, $params, $body);

        if (!$response->isSuccess()) {
            throw new \RuntimeException("Beispiel-Abruf fuer \"{$objectKey}\" fehlgeschlagen: HTTP {$response->statusCode} - {$response->body}");
        }

        if ($this->requestFormat === 'xml') {
            $rows = $operation->responseRecordsPath !== null
                ? XmlDatasetParser::parseNamedRows($response->body, $operation->responseRecordsPath)
                : XmlDatasetParser::parseRows($response->body);

            return $rows[0] ?? null;
        }

        $decoded = json_decode($response->body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Antwort von \"{$objectKey}\" ist kein gueltiges JSON.");
        }

        $rows = $operation->responseRecordsPath !== null
            ? (self::extractByPath($decoded, $operation->responseRecordsPath) ?? [])
            : $decoded;

        if (!is_array($rows)) {
            return null;
        }

        // Einzelobjekt (z.B. Shopify "shop", keine Liste) direkt zurueckgeben,
        // sonst das erste Element einer Liste -- gleiche Unterscheidung wie
        // in read() (siehe dortiger Kommentar zu array_is_list()).
        if (!array_is_list($rows)) {
            return $rows;
        }

        return is_array($rows[0] ?? null) ? $rows[0] : null;
    }

    /**
     * GraphQL-Schema-Introspection: fragt direkt beim Schema der Verbindung
     * ab, welche Felder ein Typ (z.B. "Order", "Customer") tatsaechlich hat --
     * unabhaengig davon, was die eigenen gespeicherten Operationen aktuell
     * abfragen. Fuer die "Schema erkunden"-Funktion im Connector Builder
     * (object_edit.php), damit Admins nicht raten muessen, welche Felder
     * ueberhaupt verfuegbar waeren. Nur bei JSON/GraphQL-Systemen moeglich --
     * XML-Systeme wie MGM haben kein Introspection-Konzept.
     *
     * Der Pfad zum GraphQL-Endpunkt wird von irgendeiner bereits
     * gespeicherten Operation dieses Connector Types "geborgt" (der Endpunkt
     * ist fuer alle Objects/Operationen desselben Systems ohnehin identisch).
     *
     * @return array<int, array{name: string, description: ?string, typeLabel: string}>
     */
    public function introspectType(string $typeName): array
    {
        if ($this->requestFormat !== 'json') {
            throw new \RuntimeException('Schema-Introspection ist nur bei JSON/GraphQL-Systemen möglich.');
        }

        $pathTemplate = null;
        foreach ($this->operationsByObjectAndType as $operation) {
            $pathTemplate = $operation->pathTemplate;
            break;
        }

        if ($pathTemplate === null) {
            throw new \RuntimeException('Keine Operation vorhanden, um den API-Endpunkt zu ermitteln -- zuerst irgendeine Operation anlegen.');
        }

        $introspectionQuery = 'query($typeName: String!) { __type(name: $typeName) { name fields { name description type { kind name ofType { kind name ofType { kind name } } } } } }';
        $body = json_encode(['query' => $introspectionQuery, 'variables' => ['typeName' => $typeName]], JSON_THROW_ON_ERROR);

        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($pathTemplate, '/');
        $headers = $this->buildAuthHeaders();
        $headers['Content-Type'] = 'application/json';

        $response = $this->httpClient->request('POST', $url, $headers, $body);

        if (!$response->isSuccess()) {
            throw new \RuntimeException("Introspection fehlgeschlagen: HTTP {$response->statusCode} - " . mb_substr($response->body, 0, 500));
        }

        $decoded = json_decode($response->body, true);
        $typeData = $decoded['data']['__type'] ?? null;

        if ($typeData === null) {
            throw new \RuntimeException("Typ \"{$typeName}\" wurde im Schema nicht gefunden -- Tippfehler? Gross-/Kleinschreibung beachten (GraphQL-Typnamen beginnen meist gross, z.B. \"Order\").");
        }

        $result = [];
        foreach ($typeData['fields'] ?? [] as $field) {
            $result[] = [
                'name' => $field['name'],
                'description' => $field['description'],
                'typeLabel' => self::formatGraphQlTypeLabel($field['type']),
            ];
        }

        return $result;
    }

    /** Hat das Object eine Operation dieses Typs (z.B. "read_one")? */
    public function hasOperation(string $objectKey, string $operationType): bool
    {
        return isset($this->operationsByObjectAndType[$objectKey . ':' . $operationType]);
    }

    /**
     * Freie GraphQL-Abfrage gegen den Endpunkt dieser Connection -- fuer einmalige
     * Admin-Hilfen (z.B. Shop-Felder: Metafields eines Produkts anzeigen, siehe
     * Connect\Sync\ShopFields::discoverMetafields()), nicht fuer den Sync-Betrieb.
     * Endpunkt wie bei introspectType() von einer gespeicherten Operation geborgt.
     *
     * @param array<string, mixed> $variables
     * @return array<string, mixed> dekodierte Antwort
     */
    public function graphQlQuery(string $query, array $variables = []): array
    {
        if ($this->requestFormat !== 'json') {
            throw new \RuntimeException('GraphQL-Abfragen sind nur bei JSON/GraphQL-Systemen möglich.');
        }

        $pathTemplate = null;
        foreach ($this->operationsByObjectAndType as $operation) {
            $pathTemplate = $operation->pathTemplate;
            break;
        }
        if ($pathTemplate === null) {
            throw new \RuntimeException('Keine Operation vorhanden, um den API-Endpunkt zu ermitteln.');
        }

        $body = json_encode(['query' => $query, 'variables' => (object) $variables], JSON_THROW_ON_ERROR);
        $headers = $this->buildAuthHeaders();
        $headers['Content-Type'] = 'application/json';

        $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/') . '/' . ltrim($pathTemplate, '/'), $headers, $body);
        if (!$response->isSuccess()) {
            throw new \RuntimeException("Abfrage fehlgeschlagen: HTTP {$response->statusCode} - " . mb_substr($response->body, 0, 500));
        }

        $decoded = json_decode($response->body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Antwort ist kein gültiges JSON.');
        }
        if (!empty($decoded['errors']) && is_array($decoded['errors']) && empty($decoded['data'])) {
            $messages = array_map(static fn ($e) => is_array($e) ? (string) ($e['message'] ?? 'unbekannt') : (string) $e, $decoded['errors']);
            throw new \RuntimeException('GraphQL-Fehler: ' . implode('; ', $messages));
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $type
     */
    private static function formatGraphQlTypeLabel(array $type): string
    {
        if ($type['kind'] === 'NON_NULL' && isset($type['ofType'])) {
            return self::formatGraphQlTypeLabel($type['ofType']) . '!';
        }

        if ($type['kind'] === 'LIST' && isset($type['ofType'])) {
            return '[' . self::formatGraphQlTypeLabel($type['ofType']) . ']';
        }

        return (string) ($type['name'] ?? $type['kind']);
    }

    public function write(string $objectKey, string $operationType, array $data): WriteResult
    {
        $operation = $this->requireOperation($objectKey, $operationType);
        $body = $this->buildWriteBody($operation, $data);

        try {
            // $data zusaetzlich als path_template-Parameter durchreichen (z.B.
            // HelloCash "articles/{{id}}/change-stock") -- vorher wurde hier
            // immer ein leeres Array uebergeben, wodurch {{platzhalter}} im
            // Pfad einer update-Operation nie ersetzt wurden.
            $response = $this->executeRequest($operation, $data, $body);
        } catch (\Throwable $e) {
            return WriteResult::failure('Schreiben fehlgeschlagen: ' . $e->getMessage(), $body);
        }

        if (!$response->isSuccess()) {
            return WriteResult::failure("Schreiben fehlgeschlagen: HTTP {$response->statusCode} - {$response->body}", $body, $response->body);
        }

        if ($this->requestFormat === 'xml') {
            return WriteResult::success(XmlDatasetParser::parseFlat($response->body), 'Datensatz erfolgreich geschrieben.', $body, $response->body);
        }

        $decoded = json_decode($response->body, true);
        if (!is_array($decoded)) {
            return WriteResult::failure('Antwort ist kein gueltiges JSON.', $body, $response->body);
        }

        // GraphQL-Mutationen (body_template gesetzt, siehe buildWriteBody())
        // melden fachliche Fehler NICHT per HTTP-Status (der ist bei GraphQL
        // fast immer 200), sondern per "errors" (Query-Ebene, z.B. Syntax-/
        // Rechtefehler) oder userErrors am aliasierten Mutationsfeld "result"
        // (Konvention -- Operation muss die Mutation als "result:" aliasieren,
        // siehe Migration 0027, analog zum "node"-Alias bei readOne()). Ohne
        // diese Pruefung wuerde z.B. ein falscher Lagerort bei Shopify
        // faelschlich als Erfolg durchgehen, weil der HTTP-Status trotzdem 200 ist.
        if ($operation->bodyTemplate !== null) {
            if (!empty($decoded['errors']) && is_array($decoded['errors'])) {
                $message = implode('; ', array_map(static fn ($e) => (string) ($e['message'] ?? 'unbekannter Fehler'), $decoded['errors']));
                return WriteResult::failure("GraphQL-Fehler: {$message}", $body, $response->body);
            }

            $userErrors = $decoded['data']['result']['userErrors'] ?? null;
            if (is_array($userErrors) && count($userErrors) > 0) {
                $message = implode('; ', array_map(static fn ($e) => (string) ($e['message'] ?? 'unbekannter Fehler'), $userErrors));
                return WriteResult::failure("Validierungsfehler: {$message}", $body, $response->body);
            }
        }

        // Plaine REST-Schreibzugriffe (kein GraphQL-body_template, z.B.
        // HelloCash "article.store": Antwort {"data": {...Artikel-Felder...}})
        // -- response_records_path (dieselbe Spalte wie bei Lese-Operationen)
        // packt die Nutzdaten aus, falls gesetzt, damit die Ziel-ID-Extraktion
        // in Connect\Sync\SyncRunner::translateAndWrite() (dieselben
        // field_path-Werte wie beim Lesen) auch fuer Schreibzugriffe
        // funktioniert -- ohne diese Auspackung waere z.B. "id" bei einer
        // unter "data" verschachtelten Antwort nie auffindbar. Nur wenn das
        // Ergebnis tatsaechlich ein Array ist -- sonst (z.B. falsch
        // konfigurierter Pfad) bleibt die volle, unausgepackte Antwort
        // erhalten, statt sie durch null zu ersetzen.
        if ($operation->bodyTemplate === null && $operation->responseRecordsPath !== null) {
            $unwrapped = self::extractByPath($decoded, $operation->responseRecordsPath);
            if (is_array($unwrapped)) {
                $decoded = $unwrapped;
            }
        }

        return WriteResult::success($decoded, 'Datensatz erfolgreich geschrieben.', $body, $response->body);
    }

    /**
     * Baut den Sende-Body fuer eine Schreib-Operation, OHNE die Anfrage
     * tatsaechlich abzuschicken -- fuer die "Vorschau"-Funktion im
     * Mapping-Editor (mapping_edit.php), damit dort das ECHTE Zielformat
     * (z.B. das fertig gerenderte MGM-XML) angezeigt werden kann, statt nur
     * des generischen JSON-Zwischenformats, das die Mapping Engine
     * unabhaengig vom Zielformat liefert.
     *
     * @param array<string, mixed> $data
     */
    public function buildWriteBodyForPreview(string $objectKey, string $operationType, array $data): string
    {
        return $this->buildWriteBody($this->requireOperation($objectKey, $operationType), $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildWriteBody(OperationDefinition $operation, array $data): string
    {
        if ($this->requestFormat === 'xml') {
            return XmlTemplateRenderer::render($operation->bodyTemplate ?? '', $data, $operation->staticParams);
        }

        // JSON + vorhandenes body_template: die Operation ist eine GraphQL-
        // Mutation (z.B. Shopify inventorySetQuantities, siehe Migration 0027)
        // -- braucht dieselbe {"query":...,"variables":...}-Huelle wie
        // Lesezugriffe (buildReadBody()), nicht die gemappten Felder als
        // rohen Body. Ohne body_template (z.B. ein einfacher REST-Schreibzugriff
        // wie HelloCash "change-stock") bleiben die gemappten Felder direkt
        // der JSON-Body -- das urspruengliche, unveraenderte Verhalten.
        if ($operation->bodyTemplate !== null) {
            // Manche Shopify-Mutationen verlangen "@idempotent(key: $...)" AM
            // MUTATIONSFELD (nicht am mutation-Operator, siehe Migration 0038/
            // 0039 -- Live-Fehler: "'@idempotent' can't be applied to
            // mutations (allowed: fields)") -- der Schluessel ist ein ganz
            // normales GraphQL-Variable, kein HTTP-Header. Wird hier generisch
            // ergaenzt, wenn body_template die Direktive enthaelt, damit
            // Operationen sie einfach im Query-Text referenzieren koennen
            // (@idempotent(key: $idempotencyKey)), ohne dass der Aufrufer
            // (z.B. StockSyncRunner) einen Schluessel selbst erzeugen muss.
            if (str_contains($operation->bodyTemplate, '@idempotent')) {
                $data['idempotencyKey'] = self::generateIdempotencyKey();
            }

            return $this->buildReadBody($operation, $data) ?? '';
        }

        return json_encode(array_merge($operation->staticParams, $data), JSON_THROW_ON_ERROR);
    }

    /**
     * Baut den Anfrage-Body fuer Lesezugriffe (read(), readOne(),
     * fetchRawSample(), testConnection()) -- je nach request_format:
     *
     * - JSON/GraphQL (z.B. Shopify, seit Anfang 2025 fuer Produkte/Varianten
     *   verpflichtend statt REST): body_template enthaelt die GraphQL-Query
     *   als reinen Text, keine {{platzhalter}} -- GraphQL hat mit seinen
     *   eigenen $variablen bereits einen sauberen Mechanismus dafuer.
     * - XML (MGM/AGILA "CustomCmd"-Stil, z.B. Lagerstand-Abfrage): body_template
     *   ist ein vollstaendiges XML-Template mit {{platzhalter}}, genau wie bei
     *   Schreib-Operationen (buildWriteBody()) -- nur ueber XmlTemplateRenderer
     *   gerendert statt json_encode. Fehlt ein body_template (z.B. REST-artige
     *   XML-Lesezugriffe ganz ohne Body), wird kein Body gesendet.
     *
     * @param array<string, mixed> $params
     */
    private function buildReadBody(OperationDefinition $operation, array $params): ?string
    {
        if ($operation->bodyTemplate === null) {
            return null;
        }

        if ($this->requestFormat === 'xml') {
            return XmlTemplateRenderer::render($operation->bodyTemplate, $params, $operation->staticParams);
        }

        if ($this->requestFormat !== 'json') {
            return null;
        }

        // (object)-Cast erzwingt "{}" statt "[]" im JSON, wenn keine
        // Variablen vorhanden sind -- PHP kodiert ein leeres Array sonst
        // als JSON-Array, aber GraphQL-Endpunkte (Shopify) verlangen fuer
        // "variables" strikt ein JSON-Objekt und lehnen "[]" mit HTTP 400 ab.
        return json_encode([
            'query' => $operation->bodyTemplate,
            'variables' => (object) array_merge($operation->staticParams, $params),
        ], JSON_THROW_ON_ERROR);
    }

    /** Retry-After wird nur bis zu dieser Dauer abgewartet und der Aufruf einmal wiederholt --
     *  laenger soll kein Job (erst recht kein Web-Request wie "Verbindung testen") blockieren.
     *  Ein laengeres Retry-After wird trotzdem beim RateLimiter hinterlegt (siehe unten), der
     *  naechste Aufruf (auch aus einem anderen Prozess) wartet dann von selbst entsprechend. */
    private const MAX_RETRY_AFTER_SLEEP_SECONDS = 15;

    /**
     * @param array<string, mixed> $params
     */
    /**
     * Beleg als PDF (Operationstyp "read_pdf", z.B. helloCash v2 GET /invoices/{{id}}/pdf).
     * Die Antwort ist entweder das PDF selbst oder JSON mit dem PDF als Base64 -- dann zeigt
     * response_records_path der Operation auf den Base64-Wert (z.B. helloCash v1).
     * Nichts wird gespeichert, das PDF geht direkt an den Aufrufer.
     *
     * @param array<string, mixed> $params Platzhalter im Pfad, z.B. ['id' => 220642321]
     * @return array{ok: bool, body: string, filename: ?string, error: ?string}
     */
    public function fetchDocument(string $objectKey, array $params): array
    {
        $operation = $this->requireOperation($objectKey, 'read_pdf');
        $response = $this->executeRequest($operation, $params, null, ['Accept' => 'application/pdf, application/json']);
        if (!$response->isSuccess()) {
            return ['ok' => false, 'body' => '', 'filename' => null, 'error' => "HTTP {$response->statusCode} - " . mb_substr($response->body, 0, 300)];
        }

        $pdf = $response->body;
        if (!str_starts_with(ltrim($pdf), '%PDF')) {
            $decoded = json_decode($pdf, true);
            $value = is_array($decoded) && $operation->responseRecordsPath !== null ? self::extractByPath($decoded, $operation->responseRecordsPath) : null;
            // helloCash v1 liefert eine Liste mit genau einem Base64-Wert
            if (is_array($value)) {
                $value = reset($value);
            }
            $pdf = is_string($value) ? (string) base64_decode($value, true) : '';
        }
        if (!str_starts_with(ltrim($pdf), '%PDF')) {
            return ['ok' => false, 'body' => '', 'filename' => null, 'error' => 'Die Antwort ist kein PDF (bei JSON-Antworten in der Operation den Antwort-Pfad zum Base64-Wert eintragen).'];
        }

        $filename = null;
        foreach ($response->headers as $name => $value) {
            if (strcasecmp((string) $name, 'Content-Disposition') === 0 && preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $value, $m) === 1) {
                $filename = basename(rawurldecode($m[1]));
            }
        }

        return ['ok' => true, 'body' => $pdf, 'filename' => $filename, 'error' => null];
    }

    /**
     * @param array<string, string> $extraHeaders zusaetzliche Header (z.B. Accept fuer read_pdf)
     */
    private function executeRequest(OperationDefinition $operation, array $params, ?string $body = null, array $extraHeaders = []): \Connect\Http\HttpResponse
    {
        $path = self::renderPathTemplate($operation->pathTemplate, array_merge($operation->staticParams, $params));
        // Leerer path_template (z.B. MGM: die komplette XML geht per POST
        // direkt an die Basis-URL, ohne zusaetzlichen Pfad) darf keinen
        // ueberfluessigen "/" anhaengen -- manche Server behandeln
        // "host:port" und "host:port/" nicht identisch.
        $url = $path === '' ? rtrim($this->baseUrl, '/') : rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        // Absolute Adresse im path_template (nur https): z.B. die Zusatzliste aus helloCash v1, waehrend
        // die Basis-Adresse auf v2 zeigt. Ziel prueft weiterhin OutboundGuard (HttpClient).
        if (preg_match('#^https://#i', $path) === 1) {
            $url = $path;
        }

        $headers = $this->buildAuthHeaders();
        $headers['Content-Type'] = $this->requestFormat === 'xml' ? 'application/xml; charset=utf-8' : 'application/json';
        $headers = array_merge($headers, $extraHeaders);

        // Selbst auferlegte Bremse (siehe Http\RateLimiter) -- VOR dem Aufruf, damit ein
        // konfiguriertes Limit gar nicht erst provoziert wird. Ohne konfiguriertes Limit
        // (Standard) oder ohne RateLimiter (z.B. Aufrufer ohne DB-Zugriff) sofort zurueck.
        if ($this->rateLimiter !== null && $this->rateLimitConfig !== null) {
            $this->rateLimiter->waitForCapacity($this->rateLimitConfig);
        }

        $response = $this->httpClient->request($operation->httpMethod, $url, $headers, $body);
        $this->recordUsage($response);

        // Reaktiv: das Zielsystem selbst meldet "zu viele Anfragen" (z.B. HelloCash dokumentiert
        // genau das, siehe HANDOFF). Kurz warten und GENAU EINMAL wiederholen; ein laengeres
        // Retry-After wird stattdessen nur beim RateLimiter hinterlegt (kuenftige Aufrufe warten
        // dann von selbst), statt einen Job/Web-Request lange zu blockieren.
        if ($response->statusCode === 429) {
            $retryAfter = self::parseRetryAfterSeconds($response->headers);

            if ($retryAfter !== null && $this->rateLimitConfig !== null && $this->rateLimiter !== null) {
                $this->rateLimiter->consumeRetryAfter($this->rateLimitConfig->connectorTypeId, $retryAfter);
            }

            if ($retryAfter !== null && $retryAfter <= self::MAX_RETRY_AFTER_SLEEP_SECONDS) {
                sleep($retryAfter);
                $response = $this->httpClient->request($operation->httpMethod, $url, $headers, $body);
                $this->recordUsage($response);
            }
        } elseif (\Connect\Http\ApiUsageRecorder::isThrottled($response)) {
            // Shopify GraphQL drosselt NICHT mit HTTP 429, sondern mit HTTP 200 und
            // errors[].extensions.code = THROTTLED (Kosten-Eimer leer). Vorher wurde das als
            // gewoehnlicher Fehler -- beim Listen-Lesen sogar als leere Seite -- behandelt.
            // Wartezeit aus Eimerstand und Nachfuellrate, dann GENAU EINMAL wiederholen.
            $wait = self::throttleWaitSeconds($response->body);
            $maxWait = PHP_SAPI === 'cli' ? 60 : 10;
            if ($wait <= $maxWait) {
                sleep($wait);
                $response = $this->httpClient->request($operation->httpMethod, $url, $headers, $body);
                $this->recordUsage($response);
            }
        }

        return $response;
    }

    private function recordUsage(\Connect\Http\HttpResponse $response): void
    {
        if ($this->usageRecorder !== null && $this->connectionId !== null) {
            $this->usageRecorder->record($this->connectionId, $response);
        }
    }

    /**
     * Sekunden bis genug Punkte im Shopify-Kosten-Eimer sind: (angefragte Kosten -
     * verfuegbar) / Nachfuellrate, aufgerundet + 1 s Puffer. Ohne verwertbare Angaben 2 s.
     */
    private static function throttleWaitSeconds(string $body): int
    {
        $cost = \Connect\Http\ApiUsageRecorder::parseShopifyCost($body);
        if ($cost === null || ($cost['restoreRate'] ?? 0) <= 0) {
            return 2;
        }
        $missing = (float) ($cost['requested'] ?? 0) - (float) ($cost['available'] ?? 0);

        return max(1, (int) ceil($missing / (float) $cost['restoreRate']) + 1);
    }

    /**
     * Liest den Retry-After-Header (Sekunden ODER ein HTTP-Datum, beides laut Standard
     * gueltig) -- case-insensitiv, HttpClient::parseHeaders behaelt die Original-Schreibweise
     * des Servers bei.
     *
     * @param array<string, string> $headers
     */
    private static function parseRetryAfterSeconds(array $headers): ?int
    {
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'Retry-After') !== 0) {
                continue;
            }

            $value = trim($value);
            if (ctype_digit($value)) {
                return (int) $value;
            }

            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return max(0, $timestamp - time());
            }
        }

        return null;
    }

    /**
     * UUID v4, nur fuer den Idempotency-Key-Header oben -- keine
     * Notwendigkeit einer externen UUID-Bibliothek fuer diesen einen
     * Anwendungsfall.
     */
    private static function generateIdempotencyKey(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * @return array<string, string>
     */
    private function buildAuthHeaders(): array
    {
        switch ($this->authType) {
            case 'bearer':
                return ['Authorization' => 'Bearer ' . ($this->credentials['token'] ?? '')];

            case 'basic':
                $encoded = base64_encode(($this->credentials['username'] ?? '') . ':' . ($this->credentials['password'] ?? ''));
                return ['Authorization' => 'Basic ' . $encoded];

            case 'api_key':
                $headerName = $this->credentials['header_name'] ?? 'X-Api-Key';
                return [$headerName => $this->credentials['header_value'] ?? ''];

            case 'custom_headers':
                return $this->credentials['headers'] ?? [];

            case 'none':
            default:
                return [];
        }
    }

    private function requireObject(string $objectKey): ObjectDefinition
    {
        return $this->objectsByKey[$objectKey]
            ?? throw new \RuntimeException("Unbekanntes Object: \"{$objectKey}\"");
    }

    private function requireOperation(string $objectKey, string $operationType): OperationDefinition
    {
        return $this->operationsByObjectAndType[$objectKey . ':' . $operationType]
            ?? throw new \RuntimeException("Keine \"{$operationType}\"-Operation fuer Object \"{$objectKey}\" definiert.");
    }

    /**
     * Ersetzt {{platzhalter}} im Pfad-Template durch Werte aus $params.
     *
     * @param array<string, mixed> $params
     */
    private static function renderPathTemplate(string $template, array $params): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', static function (array $matches) use ($params) {
            return (string) ($params[$matches[1]] ?? '');
        }, $template) ?? $template;
    }

    /**
     * Liest einen Wert aus einem verschachtelten Array anhand eines
     * Punkt-getrennten Pfads, z.B. "data.items" -> $data['data']['items'].
     * Bewusst eine einfache Eigenimplementierung statt einer JSONPath-
     * Bibliothek -- reicht fuer die ueblichen REST-API-Formen (Shopify,
     * HelloCash), volle JSONPath-Syntax (Filter, Wildcards) ist nicht Ziel.
     *
     * Public (statt private), damit Connect\Sync\SyncRunner::translateAndWrite()
     * dieselbe Logik fuer die Ziel-ID-Extraktion aus einer Schreib-Antwort
     * nutzen kann (z.B. HelloCash "article.store": Antwort verschachtelt
     * unter {"data": {"id": ...}}, ein flacher Schluessel-Zugriff wie zuvor
     * dort verwendet findet "id" nicht) -- eine zweite Kopie dieser kleinen,
     * aber nicht ganz trivialen Logik waere unnoetig.
     */
    public static function extractByPath(array $data, string $path): mixed
    {
        $current = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Bildet eine rohe Datenzeile (JSON-Objekt oder von XmlDatasetParser
     * gelieferte Zeile) auf field_key => Wert gemaess den definierten
     * Feldern des Objects ab.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapRowToFields(array $row, ObjectDefinition $object, string $requestFormat): array
    {
        $mapped = [];
        foreach ($object->fields as $field) {
            if ($field->parentKey !== null) {
                // Kind-Feld einer wiederholenden Gruppe (z.B. "line_sku"
                // unter "line_items") -- wird unten beim Verarbeiten der
                // Gruppe selbst behandelt, nicht hier einzeln auf $row.
                continue;
            }

            if ($field->isRepeatingGroup) {
                $mapped[$field->key] = self::mapRepeatingGroup($row, $field, $object, $requestFormat);
                continue;
            }

            $path = $field->path ?? $field->key;
            $mapped[$field->key] = $requestFormat === 'xml'
                ? ($row[$path] ?? null)
                : self::extractByPath($row, $path);
        }

        return $mapped;
    }

    /**
     * Loest ein wiederholende-Gruppen-Feld auf (z.B. Shopify "line_items" ->
     * MGM "Positionen"): holt das Array am Gruppen-Pfad, extrahiert dann pro
     * Element die Kind-Felder RELATIV zu diesem einen Element -- Kind-Feld-
     * Pfade sind entsprechend relativ zum einzelnen Element definiert (siehe
     * z.B. Migration 0006_seed_shopify_orders.sql: line_sku hat field_path
     * "node.sku", nicht "node.lineItems.edges.0.node.sku"). Noetig fuer die
     * Mapping Engine (Zeilenpositionen einer Bestellung), vorher wurden
     * wiederholende Gruppen beim Lesen komplett uebersprungen.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function mapRepeatingGroup(array $row, FieldDefinition $groupField, ObjectDefinition $object, string $requestFormat): array
    {
        $groupPath = $groupField->path ?? $groupField->key;
        $items = $requestFormat === 'xml' ? ($row[$groupPath] ?? []) : (self::extractByPath($row, $groupPath) ?? []);

        if (!is_array($items)) {
            return [];
        }

        $childFields = array_values(array_filter(
            $object->fields,
            static fn (FieldDefinition $f) => $f->parentKey === $groupField->key
        ));

        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $mappedItem = [];
            foreach ($childFields as $childField) {
                $childPath = $childField->path ?? $childField->key;
                $mappedItem[$childField->key] = $requestFormat === 'xml'
                    ? ($item[$childPath] ?? null)
                    : self::extractByPath($item, $childPath);
            }
            $result[] = $mappedItem;
        }

        return $result;
    }
}
