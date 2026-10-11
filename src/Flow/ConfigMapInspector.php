<?php

declare(strict_types=1);

namespace Connect\Flow;

use Connect\Sync\Document\DocumentProfile;
use PDO;

/**
 * Baut fuer die globale Konfigurations-Landkarte (admin/config_map.php) alle
 * shop-uebergreifenden Bausteine als Diagramm-Daten: Systeme (Connector
 * Types) mit ihren Objects, Mapping-Vorlagen als Verbindungen zwischen
 * Objects, Funktionen, Lookup-Tabellen, Job-Vorlagen und Belegprofile -- und
 * fuer jeden Baustein, wo er verwendet wird (Shops, Aktivierungen, andere
 * Bausteine). Gegenstueck zu FlowInspector (Shop-Ebene).
 *
 * Rein lesend. Ampeln aus Konfigurationspruefungen (z.B. Vorlage ohne
 * Feldzuordnungen, Ziel-Object ohne Operation "Anlegen", nicht verwendete
 * Vorlage/Lookup-Tabelle).
 *
 * Rueckgabe: ['systems' => [...], 'objects' => [...], 'templates' => [...],
 * 'lookups' => [...], 'functions' => [...], 'implicit' => [...]]. Einstellungen
 * im selben Format wie FlowInspector (name, value, src, where, url, warn).
 */
final class ConfigMapInspector
{
    private const SRC_PROFILE = 'prof';
    private const SRC_TEMPLATE = 'tpl';
    private const SRC_OBJECT = 'obj';
    private const SRC_FUNCTION = 'fn';
    private const SRC_FIXED = 'fix';
    private const SRC_SYSTEM = 'sys';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $types = $this->all('SELECT * FROM connect_connector_types ORDER BY id');
        $objects = $this->all('SELECT * FROM connect_objects ORDER BY connector_type_id, label');
        $templates = $this->all('SELECT * FROM connect_mapping_templates ORDER BY name');
        $functions = $this->all('SELECT f.*, t.name AS template_name FROM connect_functions f JOIN connect_mapping_templates t ON t.id = f.mapping_template_id ORDER BY f.label');
        $jobTemplates = $this->all('SELECT * FROM connect_sync_job_templates ORDER BY name');
        $lookups = $this->all('SELECT l.*, (SELECT COUNT(*) FROM connect_lookup_table_entries e WHERE e.lookup_table_id = l.id) AS entries FROM connect_lookup_tables l ORDER BY l.name');
        $connections = $this->all('SELECT id, shop, label, connector_type_id, status FROM connect_connections ORDER BY shop');
        $activations = $this->all('SELECT id, shop, name, mapping_template_id, sync_job_template_id, enabled, mapping_kind FROM connect_sync_mappings ORDER BY shop');

        $operationsByObject = [];
        foreach ($this->all('SELECT object_id, operation_type, http_method, path_template FROM connect_object_operations ORDER BY id') as $op) {
            $operationsByObject[(int) $op['object_id']][] = $op;
        }
        $fieldsByObject = [];
        foreach ($this->all('SELECT id, object_id, field_key, label, is_repeating_group, is_identifier, comparison_role, is_stock_quantity, max_length FROM connect_object_fields ORDER BY id') as $field) {
            $fieldsByObject[(int) $field['object_id']][] = $field;
        }
        $mappingsByTemplate = [];
        foreach ($this->all('SELECT mapping_template_id, transform_type, transform_config FROM connect_field_mappings') as $fm) {
            $mappingsByTemplate[(int) $fm['mapping_template_id']][] = $fm;
        }

        // Belegprofile: welches Object hat eines, welche Kunden-Vorlage verweist es
        $profiles = [];
        $customerTemplateRefs = [];
        foreach ($objects as $object) {
            if (($object['document_profile'] ?? null) !== null && (string) $object['document_profile'] !== '') {
                $profile = DocumentProfile::fromJson((string) $object['document_profile']);
                $profiles[(int) $object['id']] = $profile;
                if ($profile->customerCreateTemplateId !== null) {
                    $customerTemplateRefs[$profile->customerCreateTemplateId][] = (int) $object['id'];
                }
            }
        }

