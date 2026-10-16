<?php

declare(strict_types=1);

namespace Connect\Faq;

use PDO;

/**
 * FAQ fuer Merchants (Migration 0102, Tabelle connect_faq). Gepflegt in admin/faq.php, angezeigt in
 * app/faq.php. Fehlt die Tabelle (Migration nicht eingespielt), liefert available() false und die
 * Seiten zeigen einen Hinweis statt eines Fehlers.
 */
final class FaqBoard
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function available(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM connect_faq LIMIT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<array{id: int, category: string, question: string, answer: string, sort_order: int, is_published: int, updated_at: ?string}>
     */
    public function all(bool $onlyPublished = false): array
    {
        $rows = $this->pdo->query(
            'SELECT id, category, question, answer, sort_order, is_published, updated_at FROM connect_faq'
            . ($onlyPublished ? ' WHERE is_published = 1' : '')
            . ' ORDER BY sort_order, id'
        )->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int) $row['id'],
                'category' => (string) $row['category'],
                'question' => (string) $row['question'],
                'answer' => (string) $row['answer'],
                'sort_order' => (int) $row['sort_order'],
                'is_published' => (int) $row['is_published'],
                'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
            ];
        }

        return $result;
    }

    /**
     * Gruppiert nach Kategorie, Gruppen in der Reihenfolge ihres ersten Eintrags.
     *
     * @param list<array{id: int, category: string, question: string, answer: string, sort_order: int, is_published: int, updated_at: ?string}> $items
     * @return array<string, list<array{id: int, category: string, question: string, answer: string, sort_order: int, is_published: int, updated_at: ?string}>>
     */
    public static function grouped(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $groups[$item['category']][] = $item;
        }

        return $groups;
    }

    /**
     * Neu anlegen (id 0) oder aendern. Gibt eine Fehlermeldung zurueck oder null bei Erfolg.
     */
    public function save(int $id, string $category, string $question, string $answer, int $sortOrder, bool $published): ?string
    {
        $category = trim($category);
        $question = trim($question);
        $answer = trim($answer);
        if ($category === '' || $question === '' || $answer === '') {
            return 'Bitte Kategorie, Frage und Antwort ausfüllen.';
        }
        if (mb_strlen($category) > 80 || mb_strlen($question) > 300) {
            return 'Kategorie darf höchstens 80, die Frage höchstens 300 Zeichen lang sein.';
        }

        if ($id > 0) {
            $this->pdo->prepare(
                'UPDATE connect_faq SET category = :category, question = :question, answer = :answer,
                        sort_order = :sort_order, is_published = :published, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            )->execute([
                'category' => $category,
                'question' => $question,
                'answer' => $answer,
                'sort_order' => $sortOrder,
                'published' => $published ? 1 : 0,
                'id' => $id,
            ]);

            return null;
        }

        $this->pdo->prepare(
            'INSERT INTO connect_faq (category, question, answer, sort_order, is_published)
             VALUES (:category, :question, :answer, :sort_order, :published)'
        )->execute([
            'category' => $category,
            'question' => $question,
            'answer' => $answer,
            'sort_order' => $sortOrder,
            'published' => $published ? 1 : 0,
        ]);

        return null;
    }

    public function setPublished(int $id, bool $published): void
    {
        $this->pdo->prepare('UPDATE connect_faq SET is_published = :published, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute(['published' => $published ? 1 : 0, 'id' => $id]);
    }

    /** Veroeffentlicht alle Entwuerfe, gibt die Anzahl zurueck. */
    public function publishAllDrafts(): int
    {
        $stmt = $this->pdo->prepare('UPDATE connect_faq SET is_published = 1, updated_at = CURRENT_TIMESTAMP WHERE is_published = 0');
        $stmt->execute();

        return $stmt->rowCount();
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM connect_faq WHERE id = :id')->execute(['id' => $id]);
    }

    /** Naechste freie Sortierzahl (Schritte von 10), fuer neue Eintraege am Ende. */
    public function nextSortOrder(): int
    {
        return ((int) $this->pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM connect_faq')->fetchColumn()) + 10;
    }

    /** @return list<string> vorhandene Kategorien, fuer die Auswahlvorschlaege im Formular */
    public function categories(): array
    {
        return array_map('strval', $this->pdo->query('SELECT DISTINCT category FROM connect_faq ORDER BY category')->fetchAll(PDO::FETCH_COLUMN));
    }
}
