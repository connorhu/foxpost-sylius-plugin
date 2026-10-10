<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Support;

use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * A plugin két admin controllere `AbstractController`-ből származik, és abból
 * kettőt használ: `addFlash()`-t és `redirectToRoute()`-ot. Mindkettő a
 * controller konténerén keresztül dolgozik (`request_stack`, illetve
 * `router`), tehát kernel nélkül is futtatható — csak ezt a két
 * szolgáltatást kell megadni.
 *
 * A `router` itt a plugin VALÓDI route-gyűjteményén álló generátor
 * (l. [[PluginRoutes]]): egy nem létező route-névre a `redirectToRoute()`
 * `RouteNotFoundException`-t dob, nem csendben rossz URL-t ad. A session
 * valódi `Session` `MockArraySessionStorage`-dzsal — a flash-eket így a
 * tesztek ugyanúgy olvassák, ahogy az admin lap kiírná őket.
 */
final class AdminControllerHarness
{
    public const string REFERER = 'https://bolt.hu/admin/foxpost-parcels/';

    /**
     * Felköti a controllert, és visszaadja a sessiont, amiben a flash-ek
     * landolnak.
     */
    public static function wire(AbstractController $controller, Request $request): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $controller->setContainer(self::container($requestStack));

        return $session;
    }

    /** @param array<string, string> $body */
    public static function post(array $body = [], ?string $referer = self::REFERER): Request
    {
        $request = Request::create('/admin/foxpost/parcels/register/1', 'POST', $body);

        if ($referer !== null) {
            $request->headers->set('referer', $referer);
        }

        return $request;
    }

    /**
     * @return array<string, list<string>>
     */
    public static function flashes(Session $session): array
    {
        /** @var array<string, list<string>> $all */
        $all = $session->getFlashBag()->all();

        return $all;
    }

    private static function container(RequestStack $requestStack): ContainerInterface
    {
        /** @var array<string, object> $services */
        $services = [
            'request_stack' => $requestStack,
            'router' => PluginRoutes::generator(),
        ];

        return new class($services) implements ContainerInterface {
            /** @param array<string, object> $services */
            public function __construct(private readonly array $services)
            {
            }

            public function get(string $id): object
            {
                if (!isset($this->services[$id])) {
                    throw new class(\sprintf('A controller a `%s` szolgáltatást kérte, amit ez a teszt-konténer nem ismer. ' . 'A plugin admin controllereinek az `AbstractController`-ből csak a `request_stack` ' . 'és a `router` kell — ha ez megváltozott, a harness-t kell bővíteni.', $id, )) extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {
                    };
                }

                return $this->services[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }
        };
    }
}
