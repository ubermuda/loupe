<?php

declare(strict_types=1);

namespace App\Command;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Project\Command\MintProjectWidgetTokenCommand;
use App\Module\Project\Command\MintProjectWidgetTokenHandler;
use App\Module\Project\Command\RegenerateProjectWidgetTokenCommand;
use App\Module\Project\Command\RegenerateProjectWidgetTokenHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Populates an empty development database with the minimum needed to log in
 * and look at the app.
 *
 * This exists for per-worktree databases, which start empty: without a user
 * there is nothing to see, and without a widget token matching
 * SITE_REVIEW_WIDGET_TOKEN the annotation widget loads straight into its
 * rejected-token fatal state.
 *
 * Tokens are stored as sha256(raw) and ApiToken::issue() generates its own
 * random value, so a token with a predetermined raw value cannot be created
 * without adding a backdoor to production code. Instead this mints a fresh one
 * and prints it; worktree-bootstrap.sh captures that and writes it into the
 * worktree's .env.local.
 *
 * Lives in the root namespace rather than a module because it spans two of
 * them (Account and Project), and modules must not depend on each other.
 */
#[AsCommand(
    name: 'app:dev:seed',
    description: 'Seed an empty dev database with a verified user, a project, and a widget token.',
)]
final class SeedDevDataCommand extends Command
{
    private const string EMAIL = 'dev@loupe.test';
    private const string PASSWORD = 'password';
    private const string PROJECT_NAME = 'Dev Project';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly ProjectRepository $projects,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly MintProjectWidgetTokenHandler $mintWidgetToken,
        private readonly RegenerateProjectWidgetTokenHandler $regenerateWidgetToken,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption(
            'reissue-widget-token',
            null,
            InputOption::VALUE_NONE,
            'Replace the existing widget token so its raw value can be printed again.',
        );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Idempotent: bootstrap re-runs on every worktree entry, and re-seeding
        // must not create a second user or a second widget token (minting one
        // twice is rejected outright by the handler).
        $user = $this->users->findOneBy(['email' => self::EMAIL]);
        if (!$user instanceof User) {
            $user = new User(fullName: 'Dev User', email: self::EMAIL);
            $user->password = $this->passwordHasher->hashPassword($user, self::PASSWORD);
            // Verified up front — there is no inbox to click through, and an
            // unverified user cannot reach the pages worth looking at.
            $user->emailVerifiedAt = new \DateTimeImmutable();
            $this->em->persist($user);
            $this->em->flush();
        }

        $project = $this->projects->findOneBy(['owner' => $user, 'name' => self::PROJECT_NAME]);
        if (!$project instanceof Project) {
            $project = new Project($user, self::PROJECT_NAME);
            $this->em->persist($project);
            $this->em->flush();
        }

        // Only the raw value is usable by an embedder, and only the hash is
        // stored — so once minted it cannot be recovered. When the caller has
        // lost it (a deleted .env.local on a re-run), reissuing is the only way
        // back to a working widget; otherwise the worktree would keep serving a
        // token that matches no row.
        $rawToken = null;
        if (null === $project->widgetToken) {
            $rawToken = ($this->mintWidgetToken)(new MintProjectWidgetTokenCommand($project));
        } elseif ($input->getOption('reissue-widget-token')) {
            $rawToken = ($this->regenerateWidgetToken)(new RegenerateProjectWidgetTokenCommand($project));
        }

        if (null !== $rawToken) {
            // The ONLY line the caller parses — bootstrap reads it to populate
            // SITE_REVIEW_WIDGET_TOKEN. Keep the prefix stable.
            $output->writeln('SITE_REVIEW_WIDGET_TOKEN='.$rawToken);
        }

        $io->success(sprintf('Seeded %s (password: %s).', self::EMAIL, self::PASSWORD));

        return Command::SUCCESS;
    }
}
