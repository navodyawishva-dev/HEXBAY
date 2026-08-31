<?php
declare(strict_types=1);

namespace Hexbay\Services;

use Hexbay\Repositories\UserRepository;
use Hexbay\Support\HttpException;
use Hexbay\Validation\AdminValidator;
use PDO;
use PDOException;

final class AdminCatalogService
{
    public function __construct(
        private readonly PDO $db,
        private readonly UserRepository $users
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function categories(): array
    {
        return $this->db->query(
            'SELECT c.id, c.parent_id, c.name, c.slug, c.description,
                    c.is_active, c.requires_listing_approval, c.sort_order,
                    c.created_at, c.updated_at, p.name AS parent_name,
                    COUNT(DISTINCT sd.id) AS specification_count,
                    COUNT(DISTINCT cp.id) AS product_count
             FROM categories c
             LEFT JOIN categories p ON p.id = c.parent_id
             LEFT JOIN specification_definitions sd ON sd.category_id = c.id
             LEFT JOIN canonical_products cp ON cp.category_id = c.id
             GROUP BY c.id, p.name
             ORDER BY c.sort_order, c.name'
        )->fetchAll();
    }

    /** @return array<string, mixed> */
    public function saveCategory(
        ?int $categoryId,
        int $adminUserId,
        array $input,
        string $ipAddress
    ): array {
        $data = AdminValidator::category($input);
        if ($categoryId !== null && $data['parent_id'] === $categoryId) {
            throw new HttpException(422, 'A category cannot be its own parent.', [
                'parent_id' => ['Choose another parent category.'],
            ]);
        }
        if ($data['parent_id'] !== null) {
            $parent = $this->db->prepare('SELECT id FROM categories WHERE id = :id');
            $parent->execute(['id' => $data['parent_id']]);
            if ($parent->fetchColumn() === false) {
                throw new HttpException(422, 'Parent category was not found.', [
                    'parent_id' => ['Choose an existing category.'],
                ]);
            }
        }

        try {
            if ($categoryId === null) {
                $statement = $this->db->prepare(
                    'INSERT INTO categories
                        (parent_id, name, slug, description, is_active,
                         requires_listing_approval, sort_order)
                     VALUES
                        (:parent_id, :name, :slug, :description, :is_active,
                         :requires_listing_approval, :sort_order)'
                );
                $statement->execute($data);
                $categoryId = (int) $this->db->lastInsertId();
                $action = 'admin.category_created';
            } else {
                $exists = $this->db->prepare('SELECT id FROM categories WHERE id = :id');
                $exists->execute(['id' => $categoryId]);
                if ($exists->fetchColumn() === false) {
                    throw new HttpException(404, 'Category not found.');
                }
                $statement = $this->db->prepare(
                    'UPDATE categories
                     SET parent_id = :parent_id,
                         name = :name,
                         slug = :slug,
                         description = :description,
                         is_active = :is_active,
                         requires_listing_approval = :requires_listing_approval,
                         sort_order = :sort_order
                     WHERE id = :id'
                );
                $statement->execute([...$data, 'id' => $categoryId]);
                $action = 'admin.category_updated';
            }
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new HttpException(409, 'That category slug is already in use.');
            }
            throw $exception;
        }

        $this->users->audit(
            $adminUserId,
            $action,
            'category',
            $categoryId,
            [
                'name' => $data['name'],
                'slug' => $data['slug'],
                'is_active' => $data['is_active'],
            ],
            $ipAddress
        );
        return $this->categoryById($categoryId);
    }

    /** @return array<int, array<string, mixed>> */
    public function specifications(int $categoryId): array
    {
        $category = $this->db->prepare('SELECT id FROM categories WHERE id = :id');
        $category->execute(['id' => $categoryId]);
        if ($category->fetchColumn() === false) {
            throw new HttpException(404, 'Category not found.');
        }

        $definitions = $this->db->prepare(
            'SELECT id, category_id, code, display_name, data_type, unit,
                    is_required, is_filterable, is_compatibility_field,
                    minimum_value, maximum_value, sort_order, is_active
             FROM specification_definitions
             WHERE category_id = :category_id
             ORDER BY sort_order, display_name'
        );
        $definitions->execute(['category_id' => $categoryId]);
        $items = $definitions->fetchAll();
        if ($items === []) {
            return [];
        }

        $ids = array_map(static fn (array $item): int => (int) $item['id'], $items);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $options = $this->db->prepare(
            "SELECT id, definition_id, value_code, display_value, sort_order, is_active
             FROM specification_options
             WHERE definition_id IN ({$placeholders})
             ORDER BY sort_order, display_value"
        );
        $options->execute($ids);
        $byDefinition = [];
        foreach ($options->fetchAll() as $option) {
            $byDefinition[(int) $option['definition_id']][] = $option;
        }
        foreach ($items as &$item) {
            $item['options'] = $byDefinition[(int) $item['id']] ?? [];
        }
        unset($item);
        return $items;
    }

