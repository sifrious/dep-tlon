<?php
$path = $argv[1];
@unlink($path);
$pdo = new PDO('sqlite:' . $path);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach ([
    'CREATE TABLE titles (id INTEGER PRIMARY KEY, name TEXT NOT NULL, isbn TEXT)',
    'CREATE TABLE copies (id INTEGER PRIMARY KEY, title_id INTEGER NOT NULL REFERENCES titles(id) ON DELETE CASCADE, shelf TEXT DEFAULT \'main\')',
    'CREATE TABLE borrowers (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT)',
    'CREATE TABLE loans (copy_id INTEGER NOT NULL REFERENCES copies(id), borrower_id INTEGER NOT NULL REFERENCES borrowers(id), taken_on TEXT NOT NULL, PRIMARY KEY (copy_id, borrower_id))',
    'CREATE UNIQUE INDEX borrowers_email ON borrowers(email)',
    'CREATE VIEW on_loan AS SELECT copy_id, borrower_id FROM loans',
] as $sql) {
    $pdo->exec($sql);
}
echo "fixture built at $path\n";
