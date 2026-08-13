<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Module\Review\Mcp\DocumentHighlightTool;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * Answers tools/list with the optional tools an operator has not switched on
 * left out, so an agent never sees a tool this instance would refuse.
 *
 * It replaces the SDK's own handler rather than decorating it: the default is
 * built inside the server builder, and custom handlers are consulted first.
 *
 * @implements RequestHandlerInterface<ListToolsResult>
 */
final readonly class FlagGatedListToolsHandler implements RequestHandlerInterface
{
    /** @var array<string, string> tool name => the flag that must be on to advertise it */
    private const array GATED_TOOLS = [
        DocumentHighlightTool::NAME => DocumentHighlightTool::FLAG,
    ];

    public function __construct(
        private FeatureFlagService $featureFlags,

        #[Autowire(service: 'mcp.registry')]
        private RegistryInterface $registry,

        #[Autowire(param: 'mcp.pagination_limit')]
        private int $paginationLimit,
    ) {
    }

    #[\Override]
    public function supports(Request $request): bool
    {
        return $request instanceof ListToolsRequest;
    }

    #[\Override]
    public function handle(Request $request, SessionInterface $session): Response
    {
        \assert($request instanceof ListToolsRequest);

        $page = $this->registry->getTools($this->paginationLimit, $request->cursor);

        $tools = [];
        foreach ($page->references as $tool) {
            \assert($tool instanceof Tool);

            if (isset(self::GATED_TOOLS[$tool->name]) && !$this->featureFlags->isEnabled(self::GATED_TOOLS[$tool->name])) {
                continue;
            }

            $tools[] = $tool;
        }

        return new Response($request->getId(), new ListToolsResult($tools, $page->nextCursor));
    }
}
