<?php

declare(strict_types=1);

namespace App\Security;

use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final class BackOfficeUserProvider implements UserProviderInterface
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        if (preg_match('/^admin:(\d+)$/', $identifier, $matches)) {
            $id = (int) $matches[1];
            $exists = $this->connection->fetchOne(
                'SELECT id FROM admin_users WHERE id = ? LIMIT 1',
                [$id]
            );

            if (!$exists) {
                throw new UserNotFoundException('Admin account was not found.');
            }

            return new BackOfficeUser('admin:' . $id, ['ROLE_ADMIN']);
        }

        if (preg_match('/^staff:(\d+)$/', $identifier, $matches)) {
            $id = (int) $matches[1];
            $staff = $this->connection->fetchAssociative(
                'SELECT id, status FROM staff_users WHERE id = ? LIMIT 1',
                [$id]
            );

            if (!$staff || ($staff['status'] ?? '') !== 'active') {
                throw new UserNotFoundException('Staff account was not found or is inactive.');
            }

            return new BackOfficeUser('staff:' . $id, ['ROLE_STAFF']);
        }

        throw new UserNotFoundException('Unknown back-office account.');
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof BackOfficeUser) {
            throw new \Symfony\Component\Security\Core\Exception\UnsupportedUserException(
                sprintf('Unsupported user class "%s".', get_debug_type($user))
            );
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return is_a($class, BackOfficeUser::class, true);
    }
}
