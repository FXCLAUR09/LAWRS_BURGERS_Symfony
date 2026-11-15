<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add staff presence tracking for the Admin on-duty dashboard panel.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE staff_presence (staff_id INT NOT NULL, last_seen_at DATETIME NOT NULL, logged_in_at DATETIME NOT NULL, logged_out_at DATETIME DEFAULT NULL, PRIMARY KEY(staff_id), CONSTRAINT FK_STAFF_PRESENCE_USER FOREIGN KEY (staff_id) REFERENCES staff_users (id) ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE staff_presence');
    }
}
