<?php

use ElanRegistry\DatabaseInterface;

final class ConcreteDbService
{
    private DB $db;

    public function __construct(?\DB $db, private DatabaseInterface $interfaceDb)
    {
    }

    public function withUnion(DB|null $db): void
    {
    }

    public function withInterface(DatabaseInterface $db, MyDB $other): void
    {
    }
}

function concrete_db_helper(DB $db): void
{
}