        $typeById = [];
        foreach ($types as $type) {
            $typeById[(int) $type['id']] = $type;
        }
        $objectById = [];
        foreach ($objects as $object) {
            $objectById[(int) $object['id']] = $object;
        }

        // ---- Lookup-Verwendung -------------------------------------------------
        $lookupUsage = [];
        $templateLookups = [];
        foreach ($mappingsByTemplate as $templateId => $rows) {
            foreach ($rows as $row) {
                if ($row['transform_type'] !== 'lookup') {
                    continue;
                }
                $config = $row['transform_config'] !== null ? (json_decode((string) $row['transform_config'], true) ?: []) : [];
                $lookupId = (int) ($config['lookup_table_id'] ?? 0);
                if ($lookupId > 0) {
                    $lookupUsage[$lookupId][$templateId] = true;
                    $templateLookups[$templateId][$lookupId] = true;
                }
            }
        }
        $lookupById = [];
        foreach ($lookups as $lookup) {
            $lookupById[(int) $lookup['id']] = $lookup;
        }

        // ---- Templates ---------------------------------------------------------
        $templateById = [];
        foreach ($templates as $template) {
            $templateById[(int) $template['id']] = $template;
        }
        $outTemplates = [];
        $objectTemplateRoles = [];
        foreach ($templates as $template) {
            $id = (int) $template['id'];
            $rows = $mappingsByTemplate[$id] ?? [];
            $omit = 0;
            $manual = 0;
            foreach ($rows as $row) {
                $config = $row['transform_config'] !== null ? (json_decode((string) $row['transform_config'], true) ?: []) : [];
                if (!empty($config['omit_if_empty'])) {
                    $omit++;
                }
                if ($row['transform_type'] === 'manual_input') {
                    $manual++;
                }
            }
            $source = $objectById[(int) $template['source_object_id']] ?? null;
            $target = $objectById[(int) $template['target_object_id']] ?? null;
            $objectTemplateRoles[(int) $template['source_object_id']]['source'][] = $id;
            $objectTemplateRoles[(int) $template['target_object_id']]['target'][] = $id;

            $usedBy = [];
            foreach ($activations as $activation) {
                if ((int) ($activation['mapping_template_id'] ?? 0) === $id) {
                    $usedBy[] = ['label' => 'Shop ' . $activation['shop'] . ' · Aktivierung „' . $activation['name'] . '“' . ((int) $activation['enabled'] === 1 ? '' : ' (inaktiv)'),
                        'url' => 'flows.php?' . http_build_query(['shop' => $activation['shop']]) . '#m' . (int) $activation['id']];
                }
            }
            $templateFunctions = [];
            foreach ($functions as $function) {
                if ((int) $function['mapping_template_id'] === $id) {
                    $templateFunctions[] = (string) $function['label'];
                    $usedBy[] = ['label' => 'Funktion „' . $function['label'] . '“ (' . self::functionUse((string) $function['function_kind']) . ')', 'url' => 'functions.php'];
                }
            }
            foreach ($customerTemplateRefs[$id] ?? [] as $profileObjectId) {
                $profileObject = $objectById[$profileObjectId] ?? null;
                $usedBy[] = ['label' => 'Belegprofil „' . ($profileObject['label'] ?? '?') . '“ – Kunden-Neuanlage', 'url' => 'object_edit.php?id=' . $profileObjectId];
            }

            $targetHasCreate = $target !== null && self::hasWriteOperation($operationsByObject[(int) $target['id']] ?? []);
            $sourceIsPrepared = $source !== null && ($typeById[(int) $source['connector_type_id']]['type_key'] ?? '') === 'connect_internal';
            $lookupNames = [];
            foreach (array_keys($templateLookups[$id] ?? []) as $lookupId) {
                $lookupNames[] = ['id' => $lookupId, 'name' => (string) ($lookupById[$lookupId]['name'] ?? 'gelöscht')];
            }

            $status = 'ok';
            $note = null;
            if ($rows === []) {
                $status = 'err';
                $note = 'Die Vorlage hat keine Feldzuordnungen.';
            } elseif (!$targetHasCreate) {
                $status = 'err';
                $note = 'Das Ziel-Object hat keine schreibende Operation (z. B. „Anlegen“).';
            } elseif ($usedBy === []) {
                $status = 'warn';
                $note = 'Nicht verwendet: keine Aktivierung, keine Funktion, kein Belegprofil.';
            }

            $settings = [
                $this->setting('Quelle', ($source !== null ? ($typeById[(int) $source['connector_type_id']]['name'] ?? '?') . ' · ' . $source['label'] : 'gelöscht'), self::SRC_TEMPLATE, null),
                $this->setting('Ziel', ($target !== null ? ($typeById[(int) $target['connector_type_id']]['name'] ?? '?') . ' · ' . $target['label'] : 'gelöscht'), self::SRC_TEMPLATE, null),
                $this->setting('Feldzuordnungen', count($rows) . ' · ' . $omit . ' „nur wenn vorhanden“' . ($manual > 0 ? ' · ' . $manual . ' manuelle Eingabe' : ''), self::SRC_TEMPLATE, 'Mapping-Vorlage → Felder zuordnen', 'mapping_template_edit.php?id=' . $id, $rows === []),
                $this->setting('Verarbeitung', $sourceIsPrepared ? 'Beleg-Ablauf mit Aufbereitung, Kontrollen und Belegprofil' : 'einfache Übersetzung ohne Kontrollen', self::SRC_FIXED, null),
            ];
            foreach ($lookupNames as $lookup) {
                $settings[] = $this->setting('Lookup-Tabelle', $lookup['name'], self::SRC_TEMPLATE, 'Konfiguration → Lookup-Tabellen', 'lookup_table_edit.php?id=' . $lookup['id']);
            }
            foreach ($templateFunctions as $functionLabel) {
                $settings[] = $this->setting('Funktion', $functionLabel, self::SRC_FUNCTION, 'Konfiguration → Funktionen', 'functions.php');
            }

            $outTemplates[] = [
                'id' => $id,
                'name' => (string) $template['name'],
                'source' => (int) $template['source_object_id'],
                'target' => (int) $template['target_object_id'],
                'fieldCount' => count($rows),
                'hasFunction' => $templateFunctions !== [],
                'hasLookup' => $lookupNames !== [],
                'status' => $status,
                'note' => $note,
                'settings' => $settings,
                'usedBy' => $usedBy,
                'open' => ['label' => 'Vorlage öffnen', 'url' => 'mapping_template_edit.php?id=' . $id],
                'search' => mb_strtolower($template['name'] . ' ' . implode(' ', array_column($lookupNames, 'name')) . ' ' . implode(' ', $templateFunctions)),
            ];
        }

