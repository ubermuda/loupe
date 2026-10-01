<?php

declare(strict_types=1);

namespace App\Command;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Inbox\Service\Dev\ProjectShowcaseSeeder;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Fills a development project with enough content to judge a page by eye.
 *
 * `app:dev:seed` stays the minimum needed to sign in, because a worktree runs
 * it on every entry. This command is the opposite. It writes a whole project
 * worth of work, and a person runs it by hand.
 *
 * The writing itself lives in ProjectShowcaseSeeder, which spans the modules a
 * request links to and may name them. This command resolves the account and
 * the project, which is all the root namespace is allowed to know.
 */
#[AsCommand(
    name: 'app:dev:seed-showcase',
    description: 'Fill a dev project with cards, documents, inbox requests and site feedback for looking at the design.',
)]
#[When('dev')]
final class SeedShowcaseDataCommand extends Command
{
    private const string EMAIL = 'dev@loupe.test';
    private const string ADMIN_EMAIL = 'admin@loupe.test';
    private const string PROJECT_NAME = 'Dev Project';

    public function __construct(
        private readonly UserRepository $users,
        private readonly ProjectRepository $projects,
        private readonly ProjectShowcaseSeeder $showcase,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption(
            'project',
            null,
            InputOption::VALUE_REQUIRED,
            'The name of the project to fill.',
            self::PROJECT_NAME,
        );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $option = $input->getOption('project');
        $name = \is_string($option) && '' !== $option ? $option : self::PROJECT_NAME;

        $owner = $this->users->findOneBy(['email' => self::EMAIL]);
        if (!$owner instanceof User) {
            $io->error(\sprintf('No %s account. Run app:dev:seed first.', self::EMAIL));

            return Command::FAILURE;
        }

        $project = $this->projectNamed($owner, $name);
        if (!$project instanceof Project) {
            $io->error(\sprintf('%s owns no project named "%s". Run app:dev:seed first.', self::EMAIL, $name));

            return Command::FAILURE;
        }

        // The second account replies and records a verdict, so a thread reads
        // as a conversation rather than as one person talking to themselves.
        $reviewer = $this->users->findOneBy(['email' => self::ADMIN_EMAIL]) ?? $owner;

        $seeding = ($this->showcase)($project, $owner, $reviewer);
        if (!$seeding->inboxEnabled) {
            $io->warning('Loupe opened no wait item, because inbox.enabled is off. Switch the flag on at /admin/feature-flags, then run this command again.');
        } elseif (!$seeding->waitItemOpen) {
            $io->note(\sprintf('The "%s" card has no open wait item. Its tech design is no longer in review, the card left Tech design, or someone dismissed the item.', ProjectShowcaseSeeder::WAITING_CARD_TITLE));
        }
        if ([] !== $seeding->syncCards) {
            $io->writeln(\sprintf('Added %d cards that show each sync status of a pull request:', \count($seeding->syncCards)));
            foreach ($seeding->syncCards as $path) {
                $io->writeln('  '.$path);
            }
        }
        if (!$seeding->written) {
            $io->note(\sprintf('"%s" already holds the showcase, so this run added no %s data.', $name, [] === $seeding->syncCards ? 'other' : 'inbox, document or feedback'));

            return Command::SUCCESS;
        }

        $io->success(\sprintf('Filled "%s" with 7 cards, 3 documents, %d requests and 4 site comments.', $name, 9 + $seeding->waitItems));
        $io->writeln('  /projects/'.$project->id.'/inbox');
        $io->writeln('  /projects/'.$project->id.'/inbox?queue=completed');
        $io->writeln('  /projects/'.$project->id.'/board');

        return Command::SUCCESS;
    }

    private function projectNamed(User $owner, string $name): ?Project
    {
        foreach ($this->projects->findBy(['owner' => $owner]) as $project) {
            if ($project->name === $name) {
                return $project;
            }
        }

        return null;
    }
}
