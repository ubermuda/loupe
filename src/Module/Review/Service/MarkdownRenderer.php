<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use League\CommonMark\CommonMarkConverter;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final readonly class MarkdownRenderer
{
    private CommonMarkConverter $converter;
    private HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $this->converter = new CommonMarkConverter(['html_input' => 'allow', 'allow_unsafe_links' => false]);
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