        // ---- Objects -----------------------------------------------------------
        $outObjects = [];
        foreach ($objects as $object) {
            $id = (int) $object['id'];
            $type = $typeById[(int) $object['connector_type_id']] ?? null;
            $typeKey = (string) ($type['type_key'] ?? '');
            $ops = $operationsByObject[$id] ?? [];
            $fields = $fieldsByObject[$id] ?? [];
            $asSource = $objectTemplateRoles[$id]['source'] ?? [];
            $asTarget = $objectTemplateRoles[$id]['target'] ?? [];
            $objectJobTemplates = array_values(array_filter($jobTemplates, static fn ($jt) => (int) $jt['object_id'] === $id));
            $profile = $profiles[$id] ?? null;
            $isInternal = $typeKey === 'connect_internal';

            $roles = [];
            $maxLengths = [];
            $identifier = null;
            foreach ($fields as $field) {
                if ($field['comparison_role'] !== null && $field['comparison_role'] !== '') {
                    $roles[] = self::roleLabel((string) $field['comparison_role']) . ' = ' . $field['field_key'];
                }
                if ((int) $field['is_stock_quantity'] === 1) {
                    $roles[] = 'Lagerbestand = ' . $field['field_key'];
                }
                if ($field['max_length'] !== null) {
                    $maxLengths[] = $field['field_key'] . ' ' . $field['max_length'];
                }
                if ((int) $field['is_identifier'] === 1) {
                    $identifier = (string) $field['field_key'];
                }
            }

            $badges = [];
            if ($objectJobTemplates !== []) {
                $badges[] = ['icon' => 'clock', 'text' => 'Job'];
            }
            if ($profile !== null) {
                $badges[] = ['icon' => 'doc', 'text' => 'Belegprofil'];
            }
            if ($roles !== []) {
                $badges[] = ['icon' => 'scale', 'text' => count($roles) . ' Rolle' . (count($roles) === 1 ? '' : 'n')];
            }

            $status = 'ok';
            $note = null;
            $hasRead = self::hasOperation($ops, 'read_list') || self::hasOperation($ops, 'read_one');
            if (!$isInternal && $asTarget !== [] && !self::hasWriteOperation($ops)) {
                $status = 'err';
                $note = 'Ist Ziel einer Vorlage, hat aber keine schreibende Operation (z. B. „Anlegen“).';
            } elseif (!$isInternal && ($asSource !== [] || $objectJobTemplates !== []) && !$hasRead) {
                $status = 'err';
                $note = 'Wird gelesen (Vorlage oder Job), hat aber keine Lese-Operation.';
            } elseif (!$isInternal && $fields !== [] && $identifier === null && ($asSource !== [] || $objectJobTemplates !== [])) {
                $status = 'warn';
                $note = 'Kein Feld als „Eindeutige Kennung“ markiert – der Zwischenspeicher funktioniert so nicht.';
            } elseif ($asSource === [] && $asTarget === [] && $objectJobTemplates === [] && $profile === null && !$isInternal) {
                $status = 'opt';
            }

            $opText = $ops === [] ? 'keine' : implode(' · ', array_map(static fn ($op) => self::opLabel((string) $op['operation_type']) . ' ' . $op['http_method'] . ' ' . mb_strimwidth((string) $op['path_template'], 0, 50, '…'), $ops));
            $objectUrl = 'object_edit.php?id=' . $id;
            $settings = [
                $this->setting('Felder', count($fields) . ($identifier !== null ? ' · Kennung ' . $identifier : ' · keine Kennung'), self::SRC_OBJECT, 'Connector Builder → Felder', $objectUrl),
                $this->setting('Operationen', $opText, self::SRC_OBJECT, 'Connector Builder → Operationen', $objectUrl, $status === 'err'),
            ];
            if ($roles !== []) {
                $settings[] = $this->setting('Rollen', implode(' · ', $roles), self::SRC_OBJECT, 'Connector Builder → Felder → Vergleichswert / Lagerbestand', $objectUrl);
            }
            if ($maxLengths !== []) {
                $settings[] = $this->setting('Max. Länge', implode(' · ', $maxLengths), self::SRC_OBJECT, 'Connector Builder → Felder → Max. Länge', $objectUrl);
            }
            if ((int) ($object['hydrate_details_after_list'] ?? 0) === 1) {
                $settings[] = $this->setting('Details pro Datensatz nachladen', 'an · Batch-Größe ' . ($object['hydrate_batch_size'] ?? 200), self::SRC_OBJECT, 'Connector Builder → Stammdaten', $objectUrl);
            }
            if (($object['max_age_days'] ?? null) !== null) {
                $settings[] = $this->setting('Zeitfenster beim Listen-Abruf', 'letzte ' . $object['max_age_days'] . ' Tage', self::SRC_FIXED, 'nur per Migration änderbar');
            }
            if (($object['settings_schema'] ?? null) !== null && trim((string) $object['settings_schema']) !== '') {
                $schema = json_decode((string) $object['settings_schema'], true);
                $keys = is_array($schema) ? array_map(static fn ($f) => is_array($f) ? (string) ($f['key'] ?? '?') : '?', array_values($schema)) : ['ungültiges JSON'];
                $settings[] = $this->setting('Einstellungen pro Aktivierung', $keys !== [] ? implode(', ', $keys) : 'leer', self::SRC_OBJECT, 'Connector Builder → Stammdaten → settings_schema', $objectUrl);
            }
            if ($profile !== null) {
                $settings[] = $this->setting('Belegprofil', self::profileSummary($profile, $templateById), self::SRC_PROFILE, 'Connector Builder → Belegprofil', $objectUrl);
            }
            foreach ($objectJobTemplates as $jt) {
                $settings[] = $this->setting('Job-Vorlage', $jt['name'] . ' · alle ' . $jt['default_interval_minutes'] . ' Min.' . ((int) $jt['auto_activate'] === 1 ? ' · automatisch für neue Connections' : ''), self::SRC_OBJECT, 'Konfiguration → Job-Vorlagen', 'sync_job_templates.php');
            }

            $usedBy = [];
            foreach ($asSource as $templateId) {
                $usedBy[] = ['label' => 'Quelle von Vorlage „' . ($templateById[$templateId]['name'] ?? '?') . '“', 'url' => 'mapping_template_edit.php?id=' . $templateId];
            }
            foreach ($asTarget as $templateId) {
                $usedBy[] = ['label' => 'Ziel von Vorlage „' . ($templateById[$templateId]['name'] ?? '?') . '“', 'url' => 'mapping_template_edit.php?id=' . $templateId];
            }
            foreach ($objectJobTemplates as $jt) {
                $jobShops = array_values(array_unique(array_map(static fn ($a) => (string) $a['shop'], array_filter($activations, static fn ($a) => (int) ($a['sync_job_template_id'] ?? 0) === (int) $jt['id']))));
                if ($jobShops !== []) {
                    $usedBy[] = ['label' => 'Job „' . $jt['name'] . '“ aktiv in ' . count($jobShops) . ' Shop' . (count($jobShops) === 1 ? '' : 's') . ': ' . implode(', ', $jobShops), 'url' => 'sync_job_templates.php'];
                }
            }

            $outObjects[] = [
                'id' => $id,
                'system' => (int) $object['connector_type_id'],
                'key' => (string) $object['object_key'],
                'label' => (string) $object['label'],
                'badges' => $badges,
                'status' => $status,
                'note' => $note,
                'settings' => $settings,
                'usedBy' => $usedBy,
                'involved' => $asSource !== [] || $asTarget !== [] || $profile !== null,
                'open' => $isInternal ? null : ['label' => 'Object öffnen', 'url' => $objectUrl],
                'search' => mb_strtolower($object['label'] . ' ' . $object['object_key'] . ' ' . implode(' ', array_map(static fn ($f) => $f['field_key'] . ' ' . $f['label'], $fields))),
            ];
        }

