<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\QueryCachingOpportunityAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for QueryCachingOpportunityAnalyzer.
 *
 * This analyzer detects two types of caching opportunities:
 * 1. Frequent queries - queries executed 3+ times in the same request
 * 2. Static table queries - queries on rarely-changing lookup tables
 */
final class QueryCachingOpportunityAnalyzerTest extends TestCase
{
    private QueryCachingOpportunityAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new QueryCachingOpportunityAnalyzer(
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_detects_frequent_query_at_info_threshold(): void
    {
        // Arrange: Same query executed 3 times (minimum threshold)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 10.0)
            ->addQuery('SELECT * FROM users WHERE id = 2', 10.0)
            ->addQuery('SELECT * FROM users WHERE id = 3', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect as INFO severity
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray, 'Should detect frequent query');
        self::assertStringContainsString('3 Times', $issuesArray[0]->getTitle());
    }

    #[Test]
    public function it_detects_frequent_query_at_warning_threshold(): void
    {
        // Arrange: Same query executed 5 times (warning threshold)
        $queries = QueryDataBuilder::create();
        for ($i = 1; $i <= 5; $i++) {
            $queries->addQuery("SELECT * FROM users WHERE id = {$i}", 10.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should detect as WARNING severity
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('5 Times', $issuesArray[0]->getTitle());
    }

    #[Test]
    public function it_detects_frequent_query_at_critical_threshold(): void
    {
        // Arrange: Same query executed 10 times (critical threshold)
        $queries = QueryDataBuilder::create();
        for ($i = 1; $i <= 10; $i++) {
            $queries->addQuery("SELECT * FROM products WHERE id = {$i}", 10.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should detect as CRITICAL severity
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('10 Times', $issuesArray[0]->getTitle());
        self::assertEquals('critical', $issuesArray[0]->getSeverity()->value);
    }

    #[Test]
    public function it_does_not_detect_queries_below_threshold(): void
    {
        // Arrange: Same query executed only 2 times (below threshold of 3)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 10.0)
            ->addQuery('SELECT * FROM users WHERE id = 2', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT detect (below threshold)
        self::assertCount(0, $issues, 'Should not detect queries below threshold');
    }

    #[Test]
    public function it_normalizes_queries_with_different_values(): void
    {
        // Arrange: Same query structure with different values
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM users WHERE id = 1", 10.0)
            ->addQuery("SELECT * FROM users WHERE id = 2", 10.0)
            ->addQuery("SELECT * FROM users WHERE id = 3", 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should treat as same query pattern
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray, 'Should normalize and detect pattern');
    }

    #[Test]
    public function it_normalizes_queries_with_string_literals(): void
    {
        // Arrange: Same query with different string literals
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM users WHERE name = 'Alice'", 10.0)
            ->addQuery("SELECT * FROM users WHERE name = 'Bob'", 10.0)
            ->addQuery("SELECT * FROM users WHERE name = 'Charlie'", 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should treat as same query pattern
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
    }

    #[Test]
    public function it_normalizes_queries_with_in_clauses(): void
    {
        // Arrange: Same query with different IN clause values
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM users WHERE id IN (1, 2, 3)", 10.0)
            ->addQuery("SELECT * FROM users WHERE id IN (4, 5, 6)", 10.0)
            ->addQuery("SELECT * FROM users WHERE id IN (7, 8, 9)", 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should treat as same query pattern
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
    }

    #[Test]
    public function it_detects_static_table_countries(): void
    {
        // Arrange: Query on 'countries' table (static table)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM countries WHERE code = ?', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect static table caching opportunity
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray, 'Should detect static table');
        self::assertStringContainsString('countries', strtolower($issuesArray[0]->getTitle()));
    }

    #[Test]
    public function it_detects_static_table_currencies(): void
    {
        // Arrange: Query on 'currencies' table
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM currencies WHERE code = ?', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect static table
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('currencies', strtolower($issuesArray[0]->getTitle()));
    }

    #[Test]
    public function it_detects_static_table_languages(): void
    {
        // Arrange: Query on 'languages' table
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM languages ORDER BY name', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect static table
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('languages', strtolower($issuesArray[0]->getTitle()));
    }

    #[Test]
    public function it_detects_static_table_with_join(): void
    {
        // Arrange: Query joining static table
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users JOIN countries ON users.country_id = countries.id', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect static table in JOIN
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('countries', strtolower($issuesArray[0]->getTitle()));
    }

    #[Test]
    public function it_reports_static_table_only_once(): void
    {
        // Arrange: Multiple queries on same static table
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM countries WHERE id = 1', 10.0)
            ->addQuery('SELECT * FROM countries WHERE id = 2', 10.0)
            ->addQuery('SELECT * FROM countries WHERE id = 3', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should report static table only once + frequent query once = 2 issues
        $issuesArray = $issues->toArray();
        self::assertCount(2, $issuesArray, 'Static table reported once, frequent query once');
    }

    #[Test]
    public function it_detects_both_frequent_and_static_opportunities(): void
    {
        // Arrange: Mix of frequent queries and static table queries
        $queries = QueryDataBuilder::create();

        // Frequent query on regular table
        for ($i = 1; $i <= 5; $i++) {
            $queries->addQuery("SELECT * FROM orders WHERE id = {$i}", 10.0);
        }

        // Query on static table
        $queries->addQuery('SELECT * FROM countries WHERE code = ?', 10.0);

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should detect both types
        $issuesArray = $issues->toArray();
        self::assertCount(2, $issuesArray, 'Should detect both frequent and static opportunities');
    }

    #[Test]
    public function it_calculates_total_time(): void
    {
        // Arrange: Same query executed 3 times with different execution times
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 10.0)
            ->addQuery('SELECT * FROM users WHERE id = 2', 15.0)
            ->addQuery('SELECT * FROM users WHERE id = 3', 20.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should calculate total time (45ms)
        $issuesArray = $issues->toArray();
        $description = $issuesArray[0]->getDescription();
        self::assertStringContainsString('45', $description, 'Should show total time');
    }

    #[Test]
    public function it_mentions_use_result_cache_in_suggestion(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create();
        for ($i = 1; $i <= 3; $i++) {
            $queries->addQuery("SELECT * FROM products WHERE id = {$i}", 10.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should suggest useResultCache()
        $issuesArray = $issues->toArray();
        $description = $issuesArray[0]->getDescription();
        self::assertStringContainsString('useResultCache', $description);
    }

    #[Test]
    public function it_provides_cache_duration_hint_for_static_tables(): void
    {
        // Arrange: Query on static table
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM countries', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should mention cache duration
        $issuesArray = $issues->toArray();
        $description = $issuesArray[0]->getDescription();
        self::assertStringContainsString('hour', strtolower($description));
    }

    #[Test]
    public function it_includes_backtrace(): void
    {
        // Arrange: Query with backtrace
        $queries = QueryDataBuilder::create();
        for ($i = 1; $i <= 3; $i++) {
            $queries->addQueryWithBacktrace(
                "SELECT * FROM users WHERE id = {$i}",
                [['file' => 'UserRepository.php', 'line' => 42]],
                10.0,
            );
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should include backtrace
        $issuesArray = $issues->toArray();
        $backtrace = $issuesArray[0]->getBacktrace();

        self::assertNotNull($backtrace);
        self::assertIsArray($backtrace);
        self::assertEquals('UserRepository.php', $backtrace[0]['file']);
    }

    #[Test]
    public function it_handles_empty_query_collection(): void
    {
        // Arrange: Empty collection
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should return empty collection
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_has_performance_category(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create();
        for ($i = 1; $i <= 3; $i++) {
            $queries->addQuery("SELECT * FROM users WHERE id = {$i}", 10.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert
        $issuesArray = $issues->toArray();
        self::assertEquals('performance', $issuesArray[0]->getCategory());
    }

    #[Test]
    public function it_provides_suggestion_for_frequent_queries(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create();
        for ($i = 1; $i <= 3; $i++) {
            $queries->addQuery("SELECT * FROM users WHERE id = {$i}", 10.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should provide suggestion
        $issuesArray = $issues->toArray();
        $suggestion = $issuesArray[0]->getSuggestion();

        self::assertNotNull($suggestion, 'Should provide suggestion');
        self::assertNotEmpty($suggestion->getCode(), 'Should have code in suggestion');
    }

    #[Test]
    public function it_provides_suggestion_for_static_tables(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM countries', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should provide suggestion
        $issuesArray = $issues->toArray();
        $suggestion = $issuesArray[0]->getSuggestion();

        self::assertNotNull($suggestion, 'Should provide suggestion');
        self::assertNotEmpty($suggestion->getCode(), 'Should have code in suggestion');
    }

    #[Test]
    public function it_distinguishes_different_query_patterns(): void
    {
        // Arrange: Two different query patterns, each executed 3 times
        $queries = QueryDataBuilder::create();

        // Pattern 1: users table
        for ($i = 1; $i <= 3; $i++) {
            $queries->addQuery("SELECT * FROM users WHERE id = {$i}", 10.0);
        }

        // Pattern 2: products table
        for ($i = 1; $i <= 3; $i++) {
            $queries->addQuery("SELECT * FROM products WHERE id = {$i}", 10.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should detect both patterns separately
        $issuesArray = $issues->toArray();
        self::assertCount(2, $issuesArray, 'Should detect each pattern separately');
    }

    #[Test]
    public function it_detects_settings_table_as_static(): void
    {
        // Arrange: Query on 'settings' table
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM settings WHERE key = ?', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect static table
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('settings', strtolower($issuesArray[0]->getTitle()));
    }

    #[Test]
    public function it_detects_roles_table_as_static(): void
    {
        // Arrange: Query on 'roles' table
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM roles', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect static table
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('roles', strtolower($issuesArray[0]->getTitle()));
    }

    #[Test]
    public function it_detects_categories_table_as_static(): void
    {
        // Arrange: Query on 'categories' table
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM categories', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect static table
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('categories', strtolower($issuesArray[0]->getTitle()));
    }

    #[Test]
    public function it_does_not_suggest_caching_for_insert_queries(): void
    {
        // Arrange: Same INSERT query executed 6 times
        // This is a valid use case - inserting multiple orders
        $queries = QueryDataBuilder::create();
        for ($i = 1; $i <= 6; $i++) {
            $queries->addQuery('INSERT INTO orders (status, total, created_at, user_id, customer_id) VALUES (?, ?, ?, ?, ?)', 0.67);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should NOT suggest caching for INSERT queries
        // INSERT queries cannot be cached - they modify data
        self::assertCount(0, $issues, 'Should not suggest caching for INSERT queries');
    }

    #[Test]
    public function it_does_not_suggest_caching_for_update_queries(): void
    {
        // Arrange: Same UPDATE query executed 5 times
        $queries = QueryDataBuilder::create();
        for ($i = 1; $i <= 5; $i++) {
            $queries->addQuery('UPDATE users SET status = ? WHERE id = ?', 10.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should NOT suggest caching for UPDATE queries
        self::assertCount(0, $issues, 'Should not suggest caching for UPDATE queries');
    }

    #[Test]
    public function it_does_not_suggest_caching_for_delete_queries(): void
    {
        // Arrange: Same DELETE query executed 4 times
        $queries = QueryDataBuilder::create();
        for ($i = 1; $i <= 4; $i++) {
            $queries->addQuery('DELETE FROM sessions WHERE expired_at < ?', 10.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should NOT suggest caching for DELETE queries
        self::assertCount(0, $issues, 'Should not suggest caching for DELETE queries');
    }

    #[Test]
    public function it_only_suggests_caching_for_select_queries(): void
    {
        // Arrange: Mix of SELECT, INSERT, UPDATE queries
        $queries = QueryDataBuilder::create();

        // 5 SELECT queries (should trigger suggestion)
        for ($i = 1; $i <= 5; $i++) {
            $queries->addQuery("SELECT * FROM products WHERE id = {$i}", 10.0);
        }

        // 5 INSERT queries (should NOT trigger suggestion)
        for ($i = 1; $i <= 5; $i++) {
            $queries->addQuery('INSERT INTO orders (status, total) VALUES (?, ?)', 10.0);
        }

        // 5 UPDATE queries (should NOT trigger suggestion)
        for ($i = 1; $i <= 5; $i++) {
            $queries->addQuery('UPDATE users SET last_login = ? WHERE id = ?', 10.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should only detect SELECT query caching opportunity
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray, 'Should only suggest caching for SELECT queries');

        // Verify it's about the SELECT query by checking the suggestion code
        $suggestion = $issuesArray[0]->getSuggestion();
        self::assertNotNull($suggestion);
        self::assertStringContainsString('SELECT', $suggestion->getCode());
    }

    #[Test]
    public function it_does_not_suggest_caching_for_insert_on_static_tables(): void
    {
        // Arrange: INSERT query on static table
        // This should not trigger suggestion because INSERTs cannot be cached
        $queries = QueryDataBuilder::create()
            ->addQuery('INSERT INTO countries (code, name) VALUES (?, ?)', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT suggest caching
        self::assertCount(0, $issues, 'Should not suggest caching for INSERT on static tables');
    }
}
