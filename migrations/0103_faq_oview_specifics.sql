-- =============================================================================
-- 0103 FAQ: Ergaenzung mit oView-Besonderheiten (Fortsetzung von 0102)
--
-- Inhalte teils aus der FAQ der alten oView-App uebernommen (sinngemaess, eigene Formulierung) und
-- gegen Connect geprueft (Abos und Intervalle: Billing\Plans, Lagerrichtung, Vergleichswert).
-- Wie in 0102 als ENTWURF angelegt: im Admin unter FAQ lesen, anpassen, dann freigeben.
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

INSERT INTO connect_faq (category, question, answer, sort_order, is_published) VALUES
('Lagerbestand', 'Welches System führt den Lagerbestand?',
'Das legst du in den Einstellungen fest: Entweder gilt der Bestand deiner Kasse und wird nach Shopify übertragen, oder der Bestand in Shopify gilt und wird in die Kasse übertragen. Das führende System ist immer die Quelle, das andere System wird angeglichen.

Wähle das System, in dem du Bestände tatsächlich pflegst, zum Beispiel beim Wareneingang. Die Richtung kannst du später ändern.', 40, 0),

('Lagerbestand', 'Wie oft wird der Lagerbestand abgeglichen?',
'Das hängt von deinem Abo ab:

OVIEW HELLOCASH: alle 60 Minuten
OVIEW HELLOCASH +: alle 30 Minuten
OVIEW HELLOCASH ++: alle 15 Minuten

Häufiger geht es nicht, weil Shopify die Menge automatischer Abfragen begrenzt. Ein Wechsel des Abos kann jederzeit unter Abo erfolgen.', 50, 0),

('Lagerbestand', 'Warum wurde der Lagerabgleich ausgelassen oder angehalten?',
'oView hat eine Sicherheitsbremse. Liefert die führende Quelle plötzlich auffällige Daten, zum Beispiel eine deutlich kürzere Artikelliste oder auf einmal sehr viele Bestände mit null, wird der Abgleich ausgelassen. So verhindert oView, dass ein technischer Fehler in der Kasse deine Bestände im Shop überschreibt.

Tritt es mehrmals hintereinander auf, wird der Abgleich angehalten und das oView-Team prüft es. Wenn du den Verdacht hast, dass die Bestände wirklich stimmen, schreib uns an office@oview.at.', 60, 0),

('Lagerbestand', 'Warum wird ein einzelnes Produkt nicht abgeglichen?',
'Fast immer liegt es am Vergleichswert. Fehlt SKU bzw. Barcode in einem der beiden Systeme, oder ist der Wert mehrfach vergeben, kann oView das Produkt nicht eindeutig zuordnen und lässt es aus. Betroffene Produkte findest du auf der Seite Produkte. Dort siehst du auch, was zu korrigieren ist.', 70, 0),

('Produkte und Preise', 'Welcher Vergleichswert ist der beste?',
'Am besten SKU oder Barcode (EAN), denn beide sollten pro Produkt eindeutig sein. Der Titel ist unsicherer: Er ändert sich leicht und kommt gelegentlich doppelt vor.

Den Vergleichswert stellst du in den Einstellungen ein. Wichtig ist, dass in beiden Systemen derselbe Wert hinterlegt ist, zum Beispiel dieselbe Artikelnummer.', 30, 0),

('Produkte und Preise', 'Wie funktionieren Shopify-Varianten mit meiner Kasse?',
'Die Kasse kennt keine Varianten. Jede Variante (zum Beispiel jede Größe oder Farbe) muss dort als eigener Artikel angelegt sein. oView verbindet Variante und Artikel über SKU oder Barcode. Hat jede Variante in Shopify eine eigene SKU bzw. einen eigenen Barcode, ordnet oView sie einzeln zu.', 40, 0),

('Produkte und Preise', 'Warum sehe ich beim Import keine Produkte?',
'Dafür gibt es drei häufige Gründe: Die Produkte sind bereits verknüpft, es fehlt der Vergleichswert (SKU bzw. Barcode) oder der Wert ist mehrfach vergeben. Auf der Seite Produkte siehst du, welche Produkte verknüpft sind und wo Probleme bestehen.', 50, 0),

('Produkte und Preise', 'Was zeigt mir die Seite Produkte?',
'Dort vergleicht oView deinen Shop mit der Kasse und zeigt Unterschiede, zum Beispiel bei Preisen, Barcodes oder SKUs. Du siehst, welche Produkte verknüpft sind, welche nur in einem System vorkommen und wo es Probleme gibt. Fehlende Produkte kannst du von dort übernehmen.', 60, 0),

('Rechnungen', 'Wer erstellt die Rechnung, oView oder die Kasse?',
'Die Rechnung erstellt deine Kasse, bei helloCash also helloCash selbst. oView übergibt nur die Daten der Shopify-Bestellung an die Kasse. Für die gesetzlichen Vorgaben (zum Beispiel RKSV in Österreich) ist deshalb die Kasse zuständig. Das Dokument aus der Kasse ist das Original, Kopien in anderen Formaten sind nur Abschriften.', 40, 0),

('Rechnungen', 'Legt oView Shopify-Bestellungen an, wenn ich im Laden verkaufe?',
'Nein. oView überträgt Bestellungen von Shopify in die Kasse, nicht umgekehrt. Ein Verkauf im Laden verringert aber den Bestand, und der wird mit Shopify abgeglichen (siehe Lagerbestand).', 50, 0),

('Rechnungen', 'Wer bekommt die Rechnung per E-Mail und von welcher Adresse?',
'Im Abo OVIEW HELLOCASH wird die Rechnungsmail über die Absenderadresse von oView verschickt. Ab OVIEW HELLOCASH + kannst du deine eigene SMTP-Adresse hinterlegen, dann kommt die Mail von deiner Domain. Anhänge an der Rechnungsmail gibt es ebenfalls ab OVIEW HELLOCASH +.', 60, 0),

('Abo und Datenschutz', 'Was kosten die Abos?',
'OVIEW HELLOCASH kostet 19,90 USD, OVIEW HELLOCASH + 25,90 USD und OVIEW HELLOCASH ++ 35,90 USD pro Monat. Der Unterschied liegt vor allem im Abgleich-Intervall (60, 30 oder 15 Minuten) und in den Zusatzfunktionen: Preise abgleichen, Sets, Anhänge an der Rechnungsmail, eigene SMTP-Adresse und der vollständige Produkt-Check gibt es ab OVIEW HELLOCASH +.

Das erste Abo startet mit 14 Tagen Testzeit. Alle Details siehst du auf der Seite Abo.', 40, 0),

('Abo und Datenschutz', 'Wie erreiche ich den Support?',
'Schriftlich per E-Mail an office@oview.at oder per Chat. Bitte nenne deinen Shop und, wenn es um eine Rechnung geht, die Bestellnummer. Eine Fehlermeldung aus der Seite Status hilft uns, schneller zu antworten.', 50, 0);