        // ---- Systeme -----------------------------------------------------------
        $outSystems = [];
        foreach ($types as $type) {
            $id = (int) $type['id'];
            $typeConnections = array_values(array_filter($connections, static fn ($c) => (int) $c['connector_type_id'] === $id));
            $isInternal = (string) $type['type_key'] === 'connect_internal';
            $shops = array_values(array_unique(array_map(static fn ($c) => (string) $c['shop'], $typeConnections)));
            $rateLimit = ($type['rate_limit_max_requests'] ?? null) !== null ? $type['rate_limit_max_requests'] . ' Aufrufe je ' . ($type['rate_limit_window_seconds'] ?? '?') . ' s' : 'kein Limit';
            $settings = $isInternal ? [
                $this->setting('Art', 'von Connect selbst aufbereitete Daten, keine Connection', self::SRC_FIXED, null),
            ] : [
                $this->setting('Anmeldung', (string) $type['auth_type'], self::SRC_SYSTEM, 'Connector Types → Bearbeiten', 'connector_type_edit.php?id=' . $id),
                $this->setting('Datenformat', strtoupper((string) $type['request_format']), self::SRC_SYSTEM, 'Connector Types → Bearbeiten', 'connector_type_edit.php?id=' . $id),
                $this->setting('Feste Basis-Adresse', (string) ($type['base_url_template'] ?? '') !== '' ? (string) $type['base_url_template'] : 'pro Connection', self::SRC_SYSTEM, 'Connector Types → Bearbeiten', 'connector_type_edit.php?id=' . $id),
                $this->setting('Anfrage-Limit', $rateLimit, self::SRC_SYSTEM, 'Connector Types → Bearbeiten', 'connector_type_edit.php?id=' . $id),
            ];
            $usedBy = [];
            foreach ($shops as $shop) {
                $usedBy[] = ['label' => 'Shop ' . $shop, 'url' => 'flows.php?' . http_build_query(['shop' => $shop])];
            }
            $outSystems[] = [
                'id' => $id,
                'key' => (string) $type['type_key'],
                'name' => (string) $type['name'],
                'connections' => count($typeConnections),
                'status' => !$isInternal && $typeConnections === [] ? 'opt' : 'ok',
                'note' => !$isInternal && $typeConnections === [] ? 'Noch keine Connection in einem Shop.' : null,
                'settings' => $settings,
                'usedBy' => $usedBy,
                'open' => $isInternal ? null : ['label' => 'System öffnen', 'url' => 'connector_type_edit.php?id=' . $id],
            ];
        }

