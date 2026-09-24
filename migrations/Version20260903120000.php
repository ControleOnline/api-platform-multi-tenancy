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
        // Version20260729173000 creates/normalizes cron_jobs before this cleanup.
        // Avoid DBAL schema introspection here: legacy MySQL ENUM columns are not
        // portable across the DBAL versions used by the supported installations.
        $this->addSql("DELETE FROM `cron_jobs` WHERE `command` = 'tenant:integration:start'");
    }

    public function down(Schema $schema): void
    {
        // The legacy polling job is intentionally not restored.
    }
}
