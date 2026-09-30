<?php

declare(strict_types=1);

namespace App\Module\Billing\Service\Dev;

use App\Command\DevDataSeederInterface;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Billing\Command\Admin\GrantCompCommand;
use App\Module\Billing\Command\Admin\GrantCompHandler;
use App\Module\Billing\Entity\BetaInvite;
use App\Module\Billing\Repository\BetaInviteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * One beta invite in each state, and a comped tester who used one. The note
 * is the key: a note that exists already means that invite is seeded.
 */
#[When('dev')]
final readonly class BetaInviteDevSeeder implements DevDataSeederInterface
{
    public const string TESTER_EMAIL = 'beta@loupe.test';

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private BetaInviteRepository $betaInvites,
        private GrantCompHandler $grantComp,
        private UserPasswordHasherInterface $passwordHasher,

        #[Autowire(param: 'app.terms.version')]
        private string $termsVersion,
    ) {
    }

    #[\Override]
    public function seed(User $admin): void
    {
        $tester = $this->tester();

        $this->invite($admin, 'reddit u/preview-unused');
        $this->invite($admin, 'reddit u/preview-used')?->redeem($tester);
        $this->invite($admin, 'reddit u/preview-revoked')?->revoke();
        $this->em->flush();

        try {
            ($this->grantComp)(new GrantCompCommand($tester, $admin));
        } catch (DomainErrors) {
            // The tester holds a comp from an earlier run.
        }
    }

    /** @return BetaInvite|null the new invite, or null when the note is seeded already */
    private function invite(User $admin, string $note): ?BetaInvite
    {
        if (null !== $this->betaInvites->findOneBy(['note' => $note])) {
            return null;
        }

        [$invite] = BetaInvite::issue($admin, $note);
        $this->em->persist($invite);

        return $invite;
    }

    private function tester(): User
    {
        $tester = $this->users->findOneBy(['email' => self::TESTER_EMAIL]);
        if ($tester instanceof User) {
            return $tester;
        }

        $tester = new User(fullName: 'Beta Tester', email: self::TESTER_EMAIL);
        $tester->password = $this->passwordHasher->hashPassword($tester, 'password');
        $tester->emailVerifiedAt = new \DateTimeImmutable();
        $tester->termsAcceptedAt = new \DateTimeImmutable();
        $tester->termsVersion = $this->termsVersion;
        $this->em->persist($tester);
        $this->em->flush();

        return $tester;
    }
}