        // ---- Lookup-Tabellen ---------------------------------------------------
        $outLookups = [];
        foreach ($lookups as $lookup) {
            $id = (int) $lookup['id'];
            $usedBy = [];
            foreach (array_keys($lookupUsage[$id] ?? []) as $templateId) {
                $usedBy[] = ['label' => 'Vorlage „' . ($templateById[$templateId]['name'] ?? '?') . '“', 'url' => 'mapping_template_edit.php?id=' . $templateId];
            }
            $outLookups[] = [
                'id' => $id,
                'name' => (string) $lookup['name'],
                'status' => $usedBy === [] ? 'warn' : ((int) $lookup['entries'] === 0 ? 'err' : 'ok'),
                'note' => $usedBy === [] ? 'Wird von keiner Vorlage verwendet.' : ((int) $lookup['entries'] === 0 ? 'Die Tabelle hat keine Einträge.' : null),
                'settings' => [
                    $this->setting('Einträge', (string) $lookup['entries'], self::SRC_TEMPLATE, 'Lookup-Tabelle bearbeiten', 'lookup_table_edit.php?id=' . $id, (int) $lookup['entries'] === 0),
                    $this->setting('Beschreibung', (string) ($lookup['description'] ?? '') !== '' ? (string) $lookup['description'] : '–', self::SRC_TEMPLATE, null),
                ],
                'usedBy' => $usedBy,
                'templateIds' => array_keys($lookupUsage[$id] ?? []),
                'open' => ['label' => 'Lookup-Tabelle öffnen', 'url' => 'lookup_table_edit.php?id=' . $id],
                'search' => mb_strtolower((string) $lookup['name']),
            ];
        }

