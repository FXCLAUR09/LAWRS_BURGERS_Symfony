<?php

namespace App\EventSubscriber;

use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class BackOfficeAccessSubscriber implements EventSubscriberInterface
{
    private const STAFF_ALLOWED_ROUTES = [
        'staff_dashboard',
        'staff_reviews',
        'admin_burgers',
        'admin_burger_add',
        'admin_burger_restock',
        'admin_burger_edit',
        'admin_burger_delete',
        'admin_orders',
        'admin_order_view',
        'admin_order_update_status',
        'admin_order_payment',
        'staff_order_add',
        'admin_order_delete',
    ];

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private Connection $connection
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 8]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');
        if ($route === '' || in_array($route, ['app_login', 'app_logout'], true)) {
            return;
        }

        $session = $request->getSession();
        $isAdmin = $session->has('admin_id');
        $isStaff = $session->has('staff_id');

        if ($isStaff) {
            $activeStaff = $this->connection->fetchOne(
                "SELECT id FROM staff_users WHERE id = ? AND status = 'active' LIMIT 1",
                [(int) $session->get('staff_id')]
            );
            if (!$activeStaff) {
                $session->invalidate();
                $event->setResponse(new RedirectResponse(
                    $this->urlGenerator->generate('app_login')
                ));
                return;
            }
        }

        if (str_starts_with($route, 'admin_')) {
            if (in_array($route, self::STAFF_ALLOWED_ROUTES, true)) {
                if (!$isAdmin && !$isStaff) {
                    $event->setResponse(new RedirectResponse(
                        $this->urlGenerator->generate('app_login')
                    ));
                }
                return;
            }

            if (!$isAdmin) {
                $event->setResponse(new RedirectResponse(
                    $this->urlGenerator->generate($isStaff ? 'staff_dashboard' : 'app_login')
                ));
            }
            return;
        }

        if (str_starts_with($route, 'staff_') && !$isStaff) {
            $event->setResponse(new RedirectResponse(
                $this->urlGenerator->generate($isAdmin ? 'admin_dashboard' : 'app_login')
            ));
        }
    }
}
