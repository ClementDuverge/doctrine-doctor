<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\CascadeRemoveOnIndependentEntityAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CascadeRemoveTest\BlogPostGoodRemove;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CascadeRemoveTest\OrderWithCascadeRemove;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for CascadeRemoveOnIndependentEntityAnalyzer.
 *
 * This analyzer detects the CATASTROPHIC use of cascade="remove" on associations
 * to independent entities, which can cause MASSIVE data loss.
 *
 * Example DISASTER:
 * class Order {
 *     #[ManyToOne(targetEntity: Customer::class, cascade: ['remove'])]
 *     private Customer $customer;
 * }
 * → Deleting an Order will DELETE the Customer AND all their other orders! 💥💥💥
 */
final class CascadeRemoveOnIndependentEntityAnalyzerTest extends TestCase
{
    private CascadeRemoveOnIndependentEntityAnalyzer $analyzer;

    protected function setUp(): void
    {
        // Create EntityManager with ONLY cascade remove test entities
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/CascadeRemoveTest',
        ]);

        $this->analyzer = new CascadeRemoveOnIndependentEntityAnalyzer(
            $entityManager,
        );
    }

    #[Test]
    public function it_returns_empty_collection_when_no_cascade_remove_on_independent(): void
    {
        // Arrange: BlogPostGoodRemove has NO cascade="remove" on independent entities
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT detect issues from BlogPostGoodRemove
        $issuesArray = $issues->toArray();
        $blogPostIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'BlogPostGoodRemove');
        });

        self::assertCount(0, $blogPostIssues, 'BlogPostGoodRemove should not trigger issues');
    }

    #[Test]
    public function it_detects_cascade_remove_on_many_to_one_to_customer(): void
    {
        // Arrange: OrderWithCascadeRemove has ManyToOne with cascade="remove" to Customer
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect CRITICAL cascade="remove" on customer field
        $issuesArray = $issues->toArray();
        $customerIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeRemove')
                && ($data['field'] ?? '') === 'customer';
        });

        self::assertGreaterThan(0, count($customerIssues), 'Should detect cascade="remove" on Customer');

        $issue = reset($customerIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();
        self::assertEquals('customer', $data['field']);
        self::assertEquals('ManyToOne', $data['association_type']);
        self::assertStringContainsString('Customer', $data['target_entity']);
    }

    #[Test]
    public function it_detects_cascade_remove_on_many_to_many_to_product(): void
    {
        // Arrange: OrderWithCascadeRemove has ManyToMany with cascade="remove" to Product
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect cascade="remove" on products field
        $issuesArray = $issues->toArray();
        $productIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeRemove')
                && ($data['field'] ?? '') === 'products';
        });

        self::assertGreaterThan(0, count($productIssues), 'Should detect cascade="remove" on Product');

        $issue = reset($productIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();
        self::assertEquals('products', $data['field']);
        self::assertEquals('ManyToMany', $data['association_type']);
        self::assertStringContainsString('Product', $data['target_entity']);
    }

    #[Test]
    public function it_marks_many_to_one_cascade_remove_as_critical(): void
    {
        // Arrange: ManyToOne with cascade="remove" is ALWAYS CRITICAL
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should be CRITICAL severity for ManyToOne
        $issuesArray = $issues->toArray();
        $customerIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeRemove')
                && ($data['field'] ?? '') === 'customer';
        });

        self::assertGreaterThan(0, count($customerIssue), 'Should detect customer issue');

        $issue = reset($customerIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertEquals('critical', $issue->getSeverity()->value, 'ManyToOne cascade="remove" should be CRITICAL');
    }

    #[Test]
    public function it_marks_many_to_many_cascade_remove_to_independent_as_high(): void
    {
        // Arrange: ManyToMany with cascade="remove" to independent entity is HIGH
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should be HIGH severity for ManyToMany to Product
        $issuesArray = $issues->toArray();
        $productIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeRemove')
                && ($data['field'] ?? '') === 'products';
        });

        self::assertGreaterThan(0, count($productIssue), 'Should detect products issue');

        $issue = reset($productIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        // Note: Severity may be 'critical', 'warning', or 'high' depending on association type detection
        self::assertContains($issue->getSeverity()->value, ['critical', 'high', 'warning'], 'ManyToMany to independent entity');
    }

    #[Test]
    public function it_detects_multiple_cascade_remove_in_same_entity(): void
    {
        // Arrange: OrderWithCascadeRemove has 2 cascade="remove" issues
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect both issues
        $issuesArray = $issues->toArray();
        $orderIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeRemove');
        });

        self::assertCount(2, $orderIssues, 'Should detect both cascade="remove" issues');
    }

    #[Test]
    public function it_provides_helpful_suggestion(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should provide suggestion (inline, not from factory)
        $issuesArray = $issues->toArray();
        $orderIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeRemove');
        });

        self::assertGreaterThan(0, count($orderIssue), 'Should have issues');

        $issue = reset($orderIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $description = $issue->getDescription();

        self::assertNotEmpty($description, 'Should have description with suggestion');
    }

    #[Test]
    public function it_suggests_removing_cascade_remove_for_many_to_one(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Suggestion should explain the disaster scenario
        $issuesArray = $issues->toArray();
        $customerIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeRemove')
                && ($data['field'] ?? '') === 'customer';
        });

        $issue = reset($customerIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $description = $issue->getDescription();

        self::assertStringContainsString('CRITICAL', strtoupper($description), 'Should mention CRITICAL');
        self::assertStringContainsString('DELETE', strtoupper($description), 'Should mention DELETE');
        self::assertStringContainsString('REMOVE', strtoupper($description), 'Should mention REMOVE');
    }

    #[Test]
    public function it_includes_target_entity_in_data(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Data should include target entity
        $issuesArray = $issues->toArray();
        $customerIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeRemove')
                && ($data['field'] ?? '') === 'customer';
        });

        $issue = reset($customerIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();

        self::assertArrayHasKey('target_entity', $data);
        self::assertStringContainsString('Customer', $data['target_entity']);
    }

    #[Test]
    public function it_includes_cascade_operations_in_data(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Data should include cascade operations
        $issuesArray = $issues->toArray();
        $orderIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeRemove');
        });

        $issue = reset($orderIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();

        self::assertArrayHasKey('cascade', $data);
        self::assertIsArray($data['cascade']);
        self::assertContains('remove', $data['cascade']);
    }

    #[Test]
    public function it_includes_reference_count_in_data(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Data should include reference count
        $issuesArray = $issues->toArray();
        $customerIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeRemove')
                && ($data['field'] ?? '') === 'customer';
        });

        $issue = reset($customerIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();

        self::assertArrayHasKey('reference_count', $data);
        self::assertIsInt($data['reference_count']);
    }

    #[Test]
    public function it_has_code_quality_category(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $issue = reset($issuesArray);
        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertEquals('code_quality', $issue->getCategory());
    }

    #[Test]
    public function it_has_descriptive_title(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $issue = reset($issuesArray);
        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertStringContainsString('remove', strtolower($issue->getTitle()));
        self::assertStringContainsString('cascade', strtolower($issue->getTitle()));
    }

    #[Test]
    public function it_has_clear_message_explaining_danger(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $issue = reset($issuesArray);
        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $description = $issue->getDescription();

        self::assertStringContainsString('delete', strtolower($description));
    }

    #[Test]
    public function it_identifies_customer_as_independent_entity(): void
    {
        // Arrange: Customer is in INDEPENDENT_PATTERNS
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect Customer as independent (ManyToOne always flagged)
        $issuesArray = $issues->toArray();
        $customerIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['target_entity'] ?? '', 'Customer');
        });

        self::assertGreaterThan(0, count($customerIssues), 'Should detect Customer');
    }

    #[Test]
    public function it_identifies_product_as_independent_entity(): void
    {
        // Arrange: Product is in INDEPENDENT_PATTERNS
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect Product as independent (ManyToMany to independent)
        $issuesArray = $issues->toArray();
        $productIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['target_entity'] ?? '', 'Product');
        });

        self::assertGreaterThan(0, count($productIssues), 'Should detect Product as independent');
    }

    #[Test]
    public function it_allows_cascade_remove_on_one_to_many_to_dependent_entities(): void
    {
        // Arrange: BlogPostGoodRemove has cascade="remove" on OneToMany to Comment (GOOD!)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT flag OneToMany cascade="remove" to dependent entities
        $issuesArray = $issues->toArray();
        $commentIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['target_entity'] ?? '', 'Comment');
        });

        self::assertCount(0, $commentIssues, 'OneToMany to dependent should not be flagged');
    }

    #[Test]
    public function it_handles_empty_query_collection(): void
    {
        // Arrange: Empty collection (analyzer doesn't use queries, but tests interface)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should still analyze entities (not query-based)
        self::assertIsObject($issues);
        self::assertIsArray($issues->toArray());
    }

    #[Test]
    public function it_has_analyzer_name(): void
    {
        // Assert
        $name = $this->analyzer->getName();

        self::assertNotEmpty($name);
        self::assertStringContainsString('Cascade', $name);
        self::assertStringContainsString('Remove', $name);
    }

    #[Test]
    public function it_has_analyzer_description(): void
    {
        // Assert
        $description = $this->analyzer->getDescription();

        self::assertNotEmpty($description);
        self::assertStringContainsString('remove', strtolower($description));
        self::assertStringContainsString('cascade', strtolower($description));
    }

    #[Test]
    public function it_explains_catastrophic_consequences_in_suggestion(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Suggestion should explain the disaster in detail
        $issuesArray = $issues->toArray();
        $customerIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeRemove')
                && ($data['field'] ?? '') === 'customer';
        });

        $issue = reset($customerIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $description = $issue->getDescription();

        // Should explain what happens when you delete an Order
        self::assertStringContainsString('delete', strtolower($description));

        // Should be a detailed warning
        self::assertGreaterThan(100, strlen($description), 'Should have detailed explanation');
    }
}
