<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Scripted database for the Email Delivery tab tests: answers the three queries
 * of DeliveryLogRepository and records them.
 */
class ScriptedDeliveryDb extends FakeDatabase
{
    public bool $tableExists = true;

    /** False makes the `tags` column check report an old plugin table. */
    public bool $hasTagsColumn = true;

    public ?string $failTableCheckWith = null;

    /** @var list<string> */
    public array $sqls = [];

    public ?string $windowSql = null;

    /** @var list<mixed>|null */
    public ?array $windowParams = null;

    private bool $failed = false;

    /** @param list<array<string, mixed>> $rows */
    public function __construct(private readonly array $rows)
    {
    }

    public function query(string $sql, array $params = []): self
    {
        $this->sqls[] = $sql;
        $this->failed = false;
        if (str_contains($sql, 'information_schema.tables')) {
            if ($this->failTableCheckWith !== null) {
                $this->failed = true;
            }
            $this->firstRow = (object) ['cnt' => $this->tableExists ? 1 : 0];
        } elseif (str_contains($sql, 'information_schema.columns')) {
            $this->firstRow = (object) ['cnt' => $this->hasTagsColumn ? 1 : 0];
        } else {
            $this->windowSql = $sql;
            $this->windowParams = $params;
        }

        return $this;
    }

    public function error(): bool
    {
        return $this->failed;
    }

    public function errorString(): string
    {
        return (string) $this->failTableCheckWith;
    }

    public function results(bool $assoc = false): array
    {
        return $this->rows;
    }
}