        // ---- Funktionen ----------------------------------------------------------
        $outFunctions = [];
        foreach ($functions as $function) {
            $outFunctions[] = [
                'id' => (int) $function['id'],
                'label' => (string) $function['label'],
                'template' => (int) $function['mapping_template_id'],
                'templateName' => (string) $function['template_name'],
                'use' => self::functionUse((string) $function['function_kind']),
            ];
        }

        // Fest eingebaute Verbindung Shopify-Bestellung -> "Bestellung (aufbereitet)"
        $implicit = [];
        $shopifyOrder = null;
        $prepared = null;
        foreach ($objects as $object) {
            $typeKey = (string) ($typeById[(int) $object['connector_type_id']]['type_key'] ?? '');
            if ($typeKey === 'shopify' && $object['object_key'] === 'order') {
                $shopifyOrder = (int) $object['id'];
            }
            if ($typeKey === 'connect_internal' && $object['object_key'] === 'order_prepared') {
                $prepared = (int) $object['id'];
            }
        }
        if ($shopifyOrder !== null && $prepared !== null) {
            $implicit[] = ['source' => $shopifyOrder, 'target' => $prepared, 'label' => 'Aufbereitung (fest eingebaut)'];
        }

        return [
            'systems' => $outSystems,
            'objects' => $outObjects,
            'templates' => $outTemplates,
            'lookups' => $outLookups,
            'functions' => $outFunctions,
            'implicit' => $implicit,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $templateById
     */
    private static function profileSummary(DocumentProfile $profile, array $templateById): string
    {
        $discount = ['none' => 'kein Rabattfeld', 'percent' => 'nur Prozent', 'absolute' => 'nur Betrag', 'auto' => 'Prozent wenn glatt, sonst Betrag'][$profile->discountMode] ?? $profile->discountMode;
        $parts = [$discount];
        $parts[] = $profile->grossTotalPath !== null ? 'Nach-Kontrolle an' : 'keine Nach-Kontrolle';
        $parts[] = $profile->pdfPath !== null ? 'PDF ' . $profile->pdfPath : 'kein PDF';
        if ($profile->customerObjectKey !== null) {
            $parts[] = 'Kunde über „' . $profile->customerObjectKey . '“'
                . ($profile->customerCreateTemplateId !== null ? ', Neuanlage mit „' . ($templateById[$profile->customerCreateTemplateId]['name'] ?? 'gelöschte Vorlage') . '“' : '');
        }

        return implode(' · ', $parts);
    }

    /**
     * @param list<array<string, mixed>> $ops
     */
    private static function hasOperation(array $ops, string $type): bool
    {
        foreach ($ops as $op) {
            if ($op['operation_type'] === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * Schreibende Operation = alles ausser Lesen. Nicht nur "create": Shopify
     * legt Produkte z.B. ueber "create_product"/"create_variant" an (siehe
     * ProductImportService), HelloCash passt den Bestand ueber "adjust_stock" an.
     *
     * @param list<array<string, mixed>> $ops
     */
    private static function hasWriteOperation(array $ops): bool
    {
        foreach ($ops as $op) {
            if (!in_array($op['operation_type'], ['read_list', 'read_one'], true)) {
                return true;
            }
        }

        return false;
    }

    private static function opLabel(string $type): string
    {
        return ['read_list' => 'Liste', 'read_one' => 'Einzeln', 'create' => 'Anlegen', 'update' => 'Aktualisieren'][$type] ?? $type;
    }

    private static function roleLabel(string $role): string
    {
        return ['sku' => 'SKU', 'title' => 'Titel', 'barcode' => 'Barcode', 'price' => 'Preis (brutto)', 'price_net' => 'Preis netto'][$role] ?? $role;
    }

    private static function functionUse(string $kind): string
    {
        return [
            'import_missing' => 'Button „Importieren“ auf der Produkte-Seite aller passenden Shops',
            'invoice_create' => 'Rechnungstext beim bisherigen Rechnungsweg ohne Vorlage',
        ][$kind] ?? $kind;
    }

    /**
     * @return array<string, mixed>
     */
    private function setting(string $name, string $value, string $src, ?string $where, ?string $url = null, bool $warn = false): array
    {
        return ['name' => $name, 'value' => $value, 'src' => $src, 'where' => $where, 'url' => $url, 'warn' => $warn];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function all(string $sql): array
    {
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}
