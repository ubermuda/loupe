<?php

declare(strict_types=1);

namespace App\Module\Project\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Resolves the "current project" for the request that drives the app-shell
 * switcher and scoped nav. The active project is taken from the route params
 * ({@see resolve()}) and is always owner-scoped — it never returns another
 * user's project.
 *
 * Result is memoized per {@see Request} in a WeakMap: the class stays readonly
 * and a null result is cached (so a no-match request isn't re-computed), while
 * a long-running worker runtime cannot leak one request's project into another.
 */
final readonly class CurrentProjectProvider
{
    /** @var \WeakMap<Request, ?Project> */
    private \WeakMap $cache;

    public function __construct(
        private RequestStack $requestStack,
        private ProjectRepository $projects,
        private TokenStorageInterface $tokenStorage,
    ) {
        $this->cache = new \WeakMap();
    }

    public function current(): ?Project
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return null;
        }

        if ($this->cache->offsetExists($request)) {
            return $this->cache[$request];
        }

        return $this->cache[$request] = $this->resolve($request);
    }

    private function resolve(Request $request): ?Project
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof User) {
            return null;
        }

        // The EntityValueResolver does not write the resolved entity back to
        // request attributes, so the route param is always the raw string. On
        // {id:project} routes the value lands under the alias `project`, not
        // `id` — so probe all three known param names and take the first present.
        foreach (['id', 'project', 'projectId'] as $key) {
            $raw = $request->attributes->get($key);
            if (is_string($raw) && '' !== $raw) {
                return $this->projects->findOneByIdOrNameForOwner($raw, $user);
            }
        }

        return null;
    }
}
