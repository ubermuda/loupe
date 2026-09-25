<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Install\BoardInstallFlags;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Module\SiteReview\SiteReviewDrawing;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\AgentCredential;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class SiteReviewApiTest extends WebTestCase
{
    use BoardColumnFixtures;

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /** @param non-empty-string $email */
    private function user(EntityManagerInterface $em, string $email): User
    {
        $user = new User(fullName: 'U', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $em->persist($user);

        return $user;
    }

    /**
     * @param non-empty-string $email
     *
     * @return array{0: string, 1: Project} an access token the widget carries, and the project it is bound to
     */
    private function projectWithToken(KernelBrowser $client, string $email, string $name = 'api-site'): array
    {
        $em = $this->em();
        $user = $this->user($em, $email);
        $project = new Project($user, $name);
        $em->persist($project);
        $em->flush();

        $raw = AgentCredential::tokenFor(static::getContainer(), $user, 'site-review', $project);

        return [$raw, AgentCredential::managed($em, $project, $project->id)];
    }

    /** @param array<string, mixed>|null $json */
    private function api(KernelBrowser $client, string $method, string $path, string $raw, ?array $json = null): void
    {
        $client->request($method, $path,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw, 'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://app.localhost'],
            content: null === $json ? null : json_encode($json, \JSON_THROW_ON_ERROR));
    }

    public function test_add_comment_creates_a_pending_comment(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, $project] = $this->projectWithToken($client, 'api-a@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw,
            ['body' => 'too big', 'selector' => '.card', 'text' => 'Save', 'url' => 'https://app/x']);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('commentId', $data);

        $pending = static::getContainer()->get(SiteReviewCommentRepository::class)->findPendingForProject($project);
        self::assertCount(1, $pending);
    }

    public function test_delivery_retries_keep_one_comment_and_refuse_changed_content(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, $project] = $this->projectWithToken($client, 'api-delivery@example.com');
        $payload = ['body' => 'Original delivery', 'url' => 'https://example.com', 'deliveryId' => (string) Uuid::v4()];
        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, $payload);
        self::assertResponseStatusCodeSame(201);
        $first = (string) $client->getResponse()->getContent();
        $commentId = json_decode($first, true, flags: \JSON_THROW_ON_ERROR)['commentId'];
        $this->api($client, Request::METHOD_PATCH, '/api/site-review/comments/'.$commentId, $raw, ['body' => 'Edited after saving']);
        self::assertResponseIsSuccessful();
        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, $payload);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($first, (string) $client->getResponse()->getContent());
        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, array_replace($payload, ['body' => 'Conflicting retry']));
        self::assertResponseStatusCodeSame(409);
        self::assertJsonStringEqualsJsonString('{"error":"delivery_conflict"}', (string) $client->getResponse()->getContent());
        $em = $this->em();
        self::assertTrue($em->isOpen());
        $em->clear();
        $stored = $em->find(SiteReviewComment::class, $commentId);
        self::assertInstanceOf(SiteReviewComment::class, $stored);
        self::assertSame('Edited after saving', $stored->body);
        self::assertSame(1, static::getContainer()->get(SiteReviewCommentRepository::class)->count(['project' => $project->id]));
    }

    public function test_invalid_delivery_identity_is_rejected_before_writing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, $project] = $this->projectWithToken($client, 'api-invalid-delivery@example.com');
        $payload = ['body' => 'A valid comment', 'url' => 'https://example.com'];
        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, $payload);
        self::assertResponseStatusCodeSame(201);
        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, $payload + ['deliveryId' => 'not-a-uuid']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, static::getContainer()->get(SiteReviewCommentRepository::class)->count(['project' => $project->id]));
    }

    public function test_the_embed_context_reaches_the_comment_and_a_blank_one_stores_null(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, $project] = $this->projectWithToken($client, 'api-context@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, [
            'body' => 'on the preview',
            'url' => 'https://preview/x',
            'context' => 'card:0199c0de-0000-7000-8000-000000000001',
        ]);
        self::assertResponseStatusCodeSame(201);

        // A deployment that renders the attribute with nothing in it must not
        // leave an empty string behind, which later reads like a real marker.
        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, [
            'body' => 'ordinary page',
            'url' => 'https://app/x',
            'context' => '   ',
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, [
            'body' => 'no attribute at all',
            'url' => 'https://app/y',
        ]);
        self::assertResponseStatusCodeSame(201);

        $pending = static::getContainer()->get(SiteReviewCommentRepository::class)->findPendingForProject($project);
        self::assertSame(
            ['card:0199c0de-0000-7000-8000-000000000001', null, null],
            array_map(static fn ($c) => $c->context, $pending),
        );
    }

    public function test_the_boot_load_names_what_the_page_marker_resolves_to(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, $project] = $this->projectWithToken($client, 'api-ctx-label@example.com');
        $em = $this->em();

        $flags = static::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[BoardInstallFlags::FLAG_BOARD_ENABLED]->value = true;
        $this->seedColumns($project);
        $card = new Card($project, $this->column($project, 'backlog'), 'Footer overlaps the launcher', 'body', 1);
        $em->persist($card);
        $em->flush();

        $this->api($client, Request::METHOD_GET, '/api/site-review/review?context='.urlencode('card:'.$card->id), $raw);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['context']);
        self::assertSame('#1 Footer overlaps the launcher', $data['context']['label']);

        // No marker at all is the ordinary deployment, and the key is present
        // as null rather than absent so the widget never has to tell the two
        // apart.
        $this->api($client, Request::METHOD_GET, '/api/site-review/review', $raw);
        $plain = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($plain);
        self::assertArrayHasKey('context', $plain);
        self::assertNull($plain['context']);
    }

    public function test_a_comment_can_point_at_several_elements(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, $project] = $this->projectWithToken($client, 'api-anchors@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, [
            'body' => 'These two belong side by side',
            'url' => 'https://app/x',
            'anchors' => [
                ['selector' => '.card', 'text' => 'Save'],
                ['selector' => '.panel', 'text' => 'Cancel'],
            ],
        ]);
        self::assertResponseStatusCodeSame(201);

        $pending = static::getContainer()->get(SiteReviewCommentRepository::class)->findPendingForProject($project);
        $anchors = array_values($pending[0]->anchors->toArray());
        self::assertCount(2, $anchors);
        self::assertSame(['.card', '.panel'], array_map(static fn ($a) => $a->selector, $anchors));
        self::assertSame([0, 1], array_map(static fn ($a) => $a->position, $anchors));
        self::assertNull($anchors[0]->quote);
    }

    public function test_a_comment_can_carry_a_freehand_drawing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, $project] = $this->projectWithToken($client, 'api-strokes@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, [
            'body' => 'Move this to the right',
            'url' => 'https://app/x',
            'anchors' => [['selector' => '.card', 'text' => 'Save']],
            'strokes' => [['space' => 'anchor', 'points' => [[0.1, 0.2], [0.9, 0.8]]]],
        ]);
        self::assertResponseStatusCodeSame(201);

        $pending = static::getContainer()->get(SiteReviewCommentRepository::class)->findPendingForProject($project);
        self::assertSame(
            [['space' => 'anchor', 'points' => [[0.1, 0.2], [0.9, 0.8]]]],
            $pending[0]->strokes,
        );

        $this->api($client, Request::METHOD_GET, '/api/site-review/review', $raw);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame(
            [['space' => 'anchor', 'points' => [[0.1, 0.2], [0.9, 0.8]]]],
            $data['comments'][0]['strokes'],
        );
    }

    /**
     * A widget cached from before the flag went off still offers Draw. Its save
     * has to be refused, so the reviewer is told, rather than accepted with the
     * drawing dropped on the floor.
     */
    public function test_strokes_are_refused_while_drawing_is_off(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, $project] = $this->projectWithToken($client, 'api-drawing-off@example.com');
        $em = $this->em();
        // The migration seeds the row, so this moves it rather than creating it.
        $flags = static::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[SiteReviewDrawing::FLAG]->value = false;
        $em->flush();

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, [
            'body' => 'Move this to the right',
            'url' => 'https://app/x',
            'strokes' => [['space' => 'page', 'points' => [[0.1, 0.2], [0.3, 0.4]]]],
        ]);
        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('drawing_disabled', $data['error']);

        // A comment with no drawing is unaffected, and the boot load says the
        // control is gone so the widget stops offering it.
        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw,
            ['body' => 'a page note', 'url' => 'https://app/x']);
        self::assertResponseStatusCodeSame(201);
        self::assertCount(1, static::getContainer()->get(SiteReviewCommentRepository::class)->findPendingForProject($project));

        $this->api($client, Request::METHOD_GET, '/api/site-review/review', $raw);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertFalse($payload['drawingEnabled']);
    }

    /**
     * The widget picks the space from the anchors it is saving, so only another
     * client sends this. An anchor-space stroke measures against anchor 0, so
     * with no anchor it could be stored and never drawn again.
     */
    public function test_an_anchor_space_stroke_needs_an_anchor(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, $project] = $this->projectWithToken($client, 'api-orphan-stroke@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, [
            'body' => 'Look here',
            'url' => 'https://app/x',
            'strokes' => [['space' => 'anchor', 'points' => [[0.1, 0.2], [0.9, 0.8]]]],
        ]);
        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('anchor_stroke_without_anchor', $data['error']);
        self::assertCount(0, static::getContainer()->get(SiteReviewCommentRepository::class)->findPendingForProject($project));

        // The same stroke with an anchor to measure against is accepted.
        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, [
            'body' => 'Look here',
            'url' => 'https://app/x',
            'anchors' => [['selector' => '.card', 'text' => 'Save']],
            'strokes' => [['space' => 'anchor', 'points' => [[0.1, 0.2], [0.9, 0.8]]]],
        ]);
        self::assertResponseStatusCodeSame(201);
    }

    public function test_the_boot_load_names_a_valid_feedback_target(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, $project] = $this->projectWithToken($client, 'api-target-ok@example.com');
        $this->seedColumns($project);
        $em = $this->em();
        $review = new Card($project, $this->column($project, 'in-progress'), 'Review: /pricing', '', 1);
        $epic = new Card($project, $this->column($project, 'backlog'), 'Review: /checkout', '', 2, type: CardType::Epic);
        $em->persist($review);
        $em->persist($epic);
        $em->flush();

        $this->api($client, Request::METHOD_GET, '/api/site-review/review?context='.urlencode('card:'.$review->id), $raw);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('#1 Review: /pricing', $data['context']['label'] ?? null);
        self::assertStringContainsString((string) $review->id, $data['context']['url'] ?? '');
        self::assertTrue($data['feedbackAvailable']);
        self::assertSame((string) $project->id, $data['projectId']);

        $this->api($client, Request::METHOD_GET, '/api/site-review/review?context='.urlencode('epic:'.$epic->id), $raw);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('#2 Review: /checkout', $data['context']['label'] ?? null);
    }

    /**
     * Each refusal answers null, which sends the widget back to the mode
     * picker. A label for any of these would promise a save that fails.
     */
    public function test_the_boot_load_refuses_a_target_the_save_would_refuse(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, $project] = $this->projectWithToken($client, 'api-target-bad@example.com');
        [, $other] = $this->projectWithToken($client, 'api-target-other@example.com', 'other-site');
        $this->seedColumns($project);
        $this->seedColumns($other);
        $em = $this->em();
        $closed = new Card($project, $this->column($project, 'done'), 'Shipped', '', 1);
        $closedEpic = new Card($project, $this->column($project, 'done'), 'Old review', '', 2, type: CardType::Epic);
        $feature = new Card($project, $this->column($project, 'backlog'), 'Not an epic', '', 3);
        $foreign = new Card($other, $this->column($other, 'backlog'), 'Elsewhere', '', 1);
        foreach ([$closed, $closedEpic, $feature, $foreign] as $card) {
            $em->persist($card);
        }
        $em->flush();

        $refused = [
            'unknown card' => 'card:'.Uuid::v7(),
            'unknown epic' => 'epic:'.Uuid::v7(),
            'another project' => 'card:'.$foreign->id,
            'another project epic' => 'epic:'.$foreign->id,
            'terminal column' => 'card:'.$closed->id,
            'terminal epic' => 'epic:'.$closedEpic->id,
            'epic mode on a non-epic' => 'epic:'.$feature->id,
            'malformed' => 'epic:not-a-uuid',
        ];
        foreach ($refused as $case => $marker) {
            $this->api($client, Request::METHOD_GET, '/api/site-review/review?context='.urlencode($marker), $raw);
            $data = json_decode((string) $client->getResponse()->getContent(), true);
            self::assertIsArray($data);
            self::assertArrayHasKey('context', $data, $case);
            self::assertNull($data['context'], $case);
        }
    }

    public function test_the_boot_load_says_whether_feedback_can_be_saved(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw] = $this->projectWithToken($client, 'api-feedback-flag@example.com');
        $flags = static::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);

        $this->api($client, Request::METHOD_GET, '/api/site-review/review', $raw);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertTrue($data['feedbackAvailable']);

        $flags->findAllIndexed()[BoardInstallFlags::FLAG_BOARD_ENABLED]->value = false;
        $this->em()->flush();
        $this->api($client, Request::METHOD_GET, '/api/site-review/review', $raw);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertFalse($data['feedbackAvailable']);
    }

    public function test_the_boot_load_reports_drawing_on_for_an_untouched_instance(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw] = $this->projectWithToken($client, 'api-drawing-default@example.com');

        // Nothing has moved the flag, so this reads the value the migration
        // and the install seeder both write.
        $this->api($client, Request::METHOD_GET, '/api/site-review/review', $raw);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['drawingEnabled']);
    }

    public function test_a_comment_with_no_drawing_stores_null(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, $project] = $this->projectWithToken($client, 'api-no-strokes@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw,
            ['body' => 'a page note', 'url' => 'https://app/x']);
        self::assertResponseStatusCodeSame(201);

        $pending = static::getContainer()->get(SiteReviewCommentRepository::class)->findPendingForProject($project);
        self::assertNull($pending[0]->strokes);

        $this->api($client, Request::METHOD_GET, '/api/site-review/review', $raw);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame([], $data['comments'][0]['strokes']);
    }

    /**
     * Strokes are drawn on the page and rendered back onto it, so a malformed
     * payload has to be refused at the boundary rather than stored.
     *
     * @param array<string, mixed> $stroke
     */
    #[DataProvider('malformedStrokes')]
    public function test_a_malformed_drawing_is_refused(array $stroke): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw] = $this->projectWithToken($client, 'api-bad-strokes@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, [
            'body' => 'Look here',
            'url' => 'https://app/x',
            'strokes' => [$stroke],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedStrokes(): iterable
    {
        yield 'unknown space' => [['space' => 'screen', 'points' => [[0.1, 0.2], [0.3, 0.4]]]];
        yield 'a single point' => [['space' => 'page', 'points' => [[0.1, 0.2]]]];
        yield 'a point that is not a pair' => [['space' => 'page', 'points' => [[0.1], [0.3, 0.4]]]];
        yield 'a point that is not numeric' => [['space' => 'page', 'points' => [['a', 'b'], [0.3, 0.4]]]];
        yield 'a point far off the page' => [['space' => 'page', 'points' => [[0.1, 0.2], [999999.0, 0.4]]]];
    }

    /**
     * The widget script URL carries no version, so a browser can hold a
     * pre-anchors copy for a long time and still post the old body.
     */
    public function test_a_legacy_selector_body_becomes_one_anchor(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, $project] = $this->projectWithToken($client, 'api-legacy@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw,
            ['body' => 'old widget', 'selector' => '.hero h1', 'text' => 'Hello', 'url' => 'https://app/x']);
        self::assertResponseStatusCodeSame(201);

        $pending = static::getContainer()->get(SiteReviewCommentRepository::class)->findPendingForProject($project);
        $anchors = array_values($pending[0]->anchors->toArray());
        self::assertCount(1, $anchors);
        self::assertSame('.hero h1', $anchors[0]->selector);
        self::assertSame('Hello', $anchors[0]->text);
    }

    public function test_a_legacy_body_with_an_empty_selector_gets_no_anchor(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, $project] = $this->projectWithToken($client, 'api-legacy-note@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw,
            ['body' => 'a page note', 'selector' => '', 'text' => '', 'url' => 'https://app/x']);
        self::assertResponseStatusCodeSame(201);

        $pending = static::getContainer()->get(SiteReviewCommentRepository::class)->findPendingForProject($project);
        self::assertCount(0, $pending[0]->anchors);
    }

    public function test_anchors_win_over_a_legacy_selector_in_the_same_body(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, $project] = $this->projectWithToken($client, 'api-both@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, [
            'body' => 'both shapes',
            'selector' => '.legacy',
            'text' => 'Legacy',
            'url' => 'https://app/x',
            'anchors' => [['selector' => '.modern', 'text' => 'Modern']],
        ]);
        self::assertResponseStatusCodeSame(201);

        $pending = static::getContainer()->get(SiteReviewCommentRepository::class)->findPendingForProject($project);
        $anchors = array_values($pending[0]->anchors->toArray());
        self::assertCount(1, $anchors);
        self::assertSame('.modern', $anchors[0]->selector);
    }

    /**
     * The widget repeats its first anchor in the scalar pair, so an instance
     * that predates anchors[] still records the element. The current API must
     * read anchors[] and drop the repeat, rather than store it twice.
     */
    public function test_the_widget_shape_does_not_double_the_first_anchor(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, $project] = $this->projectWithToken($client, 'api-widget-shape@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, [
            'body' => 'These two belong side by side',
            'url' => 'https://app/x',
            'anchors' => [
                ['selector' => '.card', 'text' => 'Save'],
                ['selector' => '.panel', 'text' => 'Cancel'],
            ],
            'selector' => '.card',
            'text' => 'Save',
        ]);
        self::assertResponseStatusCodeSame(201);

        $pending = static::getContainer()->get(SiteReviewCommentRepository::class)->findPendingForProject($project);
        $anchors = array_values($pending[0]->anchors->toArray());
        self::assertCount(2, $anchors);
        self::assertSame(['.card', '.panel'], array_map(static fn ($a) => $a->selector, $anchors));
    }

    public function test_more_than_ten_anchors_is_rejected(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw] = $this->projectWithToken($client, 'api-cap@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, [
            'body' => 'too many',
            'url' => 'https://app/x',
            'anchors' => array_map(static fn (int $i) => ['selector' => '.e'.$i, 'text' => 'E'], range(1, 11)),
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function test_an_anchor_with_a_blank_selector_is_rejected(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw] = $this->projectWithToken($client, 'api-blank-anchor@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw,
            ['body' => 'blank', 'url' => 'https://app/x', 'anchors' => [['selector' => '', 'text' => 'E']]]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * A widget copy that predates anchors[] reads the scalar pair, so the
     * rehydrate response keeps repeating the first anchor there.
     */
    public function test_the_rehydrate_response_carries_anchors_and_the_legacy_scalars(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw] = $this->projectWithToken($client, 'api-rehydrate@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, [
            'body' => 'two elements',
            'url' => 'https://app/x',
            'anchors' => [['selector' => '.a', 'text' => 'A'], ['selector' => '.b', 'text' => 'B']],
        ]);
        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw,
            ['body' => 'a page note', 'url' => 'https://app/y']);

        $this->api($client, Request::METHOD_GET, '/api/site-review/review', $raw);
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertCount(2, $data['comments'][0]['anchors']);
        self::assertSame('.a', $data['comments'][0]['anchors'][0]['selector']);
        self::assertSame('.a', $data['comments'][0]['selector']);
        self::assertSame('A', $data['comments'][0]['text']);

        self::assertSame([], $data['comments'][1]['anchors']);
        self::assertSame('', $data['comments'][1]['selector']);
        self::assertSame('', $data['comments'][1]['text']);
    }

    /**
     * A grant names one project, and the header names another of the same
     * owner. The resolver refuses rather than ignoring the header, so the API
     * answers with the code the widget reads rather than an error page.
     */
    public function test_a_header_naming_another_project_reaches_no_site(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, $project] = $this->projectWithToken($client, 'api-b@example.com');
        $em = $this->em();
        $elsewhere = new Project($project->owner, 'api-b-elsewhere');
        $em->persist($elsewhere);
        $em->flush();

        $client->request(Request::METHOD_POST, '/api/site-review/comments', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'https://app.localhost',
            'HTTP_X_LOUPE_PROJECT' => (string) $elsewhere->id,
        ], content: json_encode(['body' => 'x', 'url' => 'https://app/x'], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('token_not_bound_to_site', json_decode((string) $client->getResponse()->getContent(), true)['error'] ?? null);
    }

    public function test_saved_comments_are_immediately_live(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, $project] = $this->projectWithToken($client, 'api-c@example.com');
        $em = $this->em();

        $this->api($client, Request::METHOD_GET, '/api/site-review/review', $raw);
        self::assertSame(
            // context is always present and null on a page with no marker, so
            // the widget never has to tell an absent key from a resolved one.
            ['drawingEnabled' => true, 'context' => null, 'comments' => []],
            json_decode((string) $client->getResponse()->getContent(), true),
        );

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, ['body' => 'one', 'url' => 'https://app/x']);
        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, ['body' => 'two', 'url' => 'https://app/y']);

        $this->api($client, Request::METHOD_GET, '/api/site-review/review', $raw);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertCount(2, $data['comments']);
        self::assertSame('one', $data['comments'][0]['body']);
        self::assertNotEmpty($data['comments'][0]['id']);

        // No send step: the POSTs alone put both comments on the agent's queue.
        $em->clear();
        $pending = static::getContainer()->get(SiteReviewCommentRepository::class)->findPendingForProject($project);
        self::assertCount(2, $pending);
    }

    public function test_the_submit_route_is_gone(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw] = $this->projectWithToken($client, 'api-e@example.com');

        // The GET on the same prefix must keep working — only the POST is gone.
        $this->api($client, Request::METHOD_POST, '/api/site-review/review/submit', $raw);
        self::assertResponseStatusCodeSame(404);
        $this->api($client, Request::METHOD_GET, '/api/site-review/review', $raw);
        self::assertResponseIsSuccessful();
    }

    public function test_edit_and_delete_pending_comment(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw] = $this->projectWithToken($client, 'api-d@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, ['body' => 'orig', 'url' => 'https://app/x']);
        $id = json_decode((string) $client->getResponse()->getContent(), true)['commentId'];

        $this->api($client, Request::METHOD_PATCH, '/api/site-review/comments/'.$id, $raw, ['body' => 'edited']);
        self::assertResponseIsSuccessful();

        $this->api($client, Request::METHOD_GET, '/api/site-review/review', $raw);
        self::assertSame('edited', json_decode((string) $client->getResponse()->getContent(), true)['comments'][0]['body']);

        $this->api($client, Request::METHOD_DELETE, '/api/site-review/comments/'.$id, $raw);
        self::assertResponseStatusCodeSame(204);

        $this->api($client, Request::METHOD_GET, '/api/site-review/review', $raw);
        self::assertSame([], json_decode((string) $client->getResponse()->getContent(), true)['comments']);
    }

    /**
     * The reviewer's own sign-off, reached with a widget token. The comment
     * keeps its row in the project, and leaves the list the widget holds,
     * because that list is the pending ones.
     */
    public function test_resolve_takes_a_comment_out_of_the_pending_list(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, $project] = $this->projectWithToken($client, 'api-resolve@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, ['body' => 'done with this', 'url' => 'https://app/x']);
        $id = json_decode((string) $client->getResponse()->getContent(), true)['commentId'];

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments/'.$id.'/resolve', $raw);
        self::assertResponseStatusCodeSame(204);

        $this->api($client, Request::METHOD_GET, '/api/site-review/review', $raw);
        self::assertSame([], json_decode((string) $client->getResponse()->getContent(), true)['comments']);

        // Resolved, not deleted: the owner can still see it and reopen it.
        $comments = static::getContainer()->get(SiteReviewCommentRepository::class);
        $resolved = $comments->findForProjectWithStatus($project, SiteReviewCommentStatus::Resolved);
        self::assertCount(1, $resolved);
        self::assertSame($id, (string) $resolved[0]->id);
    }

    /**
     * The second press has nothing pending to act on, and says so rather than
     * moving a comment the owner already signed off.
     */
    public function test_resolving_twice_reports_not_found(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw] = $this->projectWithToken($client, 'api-resolve-twice@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, ['body' => 'once', 'url' => 'https://app/x']);
        $id = json_decode((string) $client->getResponse()->getContent(), true)['commentId'];

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments/'.$id.'/resolve', $raw);
        self::assertResponseStatusCodeSame(204);

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments/'.$id.'/resolve', $raw);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * NotBlank accepts "0" by design, so a comment body of exactly that reaches
     * the controller as a valid payload and must not be mistaken for empty.
     */
    public function test_a_comment_body_of_zero_is_saved_and_editable(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw] = $this->projectWithToken($client, 'api-zero@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, ['body' => '0', 'url' => 'https://app/x']);
        self::assertResponseStatusCodeSame(201);
        $id = json_decode((string) $client->getResponse()->getContent(), true)['commentId'];

        $this->api($client, Request::METHOD_PATCH, '/api/site-review/comments/'.$id, $raw, ['body' => '0']);
        self::assertResponseIsSuccessful();

        $this->api($client, Request::METHOD_GET, '/api/site-review/review', $raw);
        self::assertSame('0', json_decode((string) $client->getResponse()->getContent(), true)['comments'][0]['body']);
    }

    public function test_cross_site_comment_is_not_reachable(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$rawA] = $this->projectWithToken($client, 'api-e@example.com', 'site-a');
        [$rawB] = $this->projectWithToken($client, 'api-f@example.com', 'site-b');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $rawA, ['body' => 'mine', 'url' => 'https://app/x']);
        $id = json_decode((string) $client->getResponse()->getContent(), true)['commentId'];

        $this->api($client, Request::METHOD_PATCH, '/api/site-review/comments/'.$id, $rawB, ['body' => 'hijack']);
        self::assertResponseStatusCodeSame(404);

        $this->api($client, Request::METHOD_DELETE, '/api/site-review/comments/'.$id, $rawB);
        self::assertResponseStatusCodeSame(404);

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments/'.$id.'/resolve', $rawB);
        self::assertResponseStatusCodeSame(404);
    }

    public function test_auth_matrix_and_validation(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();

        // No credential answers 401.
        $client->request(Request::METHOD_POST, '/api/site-review/comments', server: ['CONTENT_TYPE' => 'application/json'], content: '{"body":"x","url":"u"}');
        self::assertResponseStatusCodeSame(401);

        // An MCP credential answers 403, from the firewall before the controller.
        $user = $this->user($em, 'api-g@example.com');
        $mcpProject = new Project($user, 'api-g-site');
        $em->persist($mcpProject);
        $em->flush();
        $mcpRaw = AgentCredential::tokenFor(static::getContainer(), $user, 'mcp', $mcpProject);
        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $mcpRaw, ['body' => 'x', 'url' => 'u']);
        self::assertResponseStatusCodeSame(403);
        // The wrong-scope 403 carries a machine-readable JSON code rather than
        // the framework's HTML error page, so the widget tells it apart from the
        // 403 of a credential that reaches no site.
        self::assertSame('insufficient_scope', json_decode((string) $client->getResponse()->getContent(), true)['error'] ?? null);

        // A blank body answers 422, and a malformed comment id answers 404.
        [$raw] = $this->projectWithToken($client, 'api-h@example.com');
        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, ['body' => '  ', 'url' => 'https://app/x']);
        self::assertResponseStatusCodeSame(422);

        // The stored-XSS guard answers 422 for a javascript: URL.
        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, ['body' => 'x', 'url' => 'javascript:alert(1)']);
        self::assertResponseStatusCodeSame(422);
        $this->api($client, Request::METHOD_PATCH, '/api/site-review/comments/not-a-uuid', $raw, ['body' => 'x']);
        self::assertResponseStatusCodeSame(404);
    }

    public function test_preflight_carries_new_methods(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_OPTIONS, '/api/site-review/comments', server: ['HTTP_ORIGIN' => 'https://app.localhost']);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('GET, POST, PATCH, DELETE, OPTIONS', $client->getResponse()->headers->get('Access-Control-Allow-Methods'));
        self::assertSame('https://app.localhost', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_a_request_without_an_origin_is_granted_no_origin(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_OPTIONS, '/api/site-review/comments');

        // A wildcard fallback here would hand the API to every page on the web.
        self::assertResponseStatusCodeSame(204);
        self::assertFalse($client->getResponse()->headers->has('Access-Control-Allow-Origin'));
    }
}
