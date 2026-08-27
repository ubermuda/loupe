<?php

declare(strict_types=1);

namespace App\Module\Project\Mcp;

/**
 * The MCP tools advertised to a connected agent, in the order the Connect
 * screen lists them. Each description is a translation key so the copy stays
 * in the message catalog. The landing page lists the same set, so this is the
 * one place the roster is written down.
 */
final class AdvertisedTools
{
    /** @var list<array{name: string, descriptionKey: string}> */
    public const array ALL = [
        ['name' => 'document_create', 'descriptionKey' => 'project.connect.tool.document_create'],
        ['name' => 'document_list', 'descriptionKey' => 'project.connect.tool.document_list'],
        ['name' => 'document_get', 'descriptionKey' => 'project.connect.tool.document_get'],
        ['name' => 'document_revise', 'descriptionKey' => 'project.connect.tool.document_revise'],
        ['name' => 'document_rename', 'descriptionKey' => 'project.connect.tool.document_rename'],
        ['name' => 'document_archive', 'descriptionKey' => 'project.connect.tool.document_archive'],
        ['name' => 'document_unarchive', 'descriptionKey' => 'project.connect.tool.document_unarchive'],
        ['name' => 'document_set_tags', 'descriptionKey' => 'project.connect.tool.document_set_tags'],
        ['name' => 'document_set_references', 'descriptionKey' => 'project.connect.tool.document_set_references'],
        ['name' => 'document_highlight', 'descriptionKey' => 'project.connect.tool.document_highlight'],
        ['name' => 'document_get_review', 'descriptionKey' => 'project.connect.tool.document_get_review'],
        ['name' => 'document_reply_to_comment', 'descriptionKey' => 'project.connect.tool.document_reply_to_comment'],
        ['name' => 'document_mark_comment_addressed', 'descriptionKey' => 'project.connect.tool.document_mark_comment_addressed'],
        ['name' => 'tag_list', 'descriptionKey' => 'project.connect.tool.tag_list'],
        ['name' => 'site_review_get', 'descriptionKey' => 'project.connect.tool.site_review_get'],
        ['name' => 'site_review_mark_comment_addressed', 'descriptionKey' => 'project.connect.tool.site_review_mark_comment_addressed'],
    ];

    /** @var array<string, string> tool name => the flag that must be on to advertise it */
    public const array GATED = ['document_highlight' => 'review.highlights.enabled'];
}
