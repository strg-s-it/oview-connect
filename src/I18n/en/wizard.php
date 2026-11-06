<?php

declare(strict_types=1);

/**
 * Englisch: Startassistent (app/welcome.php), Rechtstext-Beschriftungen, Schrittnamen.
 *
 * @return array<string, string>
 */
return [
    'Einrichtung wird vorbereitet' => 'Setup is being prepared',
    'oView Connect wird gerade aktualisiert. Bitte versuche es in ein paar Minuten noch einmal.' => 'oView Connect is being updated. Please try again in a few minutes.',
    'Bitte noch zustimmen:' => 'Please agree to:',
    'Bitte wähle deine Software aus.' => 'Please choose your software.',
    'Die Verbindung hat noch nicht geklappt. Bitte prüfe den Schlüssel und versuche es noch einmal.' => 'The connection did not work yet. Please check the key and try again.',
    'Bitte wähle aus, woran wir deine Produkte erkennen sollen.' => 'Please choose how we should match your products.',
    'Bitte wähle Ja oder Nein.' => 'Please choose Yes or No.',
    'Bitte wähle, wo du deine Lagerbestände änderst.' => 'Please choose where you change your stock levels.',
    'Die Shopify-Standorte werden gerade geladen. Bitte gleich noch einmal auf „Weiter“ klicken.' => 'Your Shopify locations are being loaded. Please click “Next” again in a moment.',
    'Bitte wähle den Shopify-Standort.' => 'Please choose the Shopify location.',
    'Bitte wähle, was bei einer bezahlten Bestellung passieren soll.' => 'Please choose what should happen when an order is paid.',
    'Bitte wähle Brutto oder Netto.' => 'Please choose gross or net.',
    'Deine Änderungen sind übernommen.' => 'Your changes have been applied.',
    'oView ist gestartet. Der erste Abgleich läuft in den nächsten Minuten.' => 'oView has started. The first sync runs within the next few minutes.',
    'Aber:' => 'But:',
    'deiner Software' => 'your software',
    'Willkommen' => 'Welcome',
    'Abbrechen, nichts ändern' => 'Cancel, change nothing',
    'Schritt {n} von {m}' => 'Step {n} of {m}',

    // Abschnitte (SetupWizard::PHASES)
    'Verbinden' => 'Connect',
    'Fertig' => 'Done',

    // Rechtstexte (LegalTexts::DOCS)
    'Nutzungsbedingungen' => 'Terms of use',
    'Datenschutzerklärung' => 'Privacy policy',
    'Auftragsverarbeitung (AVV)' => 'Data processing agreement (DPA)',
    'Verantwortung und Haftung für Belege' => 'Responsibility and liability for documents',
    'Ich akzeptiere die Nutzungsbedingungen.' => 'I accept the terms of use.',
    'Ich habe die Datenschutzerklärung gelesen und akzeptiere sie.' => 'I have read and accept the privacy policy.',
    'Ich akzeptiere die Vereinbarung zur Auftragsverarbeitung.' => 'I accept the data processing agreement.',
    'Ich stimme den Bedingungen zu Verantwortung und Haftung für erstellte Belege zu.' => 'I agree to the terms on responsibility and liability for generated documents.',
    '{title} lesen' => 'Read {title}',

    // welcome
    'Unsere Bedingungen wurden aktualisiert' => 'Our terms have been updated',
    'Bitte lies die geänderten Texte und stimme zu, dann geht es weiter.' => 'Please read the updated texts and agree to continue.',
    'Willkommen bei {name}' => 'Welcome to {name}',
    'oView hält deinen Shopify-Shop und deine Kasse oder Warenwirtschaft automatisch abgestimmt: Lagerbestände, Rechnungen und Produkte. Die Einrichtung dauert etwa drei Minuten, du beantwortest nur ein paar Fragen.' => 'oView keeps your Shopify shop and your POS or ERP system in sync automatically: stock levels, invoices and products. Setup takes about three minutes, you only answer a few questions.',

    // software
    'Welche Software nutzt du neben Shopify?' => 'Which software do you use besides Shopify?',
    'Mit dieser Software gleicht oView deinen Shop ab.' => 'oView syncs your shop with this software.',
    'Noch keine Software verfügbar. Bitte schreib uns an {mail}.' => 'No software available yet. Please write to us at {mail}.',
    'Deine Software ist nicht dabei? Schreib uns an {mail}, welche du nutzt.' => 'Your software is not listed? Write to us at {mail} and tell us which one you use.',

    // connect
    '{name} verbinden' => 'Connect {name}',
    'oView braucht deine Zugangsdaten, um Artikel und Lagerstände aus {name} zu lesen und Rechnungen anzulegen.' => 'oView needs your credentials to read items and stock levels from {name} and to create invoices.',
    'oView braucht deine Zugangsdaten, um Artikel und Lagerstände aus {name} zu lesen.' => 'oView needs your credentials to read items and stock levels from {name}.',
    'Bitte wähle zuerst deine Software.' => 'Please choose your software first.',
    'Zur Auswahl' => 'Go to selection',
    'Verbunden mit {name}. Neue Zugangsdaten kannst du unten eintragen, sonst einfach weiter.' => 'Connected to {name}. You can enter new credentials below, otherwise just continue.',
    'Wo finde ich die Zugangsdaten für {name}?' => 'Where do I find the credentials for {name}?',
    'Klappt es nicht? Du kannst auch {button} und die Verbindung später unter Status prüfen.' => 'Not working? You can also {button} and check the connection later under Status.',
    'ohne erfolgreichen Test weitermachen' => 'continue without a successful test',

    // compare
    'Woran erkennen wir, welche Produkte zusammengehören?' => 'How do we recognise which products belong together?',
    'Wir haben deine Shopify-Produkte mit den Artikeln in {name} verglichen. So viele passen jeweils zusammen:' => 'We compared your Shopify products with the items in {name}. This many match each time:',
    'Wir laden gerade deine Artikel aus {name}. Sobald sie da sind, siehst du hier, wie viele zusammenpassen. Du kannst aber schon weitermachen.' => 'We are loading your items from {name}. Once they are here you will see how many match. You can already continue.',
    '{a} von {b} Artikeln gefunden' => '{a} of {b} items found',
    'Die Artikelnummer, die du in beiden Systemen pflegst.' => 'The item number you maintain in both systems.',
    'Der Strichcode auf der Verpackung.' => 'The barcode on the packaging.',
    'Nur wenn die Namen überall exakt gleich sind.' => 'Only if the names are exactly the same everywhere.',
    'Artikelnummer (SKU)' => 'Item number (SKU)',
    'Barcode (EAN)' => 'Barcode (EAN)',
    'Produktname' => 'Product name',
    'Empfohlen' => 'Recommended',
    'Artikel ohne Gegenstück siehst du später unter „Produkte“ und kannst sie dort anlegen.' => 'Items without a counterpart appear later under “Products”, where you can create them.',

    // stock
    'Sollen die Lagerbestände automatisch abgeglichen werden?' => 'Should stock levels be synced automatically?',
    'Dann stimmen die Stückzahlen in Shopify und {name} überein, ohne dass du sie doppelt pflegst.' => 'Then quantities in Shopify and {name} match without you maintaining them twice.',
    'Ja, automatisch abgleichen' => 'Yes, sync automatically',
    'oView prüft die Bestände jede Stunde.' => 'oView checks stock levels every hour.',
    'oView prüft die Bestände alle {n} Minuten.' => 'oView checks stock levels every {n} minutes.',
    'Nein, jetzt nicht' => 'No, not now',
    'Du kannst das jederzeit in den Einstellungen einschalten.' => 'You can turn this on at any time in the settings.',

    // stock_lead
    'Wo änderst du deine Lagerbestände?' => 'Where do you change your stock levels?',
    'Dort, wo du Wareneingänge und Korrekturen einträgst. Das andere System übernimmt die Zahlen.' => 'Where you enter goods receipts and corrections. The other system takes over the numbers.',
    'In {name}' => 'In {name}',
    'Shopify übernimmt die Bestände aus {name}.' => 'Shopify takes the stock levels from {name}.',
    'In Shopify' => 'In Shopify',
    '{name} übernimmt die Bestände aus Shopify.' => '{name} takes the stock levels from Shopify.',
    'Die Standorte deines Shops werden gerade aus Shopify geladen. Das dauert nur einen Moment, danach kannst du hier den Standort wählen.' => 'Your shop’s locations are being loaded from Shopify. This only takes a moment, then you can choose the location here.',
    'Dein Shop hat einen Standort: {name}. Abgeglichen wird dessen Bestand.' => 'Your shop has one location: {name}. Its stock level will be synced.',
    'Für welchen Shopify-Standort?' => 'For which Shopify location?',
    'Bitte wählen' => 'Please choose',
    'Dein Shop hat {n} Standorte. Abgeglichen wird der Bestand dieses Standorts.' => 'Your shop has {n} locations. The stock level of this location will be synced.',

    // invoice
    'Was soll passieren, wenn eine Bestellung bezahlt ist?' => 'What should happen when an order is paid?',
    'Rechnung in {name} erstellen' => 'Create invoice in {name}',
    'Die Rechnung erscheint in {name}, der Bestand wird dort abgebucht.' => 'The invoice appears in {name} and the stock is deducted there.',
    'Häufigste Wahl' => 'Most common choice',
    'Nur den Lagerbestand abziehen' => 'Only deduct stock',
    'Keine Rechnung in {name}, nur die verkauften Stück werden abgebucht.' => 'No invoice in {name}, only the units sold are deducted.',
    'Nichts' => 'Nothing',
    'Bestellungen bleiben nur in Shopify.' => 'Orders stay in Shopify only.',
    'Rechnung als PDF auch per E-Mail an den Kunden schicken' => 'Also send the invoice as a PDF by email to the customer',
    'Absender, Betreff und Text kannst du danach in den Einstellungen anpassen.' => 'You can adjust sender, subject and text afterwards in the settings.',

    // prices
    'Sind deine Shopify-Preise inklusive Mehrwertsteuer?' => 'Do your Shopify prices include VAT?',
    'Laut deinem Shop:' => 'According to your shop:',
    'ja, inklusive MwSt.' => 'yes, including VAT',
    'nein, ohne MwSt.' => 'no, excluding VAT',
    'Das ist wichtig, damit die Beträge auf der Rechnung stimmen.' => 'This matters so the amounts on the invoice are correct.',
    'Ja, inklusive MwSt. (Brutto)' => 'Yes, including VAT (gross)',
    'So rechnen die meisten Shops.' => 'Most shops work this way.',
    'Aus Shopify' => 'From Shopify',
    'Nein, ohne MwSt. (Netto)' => 'No, excluding VAT (net)',
    'Die Mehrwertsteuer kommt an der Kasse dazu.' => 'VAT is added at checkout.',

    // done
    'Standort fehlt' => 'Location missing',
    'Verbunden mit' => 'Connected to',
    'Produkte erkennen an' => 'Match products by',
    'wird in {name} gepflegt' => 'maintained in {name}',
    'kein Abgleich' => 'no sync',
    'Bezahlte Bestellung' => 'Paid order',
    'Rechnung in {name}' => 'Invoice in {name}',
    'E-Mail an Kunden' => 'email to customer',
    'nur Bestand abziehen' => 'only deduct stock',
    'nichts' => 'nothing',
    'Preise in Shopify' => 'Prices in Shopify',
    'inkl. MwSt.' => 'incl. VAT',
    'ohne MwSt.' => 'excl. VAT',
    'Deine Einstellungen' => 'Your settings',
    'Alles bereit' => 'All set',
    'So arbeitet oView nach dem Übernehmen für dich.' => 'This is how oView will work for you after applying.',
    'So arbeitet oView ab jetzt für dich. Du kannst alles später in den Einstellungen ändern.' => 'This is how oView will work for you from now on. You can change everything later in the settings.',
    'ändern' => 'change',
    'Der erste Abgleich startet sofort und dauert ein paar Minuten. Bis dahin zeigt dir die Übersicht, was gerade passiert.' => 'The first sync starts right away and takes a few minutes. Until then the overview shows what is happening.',

    // Navigation
    'Zurück' => 'Back',
    'Zustimmen und weiter' => 'Agree and continue',
    'Los geht’s' => 'Let’s go',
    'Verbindung prüfen' => 'Check connection',
    'Übernehmen' => 'Apply',
    'oView starten' => 'Start oView',
    'Weiter' => 'Next',
    'Neu verbinden' => 'Reconnect',
];
