<?php
require_once __DIR__ . '/../../src/config.php';

try {
    // Many-to-many link between users and partners. Replaces the single
    // users.partner_id column so one user can access everything belonging to
    // several partners at once. users.partner_id is kept (and mirrored with the
    // first linked partner) so older code paths keep working.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_partners (
            user_id INT NOT NULL,
            partner_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, partner_id),
            INDEX idx_user_partners_partner (partner_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    echo "Table 'user_partners' created successfully.\n";

    // Backfill from the legacy single-partner column.
    $stmt = $pdo->exec("
        INSERT IGNORE INTO user_partners (user_id, partner_id)
        SELECT u.id, u.partner_id
        FROM users u
        JOIN partners p ON p.id = u.partner_id
        WHERE u.partner_id IS NOT NULL
    ");

    echo "Backfilled $stmt existing user/partner link(s).\n";

} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage());
}
