<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use LogicException;
use Throwable;

use function in_array;
use function is_string;
use function str_ends_with;
use function str_starts_with;

/**
 * Doctrine `loadClassMetadata` listener of the bundle's entities (and only them: on a shared entity manager the host's
 * classes are untouched): prefixes table, index and unique-constraint names with `doctrine.table_prefix`
 * (REQ-ORM-002) and maps the identifier, reference, key and hash columns with a binary NO PAD collation on MySQL /
 * MariaDB ({@see BinaryCollation}), so `schema:update` and `migrations:diff` keep it.
 *
 * @internal
 */
final readonly class RegisterMetadataListener
{
    /** Namespace of the mapped entities. */
    public const ENTITY_NAMESPACE = 'Nowo\WorkingTimeBundle\Domain\\';

    /** Columns compared exactly besides every `id` / `*_id`. */
    public const EXACT_COLUMNS = ['request_key', 'dedupe_key', 'hash', 'prev_hash', 'anchor_hash', 'sha256'];

    /**
     * Wires the collaborators.
     */
    public function __construct(private string $tablePrefix = 'nowo_')
    {
    }

    /**
     * Doctrine `loadClassMetadata` listener.
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        if (!str_starts_with($metadata->getName(), self::ENTITY_NAMESPACE)) {
            return;
        }
        $table = ['name' => $this->tablePrefix . $metadata->getTableName()];
        foreach (['indexes', 'uniqueConstraints'] as $key) {
            $renamed = [];
            foreach ($metadata->table[$key] ?? [] as $name => $definition) {
                $renamed[is_string($name) ? $this->tablePrefix . $name : $name] = $definition;
            }
            $table[$key] = $renamed;
        }
        $metadata->setPrimaryTable($table);
        try {
            $platform = $args->getObjectManager()->getConnection()->getDatabasePlatform();
        } catch (Throwable $exception) {
            throw new LogicException('nowo_working_time needs the database platform to map its reference columns with a binary collation. Set doctrine.dbal.server_version (or make the database reachable) before warming up the cache.', 0, $exception);
        }
        $collation = BinaryCollation::for($platform);
        if ($collation === null) {
            return;
        }
        foreach ($metadata->fieldMappings as $field => $mapping) {
            $column = $mapping->columnName;
            if ($mapping->type === 'string' && ($column === 'id' || str_ends_with($column, '_id') || in_array($column, self::EXACT_COLUMNS, true))) {
                $metadata->fieldMappings[$field]->options = ['charset' => 'utf8mb4', 'collation' => $collation] + ($mapping->options ?? []);
            }
        }
    }
}
