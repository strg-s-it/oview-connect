-- =============================================================================
-- 0099 Anleitung "Wo finde ich die Zugangsdaten?" je Connector Type
--
-- Wird im Einrichtungs-Assistenten (app/welcome.php, Schritt "Verbinden") aufklappbar
-- angezeigt. Ein Schritt je Zeile, **fett** erlaubt. Pflege im Admin am Connector Type.
-- Fuer helloCash wird ein Vorschlag eingetragen, solange noch nichts hinterlegt ist.
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

ALTER TABLE connect_connector_types
    ADD COLUMN setup_help TEXT NULL
        COMMENT 'Anleitung fuer den Haendler: wo findet man die Zugangsdaten (ein Schritt je Zeile)';

UPDATE connect_connector_types
   SET setup_help = CONCAT(
        'Melde dich bei **helloCash** an (myhellocash.com) – am besten in einem zweiten Tab, damit du hier nichts verlierst.', CHAR(10),
        'Öffne im Menü links **Registrierkasse → Einstellungen** und dort in der Liste den Punkt **helloCash API**.', CHAR(10),
        'Klicke rechts oben auf **Token erstellen**. Gibt es schon einen Token, steht in der Tabelle stattdessen **Neu generieren** – der bisherige Token funktioniert dann nicht mehr.', CHAR(10),
        'Wähle bei den Berechtigungen **Alle Scopes** (Lesen und Schreiben).', CHAR(10),
        'Kopiere den Token **sofort** und füge ihn unten ein. In der Tabelle siehst du später nur eine gekürzte Vorschau davon.')
 WHERE type_key = 'hellocash' AND setup_help IS NULL;
