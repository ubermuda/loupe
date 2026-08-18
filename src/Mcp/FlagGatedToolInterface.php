<?php

declare(strict_types=1);

namespace App\Mcp;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * An MCP tool that is only advertised while a feature flag is on.
 *
 * The tool declares its own gate so root `src/` does not have to name a
 * module's classes: FlagGatedListToolsHandler collects whatever implements
 * this, and a module adding a gated tool changes nothing outside itself.
 */
#[AutoconfigureTag('app.mcp_gated_tool')]
interface FlagGatedToolInterface
{
    /** The tool name as advertised over MCP. */
    public function gatedToolName(): string;

    /** The flag that must be enabled for the tool to be listed. */
    public function requiredFlag(): string;
}
