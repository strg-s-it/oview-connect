-- =============================================================================
-- 0102 FAQ fuer Merchants (Connect\Faq\FaqBoard, app/faq.php, admin/faq.php)
--
-- Fragen und Antworten werden im Admin gepflegt und im Merchant-Menue unter "FAQ" gezeigt.
--   category      Ueberschrift der Gruppe (Freitext)
--   answer        Text wie bei den Rechtstexten: Leerzeile = Absatz, "# " = Ueberschrift, https://... = Link
--   sort_order    kleiner = weiter oben, Gruppen ordnen sich nach ihrer kleinsten Zahl (Gleichstand: nach Anlage)
--   is_published  0 = Entwurf (nur im Admin sichtbar), 1 = fuer Merchants sichtbar
--
-- Die Startinhalte unten sind als ENTWURF angelegt: erst im Admin lesen, anpassen, dann freigeben.
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

CREATE TABLE IF NOT EXISTS connect_faq (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category      VARCHAR(80) NOT NULL,
    question      VARCHAR(300) NOT NULL,
    answer        TEXT NOT NULL,
    sort_order    INT NOT NULL DEFAULT 100,
    is_published  TINYINT(1) NOT NULL DEFAULT 0,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_faq_order (sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO connect_faq (category, question, answer, sort_order, is_published) VALUES
('Erste Schritte', 'Was macht oView Connect?',
'oView Connect verbindet deinen Shopify-Shop mit deiner Kasse bzw. deinem Warenwirtschaftssystem (zum Beispiel helloCash oder MGM). Bestellungen, Lagerbestand und Artikeldaten werden automatisch zwischen beiden Systemen abgeglichen, damit du nichts doppelt eintragen musst.

Du richtest die Verbindung einmal ein, danach läuft der Abgleich im Hintergrund.', 10, 0),

('Erste Schritte', 'Was muss ich nach der Installation tun?',
'Beim ersten Öffnen führt dich oView durch die Einrichtung: Software wählen, Zugangsdaten deiner Kasse hinterlegen und die Rechtstexte bestätigen. Danach prüft oView die Verbindung.

Erst wenn die Verbindung auf der Seite Status als in Ordnung angezeigt wird, startet der automatische Abgleich.', 20, 0),

('Erste Schritte', 'Wo sehe ich, ob alles funktioniert?',
'Auf der Seite Status. Dort siehst du jede Verbindung mit dem Ergebnis des letzten Tests, die Sofort-Meldungen von Shopify und den Datenstand. Mit Alle Verbindungen jetzt testen prüfst du alles auf einmal.

Normalerweise musst du dort nichts tun. Bei Fragen an den Support ist diese Seite die erste Anlaufstelle.', 30, 0),

('Lagerbestand', 'Wie funktioniert der Lagerabgleich?',
'oView vergleicht den Bestand deiner Kasse mit dem Bestand in Shopify und gleicht Unterschiede aus. Verkaufst du etwas im Laden, sinkt der Bestand im Shop. Wird im Shop bestellt, sinkt er in der Kasse.

Auf der Seite Lagerbestand siehst du den aktuellen Stand und die Unterschiede.', 10, 0),

('Lagerbestand', 'Warum stimmt ein Bestand nicht sofort überein?',
'Der Abgleich läuft in regelmäßigen Abständen und nicht in Echtzeit. Kurz nach einem Verkauf kann der Bestand deshalb noch einen Moment abweichen. Nach dem nächsten Durchlauf stimmt er wieder.

Bleibt ein Unterschied dauerhaft bestehen, prüfe zuerst, ob das Produkt richtig zugeordnet ist (siehe Produkte).', 20, 0),

('Lagerbestand', 'Was sind Sets und Bundles?',
'Ein Set besteht aus mehreren Artikeln, die zusammen verkauft werden. Wird das Set bestellt, wird der Bestand der enthaltenen Einzelartikel reduziert. Sets legst du auf der Seite Sets und Bundles an.', 30, 0),

('Rechnungen', 'Wie entsteht eine Rechnung?',
'Wenn in Shopify eine Bestellung eingeht, bereitet oView sie auf und legt in deiner Kasse die passende Rechnung an. Vorher prüft oView Steuer und Gesamtbetrag, nachher vergleicht es das Ergebnis noch einmal mit der Bestellung.

Auf der Seite Rechnungen siehst du, welche Bestellungen verarbeitet wurden.', 10, 0),

('Rechnungen', 'Was bedeutet es, wenn eine Rechnung einen Fehler zeigt?',
'Dann hat eine Kontrolle angeschlagen oder die Kasse hat die Rechnung abgelehnt. Typische Gründe sind ein nicht passender Steuersatz oder ein Artikel, der in der Kasse nicht gefunden wurde.

Eine Bestellung wird nie doppelt abgerechnet: oView merkt sich jede Bestellung und legt sie nur einmal an. Eine fehlerhafte Bestellung darf erneut versucht werden, sobald die Ursache behoben ist. Melde dich bei Unklarheiten beim Support und nenne die Bestellnummer.', 20, 0),

('Rechnungen', 'Wie lange speichert oView Bestellungen?',
'Bestellungen bewahrt oView 90 Tage auf. Ältere werden aus Datenschutzgründen gelöscht. Die Rechnungen in deiner Kasse sind davon nicht betroffen, sie bleiben dort nach den Regeln deiner Kasse erhalten.', 30, 0),

('Produkte und Preise', 'Wie ordnet oView Produkte zu?',
'oView vergleicht Shopify-Produkte und Kassenartikel über einen gemeinsamen Vergleichswert, meist die SKU bzw. Artikelnummer. Stimmt dieser Wert in beiden Systemen überein, gelten die Artikel als dasselbe Produkt.

Steht dasselbe Produkt in beiden Systemen unter unterschiedlichen Nummern, kann oView es nicht zuordnen. Dann erscheint es auf der Seite Produkte als fehlend. Gleiche die Nummer in einem der beiden Systeme an.', 10, 0),

('Produkte und Preise', 'Kann ich Preise für viele Artikel auf einmal ändern?',
'Ja, auf der Seite Preise. Du wählst die Artikel aus, legst die Änderung fest (zum Beispiel eine Erhöhung oder einen Sale) und siehst vor dem Anwenden eine Vorschau. Eine Änderung lässt sich rückgängig machen.

Welche Preisfunktionen enthalten sind, hängt von deinem Abo ab.', 20, 0),

('Verbindung und Technik', 'Mein helloCash-Token wird abgelehnt (Insufficient scope). Was tun?',
'Ältere helloCash-Tokens reichen für die aktuelle Schnittstelle (API v2) nicht aus. In diesem Fall erzeugst du in helloCash einen neuen Token und hinterlegst ihn in oView unter Status bei der Verbindung (Schlüssel ändern). Danach auf Verbindung testen klicken.', 10, 0),

('Verbindung und Technik', 'Was muss ich tun, wenn die Verbindung auf Fehler steht?',
'Klicke auf der Seite Status auf Verbindung testen. Die Meldung zeigt meist den Grund, zum Beispiel einen abgelaufenen oder falschen Zugangsschlüssel. Hinterlege dann einen neuen Schlüssel und teste erneut.

Hilft das nicht, schreibe dem Support und kopiere die Fehlermeldung mit.', 20, 0),

('Abo und Datenschutz', 'Wo sehe ich mein Abo und was ist enthalten?',
'Auf der Seite Abo. Dort siehst du dein aktuelles Abo und welche Funktionen in welchem Abo enthalten sind.', 10, 0),

('Abo und Datenschutz', 'Welche Daten speichert oView?',
'oView speichert nur, was für den Abgleich nötig ist: deine Zugangsdaten (verschlüsselt), Produkt- und Bestandsdaten und Bestellungen für 90 Tage. Die Rechtstexte (Datenschutzerklärung und Auftragsverarbeitung) hast du beim ersten Öffnen bestätigt.

Wenn du die App deinstallierst, werden deine Daten nach den Vorgaben von Shopify gelöscht.', 20, 0),

('Abo und Datenschutz', 'Wie kann ich eine Funktion vorschlagen?',
'Auf der Seite Wünsche. Du schreibst deinen Wunsch in einem Satz, wir prüfen ihn kurz und geben ihn dann für alle Händler frei, ohne deinen Shop-Namen. Andere können dafür stimmen. So sehen wir, was vielen wichtig ist. Wird ein Wunsch umgesetzt, bekommst du einen Hinweis in der App.', 30, 0);
