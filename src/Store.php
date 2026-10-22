<?php
declare(strict_types=1);
namespace Tesco;

final class Store
{
    private \PDO $db;

    public function __construct(string $path)
    {
        $this->db = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA busy_timeout=5000');
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('CREATE TABLE IF NOT EXISTS cache (key TEXT PRIMARY KEY, payload TEXT NOT NULL, expires INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS watchlist (product_id TEXT PRIMARY KEY, title TEXT NOT NULL, added_at TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id TEXT NOT NULL, observed_at TEXT NOT NULL, payload TEXT NOT NULL);
            CREATE INDEX IF NOT EXISTS snapshots_product_time ON snapshots(product_id, id DESC)');
    }

    public function cached(string $key, int $now): ?array
    {
        $stmt = $this->db->prepare('SELECT payload FROM cache WHERE key = ? AND expires > ?');
        $stmt->execute([$key, $now]);
        $payload = $stmt->fetchColumn();
        return $payload === false ? null : json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
    }

    public function cache(string $key, array $payload, int $expires): void
    {
        $stmt = $this->db->prepare('INSERT OR REPLACE INTO cache VALUES (?, ?, ?)');
        $stmt->execute([$key, json_encode($payload, JSON_THROW_ON_ERROR), $expires]);
        $this->db->exec('DELETE FROM cache WHERE expires <= ' . time());
    }

    public function watch(array $product): void
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $this->db->prepare('SELECT 1 FROM watchlist WHERE product_id = ?');
            $stmt->execute([$product['id']]);
            if (!$stmt->fetchColumn() && (int) $this->db->query('SELECT COUNT(*) FROM watchlist')->fetchColumn() >= 100) {
                throw new ApiError('watchlist_full', 'The watchlist holds at most 100 products.', 422);
            }
            $stmt = $this->db->prepare('INSERT INTO watchlist VALUES (?, ?, ?) ON CONFLICT(product_id) DO UPDATE SET title=excluded.title');
            $stmt->execute([$product['id'], $product['title'], gmdate('Y-m-d\TH:i:s\Z')]);
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    public function unwatch(string $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM watchlist WHERE product_id = ?');
        $stmt->execute([$id]);
    }

    public function watchlist(): array
    {
        return $this->db->query('SELECT * FROM watchlist ORDER BY added_at, product_id')->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function snapshot(array $product): void
    {
        $stmt = $this->db->prepare('INSERT INTO snapshots(product_id, observed_at, payload) VALUES (?, ?, ?)');
        $stmt->execute([$product['id'], $product['observed_at'], json_encode($product, JSON_THROW_ON_ERROR)]);
    }

    public function history(string $id, int $limit = 100): array
    {
        $stmt = $this->db->prepare('SELECT payload FROM snapshots WHERE product_id = :id ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return array_map(static fn (string $json): array => json_decode($json, true, 64, JSON_THROW_ON_ERROR), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
