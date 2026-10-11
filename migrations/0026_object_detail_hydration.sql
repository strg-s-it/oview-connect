-- =============================================================================
-- connect/migrations/0026_object_detail_hydration.sql
--
-- Manche Objects liefern nicht alle Felder in ihrer read_list-Antwort (z.B.
-- HelloCash "Artikel": der Lagerbestand "stocks" steht nur in der
-- Einzel-Antwort "GET /articles/{id}", nicht in der Liste -- HelloCash hat
-- weder ein Bulk- noch ein Webhook-Verfahren dafuer). hydrate_details_after_list
-- schaltet das Nachladen ein: nach jedem Listen-Sync (SyncRunner::run(),
-- Cache-Zweig) wird zusaetzlich ein begrenzter Stapel bereits gecachter
-- Datensaetze per read_one aktualisiert (siehe ObjectCache::hydrateDetails()).
-- Bewusst am OBJECT (nicht an der Mapping-Aktivierung) -- gilt global fuer
-- jeden Shop, der dieses Object synchronisiert, genau wie Felder/Operationen
-- selbst auch global sind.
-- =============================================================================

ALTER TABLE connect_objects
    ADD COLUMN hydrate_details_after_list TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Nach jedem Listen-Sync zusaetzlich einen Stapel Datensaetze per read_one aktualisieren (siehe ObjectCache::hydrateDetails())' AFTER settings_schema,
    ADD COLUMN hydrate_batch_size INT UNSIGNED NULL
        COMMENT 'Anzahl Datensaetze pro Lauf fuer das Detail-Nachladen, NULL = Standard (siehe SyncRunner::DEFAULT_HYDRATE_BATCH_SIZE)' AFTER hydrate_details_after_list;
