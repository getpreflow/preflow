<?php

declare(strict_types=1);

namespace Preflow\Data\Migration;

abstract class Migration
{
    abstract public function up(Schema $schema): void;

    public function down(Schema $schema): void
    {
    }
}
