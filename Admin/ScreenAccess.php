<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CsvImporter\Admin;

use CsvImporter\CsvImporter;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Model\Admin;
use Thelia\Model\AdminLog;

/**
 * The access of the screens is the one the profiles already grant per module: VIEW to
 * read the jobs and their reports, CREATE to stage a file and simulate, UPDATE to write
 * to the catalog.
 */
final readonly class ScreenAccess
{
    private const ADMIN_ROLE = 'ADMIN';

    public function __construct(
        private SecurityContext $securityContext,
        private RequestStack $requestStack,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function can(string $access): bool
    {
        return $this->securityContext->isGranted([self::ADMIN_ROLE], [], [CsvImporter::getModuleCode()], [$access]);
    }

    public function check(string $access): ?Response
    {
        if ($this->can($access)) {
            return null;
        }

        if (!$this->securityContext->hasAdminUser()) {
            $request = $this->requestStack->getMainRequest();
            $target = null !== $request && 'GET' === $request->getMethod() ? $request->getRequestUri() : '';
            $login = $this->urls->generate('admin.login');

            return new RedirectResponse('' === $target ? $login : $login.'?_target_path='.rawurlencode($target));
        }

        $this->log($access, \sprintf('User is not granted for the module %s with the access %s', CsvImporter::getModuleCode(), $access));

        return new Response('Forbidden', Response::HTTP_FORBIDDEN, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function adminId(): ?int
    {
        $admin = $this->securityContext->getAdminUser();

        return $admin instanceof Admin ? $admin->getId() : null;
    }

    /**
     * The request content is never kept: an upload carries the file, and the headers carry
     * the session cookie.
     */
    public function log(string $access, string $message, ?int $jobId = null): void
    {
        $request = $this->requestStack->getMainRequest();

        if (null === $request) {
            return;
        }

        AdminLog::append(AdminResources::MODULE, $access, $message, $request, $this->securityContext->getAdminUser(), false, $jobId);
    }
}
