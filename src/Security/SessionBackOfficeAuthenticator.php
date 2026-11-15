<?php

declare(strict_types=1);

namespace App\Security;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class SessionBackOfficeAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly UrlGeneratorInterface $urlGenerator
    ) {
    }

    public function supports(Request $request): ?bool
    {
        $path = $request->getPathInfo();
        $protectedPath = str_starts_with($path, '/admin')
            || str_starts_with($path, '/staff');

        return $protectedPath
            && ($request->getSession()->has('admin_id')
                || $request->getSession()->has('staff_id'));
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $session = $request->getSession();
        $adminId = (int) $session->get('admin_id', 0);
        $staffId = (int) $session->get('staff_id', 0);

        // A session must represent exactly one back-office identity.
        if (($adminId > 0) === ($staffId > 0)) {
            throw new AuthenticationException('Invalid back-office session.');
        }

        if ($adminId > 0) {
            $exists = $this->connection->fetchOne(
                'SELECT id FROM admin_users WHERE id = ? LIMIT 1',
                [$adminId]
            );
            if (!$exists) {
                $session->invalidate();
                throw new AuthenticationException('Admin account no longer exists.');
            }

            $identifier = 'admin:' . $adminId;
            $roles = ['ROLE_ADMIN'];
        } else {
            $staff = $this->connection->fetchAssociative(
                "SELECT id, status FROM staff_users WHERE id = ? LIMIT 1",
                [$staffId]
            );
            if (!$staff || ($staff['status'] ?? '') !== 'active') {
                $session->invalidate();
                throw new AuthenticationException('Staff account is inactive or unavailable.');
            }

            $identifier = 'staff:' . $staffId;
            $roles = ['ROLE_STAFF'];
        }

        return new SelfValidatingPassport(
            new UserBadge($identifier, static fn (): UserInterface => new BackOfficeUser($identifier, $roles))
        );
    }

    public function onAuthenticationSuccess(
        Request $request,
        TokenInterface $token,
        string $firewallName
    ): ?Response {
        return null;
    }

    public function onAuthenticationFailure(
        Request $request,
        AuthenticationException $exception
    ): Response {
        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }
}
