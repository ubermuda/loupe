<?php

declare(strict_types=1);

namespace App\Module\Inbox\Twig;

use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Form\AnswerInboxItemFormType;
use App\Module\Inbox\Form\AnswerInboxItemRequest;
use App\Module\Inbox\Form\DeclineInboxItemFormType;
use App\Module\Inbox\Form\DeclineInboxItemRequest;
use App\Module\Inbox\Form\MarkInboxItemDoneFormType;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Project\Entity\Project;
use App\Module\Review\Service\MarkdownRenderer;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The inbox page renders up to three forms per item, so they are built here.
 * Each carries the item's own name, and a refused form comes back in place of
 * a fresh one only when the name matches.
 */
final class InboxExtension extends AbstractExtension
{
    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly MarkdownRenderer $markdown,
        private readonly InboxItemRepository $inboxItems,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('inbox_answer_form', $this->answerForm(...)),
            new TwigFunction('inbox_done_form', $this->doneForm(...)),
            new TwigFunction('inbox_decline_form', $this->declineForm(...)),
            new TwigFunction('inbox_refusals', $this->refusals(...)),
            new TwigFunction('project_inbox_open_count', $this->openCount(...)),
        ];
    }

    #[\Override]
    public function getFilters(): array
    {
        return [
            // No heading ids: an item shows beside a document whose own headings carry them.
            new TwigFilter('inbox_markdown', $this->markdown->renderWithoutHeadingIds(...), ['is_safe' => ['html']]),
        ];
    }

    public function answerForm(InboxItem $item, ?FormView $refused = null): FormView
    {
        $name = AnswerInboxItemFormType::nameFor($item);
        if (null !== $refused && $refused->vars['name'] === $name) {
            return $refused;
        }

        $selected = implode(',', $item->selectedOptions);

        return $this->formFactory
            ->createNamed($name, AnswerInboxItemFormType::class, new AnswerInboxItemRequest($selected, $item->answerText))
            ->createView();
    }

    public function doneForm(InboxItem $item, ?FormView $refused = null): FormView
    {
        $name = MarkInboxItemDoneFormType::nameFor($item);
        if (null !== $refused && $refused->vars['name'] === $name) {
            return $refused;
        }

        return $this->formFactory->createNamed($name, MarkInboxItemDoneFormType::class)->createView();
    }

    public function declineForm(InboxItem $item, ?FormView $refused = null): FormView
    {
        $name = DeclineInboxItemFormType::nameFor($item);
        if (null !== $refused && $refused->vars['name'] === $name) {
            return $refused;
        }

        return $this->formFactory
            ->createNamed($name, DeclineInboxItemFormType::class, new DeclineInboxItemRequest($item->closeNote))
            ->createView();
    }

    /**
     * The messages of a refused form of this item, root and fields alike.
     *
     * A refusal that makes an item final leaves no form on the page to carry
     * its errors, so the item shows them on its own.
     *
     * @return list<string>
     */
    public function refusals(InboxItem $item, ?FormView $refused = null): array
    {
        $names = [AnswerInboxItemFormType::nameFor($item), MarkInboxItemDoneFormType::nameFor($item), DeclineInboxItemFormType::nameFor($item)];
        if (null === $refused || !\in_array($refused->vars['name'], $names, true)) {
            return [];
        }

        $messages = [];
        foreach ([$refused, ...$refused->children] as $view) {
            foreach ($view->vars['errors'] ?? [] as $error) {
                if ($error instanceof FormError) {
                    $messages[] = $error->getMessage();
                }
            }
        }

        return array_values(array_unique($messages));
    }

    /** Feeds the sidebar pill, which shows only while the project has open items. */
    public function openCount(Project $project): int
    {
        return $this->inboxItems->countOpenByProject($project);
    }
}
