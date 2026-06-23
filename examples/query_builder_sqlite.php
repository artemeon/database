<?php

/*
 * This file is part of the Artemeon Core - Web Application Framework.
 *
 * (c) Artemeon <www.artemeon.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Runnable demo of Connection::createQueryBuilder() backed by an in-memory SQLite database.
 *
 * Usage (from the repo root):
 *
 *     php examples/query_builder_sqlite.php
 *
 * Requires `doctrine/dbal:^4` to be installed (already in require-dev).
 */

use Artemeon\Database\Connection;
use Artemeon\Database\ConnectionParameters;
use Artemeon\Database\DriverFactory;
use Artemeon\Database\Schema\DataType;

require __DIR__ . '/../vendor/autoload.php';

$params = new ConnectionParameters(
    host: 'localhost',
    username: '',
    password: '',
    database: ':memory:',
    port: null,
    driver: 'sqlite3',
);

$connection = new Connection($params, new DriverFactory());

// --- Schema + seed -----------------------------------------------------------

$table = 'demo_books';
$tableReviews = 'demo_reviews';

$connection->dropTable($table);
$connection->dropTable($tableReviews);

$connection->createTable(
    $table,
    [
        'id' => [DataType::CHAR20, false],
        'title' => [DataType::CHAR100, false],
        'author' => [DataType::CHAR100, false],
        'year' => [DataType::INT, true],
    ],
    ['id'],
);

$connection->createTable(
    $tableReviews,
    [
        'id' => [DataType::CHAR20, false],
        'book_id' => [DataType::CHAR20, false],
        'stars' => [DataType::INT, true],
        'comment' => [DataType::CHAR100, true],
    ],
    ['id'],
);

$books = [
    ['id' => 'b-1', 'title' => 'The Pragmatic Programmer', 'author' => 'Andy Hunt', 'year' => 1999],
    ['id' => 'b-2', 'title' => 'Refactoring', 'author' => 'Martin Fowler', 'year' => 1999],
    ['id' => 'b-3', 'title' => 'Domain-Driven Design', 'author' => 'Eric Evans', 'year' => 2003],
    ['id' => 'b-4', 'title' => 'Clean Code', 'author' => 'Robert Martin', 'year' => 2008],
    ['id' => 'b-5', 'title' => 'Designing Data-Intensive Applications', 'author' => 'Martin Kleppmann', 'year' => 2017],
];
foreach ($books as $book) {
    $connection->insert($table, $book);
}

$reviews = [
    ['id' => 'r-1', 'book_id' => 'b-1', 'stars' => 5, 'comment' => 'Essential read'],
    ['id' => 'r-2', 'book_id' => 'b-1', 'stars' => 4, 'comment' => 'Very practical'],
    ['id' => 'r-3', 'book_id' => 'b-3', 'stars' => 5, 'comment' => 'Changed how I think'],
    ['id' => 'r-4', 'book_id' => 'b-4', 'stars' => 3, 'comment' => 'Good but dated'],
    ['id' => 'r-5', 'book_id' => 'b-5', 'stars' => 5, 'comment' => 'Comprehensive'],
];
foreach ($reviews as $review) {
    $connection->insert($tableReviews, $review);
}

// --- Build queries via the Doctrine DBAL QueryBuilder ------------------------

echo "1) SELECT with WHERE + ORDER BY (positional parameter)\n";
echo "------------------------------------------------------\n";
$rows = $connection->createQueryBuilder()
    ->select('title', 'author', 'year')
    ->from($table)
    ->where('year >= ?')
    ->orderBy('year', 'ASC')
    ->setParameter(0, 2000)
    ->executeQuery()
    ->fetchAllAssociative();

foreach ($rows as $row) {
    printf("  %d  %-45s  %s\n", $row['year'], $row['title'], $row['author']);
}

echo "\n2) SELECT with named parameter (DBAL rewrites to positional)\n";
echo "------------------------------------------------------------\n";
$row = $connection->createQueryBuilder()
    ->select('title', 'author')
    ->from($table)
    ->where('id = :id')
    ->setParameter('id', 'b-3')
    ->executeQuery()
    ->fetchAssociative();

printf("  Book b-3 → %s by %s\n", $row['title'], $row['author']);

echo "\n3) UPDATE returning the affected row count\n";
echo "-----------------------------------------\n";
$affected = $connection->createQueryBuilder()
    ->update($table)
    ->set('author', '?')
    ->where('id = ?')
    ->setParameter(0, 'Andy Hunt and Dave Thomas')
    ->setParameter(1, 'b-1')
    ->executeStatement();

printf("  Updated %d row(s).\n", $affected);

$confirmed = $connection->fetchOne(
    'SELECT author FROM ' . $table . ' WHERE id = ?',
    ['b-1'],
);
printf("  New author for b-1: %s\n", $confirmed);

echo "\n4) Aggregate via QueryBuilder\n";
echo "-----------------------------\n";
$count = $connection->createQueryBuilder()
    ->select('COUNT(*)')
    ->from($table)
    ->executeQuery()
    ->fetchOne();

printf("  Total books in table: %d\n", $count);

echo "\n5) Complex WHERE with AND, OR, and IN\n";
echo "---------------------------------------\n";
// Books published before 2000 OR after 2010, but only from a specific id set
$qb = $connection->createQueryBuilder();
$rows = $qb
    ->select('title', 'author', 'year')
    ->from($table)
    ->where(
        $qb->expr()->and(
            $qb->expr()->or(
                $qb->expr()->lt('year', ':cutoff_low'),
                $qb->expr()->gt('year', ':cutoff_high'),
            ),
            $qb->expr()->in('id', [':b1', ':b2', ':b3']),
        ),
    )
    ->setParameter('cutoff_low', 2000)
    ->setParameter('cutoff_high', 2010)
    ->setParameter('b1', 'b-1')
    ->setParameter('b2', 'b-3')
    ->setParameter('b3', 'b-5')
    ->orderBy('year', 'ASC')
    ->executeQuery()
    ->fetchAllAssociative();

foreach ($rows as $row) {
    printf("  %d  %-45s  %s\n", $row['year'], $row['title'], $row['author']);
}

echo "\n6) JOIN books with reviews, filter by minimum rating\n";
echo "------------------------------------------------------\n";
$qb = $connection->createQueryBuilder();
$rows = $qb
    ->select('b.title', 'b.author', 'r.stars', 'r.comment')
    ->from($table, 'b')
    ->innerJoin('b', $tableReviews, 'r', 'r.book_id = b.id')
    ->where($qb->expr()->gte('r.stars', ':min_stars'))
    ->setParameter('min_stars', 5)
    ->orderBy('b.year', 'ASC')
    ->executeQuery()
    ->fetchAllAssociative();

foreach ($rows as $row) {
    printf("  %-45s  %d★  %s\n", $row['title'], $row['stars'], $row['comment']);
}
