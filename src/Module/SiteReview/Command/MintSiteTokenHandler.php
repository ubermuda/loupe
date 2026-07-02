<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class MintSiteTokenHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return non-empty-string the raw token, shown to the user exactly once
     */
    public function __invoke(MintSiteTokenCommand $command): string
    {
        $site = $command->site;
        if (null !== $site->token) {
            $this->logger->info('site_review.site.token_mint_rejected', [
                'siteId' => (string) $site->id,
            ]);
            throw new DomainErrors(['token' => 'site_review.error.token_already_minted']);
        }

        [$token, $raw] = ApiToken::issue($site->owner, 'Site: '.$site->name, ApiTokenScope::SiteReview);
        $site->token = $token;
        $this->em->persist($token);
        $this->em->flush();

        $this->logger->info('site_review.site.token_minted', [
            'siteId' => (string) $site->id,
            'tokenId' => (string) $token->id,
        ]);

        return $raw;
    }
}
