<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260830152618 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Backfill one subscription per billing profile from its status columns';
    }

    public function up(Schema $schema): void
    {
        // Each branch reproduces the access the old status-based rule gave.
        // An `active` profile with no usable period end becomes open-ended, so
        // it keeps its access until the next webhook writes a real end. Only a
        // real deletion honours a canceled profile's paid period, because
        // `incomplete` also stores as `canceled` and never went live.
        $this->addSql(<<<'SQL'
            INSERT INTO subscriptions (
                id, billing_profile_id, kind, starts_at, ends_at,
                stripe_subscription_id, stripe_status, survey_sent_at, created_at
            )
            SELECT
                gen_random_uuid(),
                p.id,
                CASE WHEN p.status = 'trialing' THEN 'trial' ELSE 'stripe' END,
                p.created_at,
                CASE
                    WHEN p.status = 'trialing' THEN p.trial_ends_at
                    WHEN p.status = 'active' THEN
                        CASE WHEN p.current_period_end > n.at THEN p.current_period_end END
                    WHEN p.status = 'past-due' THEN COALESCE(p.current_period_end, n.at)
                    WHEN p.last_stripe_event_type = 'customer.subscription.deleted' THEN
                        COALESCE(p.current_period_end, n.at)
                    ELSE n.at
                END,
                CASE WHEN p.status = 'trialing' THEN NULL ELSE p.stripe_subscription_id END,
                CASE WHEN p.status = 'trialing' THEN NULL ELSE p.status END,
                CASE WHEN p.status = 'trialing' THEN p.survey_sent_at ELSE p.cancel_survey_sent_at END,
                p.created_at
            FROM billing_profiles p
            CROSS JOIN (SELECT NOW() AT TIME ZONE 'UTC' AS at) n
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM subscriptions');
    }
}
