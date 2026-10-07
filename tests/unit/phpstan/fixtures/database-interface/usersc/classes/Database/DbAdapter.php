<?php

final class DbAdapter
{
    public function __construct(private readonly DB $db)
    {
    }
}
