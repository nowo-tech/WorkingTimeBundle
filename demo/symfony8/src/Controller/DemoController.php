<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * S0 demo home: shows that the bundle is installed, the three part toggles, the MySQL connection and the FrankenPHP mode.
 */
final class DemoController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%nowo_working_time.register.enabled%')] private readonly bool $register,
        #[Autowire('%nowo_working_time.holidays.enabled%')] private readonly bool $holidays,
        #[Autowire('%nowo_working_time.leave.enabled%')] private readonly bool $leave,
    ) {
    }

    #[Route('/', name: 'demo_home', methods: ['GET'])]
    public function home(Request $request): Response
    {
        try {
            $database = (string) $this->connection->fetchOne('SELECT VERSION()');
        } catch (Throwable) {
            $database = null;
        }

        return $this->render('demo/home.html.twig', [
            'parts'    => ['Register' => $this->register, 'Holidays' => $this->holidays, 'Leave' => $this->leave],
            'database' => $database,
            'worker'   => $request->server->has('FRANKENPHP_WORKER'),
        ]);
    }
}
