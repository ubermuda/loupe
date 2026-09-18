<?php

declare(strict_types=1);

namespace App\Module\Project\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\AmbiguousProjectHandleException;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Resolves the "current project" for the request that drives the app-shell
 * switcher and scoped nav. The active project is taken from the route params
 * ({@see resolve()}); {@see currentOrLastVisited()} also falls back to the last
 * project page this session visited. Both are owner-scoped — they never return
 * another user's project.
 *
 * Result is memoized per {@see Request} in a WeakMap: the class stays readonly
 * and a null result is cached (so a no-match request isn't re-computed), while
 * a long-running worker runtime cannot leak one request's project into another.
 */
final readonly class CurrentProjectProvider
{
    private const string LAST_VISITED_SESSION_KEY = 'project.last_visited_id';

    /** @var \WeakMap<Request, array{?Project, ?Project}> */
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
        return $this->resolved()[0];
    }

    public function currentOrLastVisited(): ?Project
    {
        return $this->resolved()[1];
    }

    /** @return array{?Project, ?Project} */
    private function resolved(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return [null, null];
        }

        if ($this->cache->offsetExists($request)) {
            return $this->cache[$request];
        }

        return $this->cache[$request] = $this->resolve($request);
    }

    /** @return array{?Project, ?Project} the route's project, then the same or the last visited one */
    private function resolve(Request $request): array
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof User) {
            return [null, null];
        }

        // The EntityValueResolver does not write the resolved entity back to
        // request attributes, so the route param is always the raw string. On
        // {id:project} routes the value lands under the alias `project`, not
        // `id` — so probe all three known param names and take the first present.
        $session = $request->hasSession() ? $request->getSession() : null;
        foreach (['id', 'project', 'projectId'] as $key) {
            $raw = $request->attributes->get($key);
            if (is_string($raw) && '' !== $raw) {
                $project = $this->findForOwner($raw, $user);
                // A hover prefetch renders the page without visiting it.
                if (null !== $project && !$this->isPrefetch($request)) {
                    $session?->set(self::LAST_VISITED_SESSION_KEY, (string) $project->id);
                }

                return [$project, $project];
            }
        }

        // Only a page with no project in its route falls back. The owner-scoped
        // lookup re-checks access, so a deleted or foreign project falls away.
        $remembered = $session?->get(self::LAST_VISITED_SESSION_KEY);

        return [null, is_string($remembered) ? $this->findForOwner($remembered, $user) : null];
    }

    private function isPrefetch(Request $request): bool
    {
        return 'prefetch' === $request->headers->get('X-Sec-Purpose')
            || 'prefetch' === $request->headers->get('Sec-Purpose');
    }

    private function findForOwner(string $handle, User $user): ?Project
    {
        try {
            return $this->projects->findOneByHandleForOwner($handle, $user);
        } catch (AmbiguousProjectHandleException) {
            return null;
        }
    }
}
