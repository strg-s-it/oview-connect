-- =============================================================================
-- connect/migrations/0081_connector_type_outbound_ip.sql
--
-- Feste Absender-IP je System (Connector Type). Fuer Systeme mit IP-Freigabe beim
-- Kunden (z.B. MGM/AGILA bei crystalpjewellery: nur die IP von main ist freigegeben,
-- Anfragen der Worker laufen in eine Zeitueberschreitung). Ist hier eine IP gesetzt,
-- gehen ALLE Anfragen an dieses System von dieser IP hinaus -- egal auf welchem Worker
-- der Job laeuft (CURLOPT_INTERFACE, siehe Connect\Http\HttpClient::withOutboundIp()).
-- Funktioniert, weil main und die Worker auf demselben Server liegen und jede dieser IPs
-- lokal gebunden werden kann. Leer = jeder Worker nutzt seine eigene IP (CONNECT_OUTBOUND_IP).
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

ALTER TABLE connect_connector_types
    ADD COLUMN outbound_ip VARCHAR(45) NULL COMMENT 'Feste Absender-IP fuer alle Anfragen an dieses System, NULL = IP des jeweiligen Workers';
