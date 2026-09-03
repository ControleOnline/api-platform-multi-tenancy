<?php

declare(strict_types=1);

namespace DoctrineMigrations\MultiTenancy;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260903120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove the legacy tenant integration polling cron job; integrations are consumed by Messenger.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('cron_jobs')) {
            $this->addSql("DELETE FROM `cron_jobs` WHERE `command` = 'tenant:integration:start'");
        }
    }

    public function down(Schema $schema): void
    {
        // The legacy polling job is intentionally not restored.
    }
}
