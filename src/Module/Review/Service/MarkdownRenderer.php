<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final readonly class MarkdownRenderer
{
    private MarkdownConverter $converter;
    private HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $environment = new Environment(['html_input' => 'allow', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new TableExtension());
        $this->converter = new MarkdownConverter($environment);
        $config = new HtmlSanitizerConfig()
            ->allowSafeElements()
            ->allowElement('h1')
            ->allowElement('h2')
            ->allowElement('h3')
            ->allowElement('h4')
            ->allowElement('h5')
            ->allowElement('h6')
            ->allowElement('ul')
            ->allowElement('ol')
            ->allowElement('li')
            ->allowElement('p')
            ->allowElement('pre')
            ->allowElement('code')
            ->allowElement('blockquote')
            ->allowElement('strong')
            ->allowElement('em')
            ->allowElement('a', ['href'])
            ->allowElement('hr')
            ->allowElement('table')
            ->allowElement('thead')
            ->allowElement('tbody')
            ->allowElement('tr')
            ->allowElement('th')
            ->allowElement('td');
        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function render(string $markdown): string
    {
        return $this->sanitizer->sanitize($this->converter->convert($markdown)->getContent());
    }
}
