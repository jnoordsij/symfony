<?php

namespace Symfony\Component\Serializer\Tests\Fixtures;

use Symfony\Component\Serializer\Annotation\Groups;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_PROPERTY)]
final class ChildOfGroupsAnnotationDummy extends Groups
{
    public function __construct()
    {
        parent::__construct(['d']);
    }
}
