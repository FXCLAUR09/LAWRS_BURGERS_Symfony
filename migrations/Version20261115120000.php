<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track the Staff member who created each back-office order while preserving legacy and online orders.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders ADD created_by_staff_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_ORDERS_CREATED_BY_STAFF ON orders (created_by_staff_id)');
        $this->addSql('ALTER TABLE orders ADD CONSTRAINT FK_ORDERS_CREATED_BY_STAFF FOREIGN KEY (created_by_staff_id) REFERENCES staff_users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders DROP FOREIGN KEY FK_ORDERS_CREATED_BY_STAFF');
        $this->addSql('DROP INDEX IDX_ORDERS_CREATED_BY_STAFF ON orders');
        $this->addSql('ALTER TABLE orders DROP created_by_staff_id');
    }
}