    /** @return array<string, mixed> */
    public function saveSpecification(
        int $categoryId,
        ?int $specificationId,
        int $adminUserId,
        array $input,
        string $ipAddress
    ): array {
        $data = AdminValidator::specification($input);
        $options = $data['options'];
        unset($data['options']);
        try {
            $this->db->beginTransaction();
            $category = $this->db->prepare('SELECT id FROM categories WHERE id = :id');
            $category->execute(['id' => $categoryId]);
            if ($category->fetchColumn() === false) {
                throw new HttpException(404, 'Category not found.');
            }

            if ($specificationId === null) {
                $statement = $this->db->prepare(
                    'INSERT INTO specification_definitions
                        (category_id, code, display_name, data_type, unit,
                         is_required, is_filterable, is_compatibility_field,
                         minimum_value, maximum_value, sort_order, is_active)
                     VALUES
                        (:category_id, :code, :display_name, :data_type, :unit,
                         :is_required, :is_filterable, :is_compatibility_field,
                         :minimum_value, :maximum_value, :sort_order, :is_active)'
                );
                $statement->execute(['category_id' => $categoryId, ...$data]);
                $specificationId = (int) $this->db->lastInsertId();
                $action = 'admin.specification_created';
            } else {
                $exists = $this->db->prepare(
                    'SELECT id
                     FROM specification_definitions
                     WHERE id = :id AND category_id = :category_id'
                );
                $exists->execute(['id' => $specificationId, 'category_id' => $categoryId]);
                if ($exists->fetchColumn() === false) {
                    throw new HttpException(404, 'Specification definition not found.');
                }
                $statement = $this->db->prepare(
                    'UPDATE specification_definitions
                     SET code = :code,
                         display_name = :display_name,
                         data_type = :data_type,
                         unit = :unit,
                         is_required = :is_required,
                         is_filterable = :is_filterable,
                         is_compatibility_field = :is_compatibility_field,
                         minimum_value = :minimum_value,
                         maximum_value = :maximum_value,
                         sort_order = :sort_order,
                         is_active = :is_active
                     WHERE id = :id'
                );
                $statement->execute([...$data, 'id' => $specificationId]);
                $action = 'admin.specification_updated';
            }

            $disableOptions = $this->db->prepare(
                'UPDATE specification_options
                 SET is_active = FALSE
                 WHERE definition_id = :definition_id'
            );
            $disableOptions->execute(['definition_id' => $specificationId]);
            if (in_array($data['data_type'], ['option', 'multi_option'], true)) {
                $saveOption = $this->db->prepare(
                    'INSERT INTO specification_options
                        (definition_id, value_code, display_value, sort_order, is_active)
                     VALUES
                        (:definition_id, :value_code, :display_value, :sort_order, TRUE)
                     ON DUPLICATE KEY UPDATE
                        display_value = VALUES(display_value),
                        sort_order = VALUES(sort_order),
                        is_active = TRUE'
                );
                $sortOrder = 0;
                foreach ($options as $valueCode => $displayValue) {
                    $saveOption->execute([
                        'definition_id' => $specificationId,
                        'value_code' => $valueCode,
                        'display_value' => $displayValue,
                        'sort_order' => $sortOrder++,
                    ]);
                }
            }

            $this->users->audit(
                $adminUserId,
                $action,
                'specification_definition',
                $specificationId,
                [
                    'category_id' => $categoryId,
                    'code' => $data['code'],
                    'data_type' => $data['data_type'],
                ],
                $ipAddress
            );
            $this->db->commit();
        } catch (PDOException $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ((string) $exception->getCode() === '23000') {
                throw new HttpException(
                    409,
                    'That specification code already exists in this category.'
                );
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        foreach ($this->specifications($categoryId) as $item) {
            if ((int) $item['id'] === $specificationId) {
                return $item;
            }
        }
        throw new \RuntimeException('Saved specification could not be loaded.');
    }

    /** @return array<string, mixed> */
    private function categoryById(int $categoryId): array
    {
        foreach ($this->categories() as $category) {
            if ((int) $category['id'] === $categoryId) {
                return $category;
            }
        }
        throw new \RuntimeException('Saved category could not be loaded.');
    }
}
