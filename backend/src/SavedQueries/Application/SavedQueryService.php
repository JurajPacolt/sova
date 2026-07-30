<?php

declare(strict_types=1);

namespace Sova\SavedQueries\Application;

use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Application\AuthorizationSubject;
use Sova\Authorization\Domain\Permission;
use Sova\Issues\Application\Search\IssueSearchService;
use Sova\SavedQueries\Domain\SavedQueryAccess;
use Sova\SavedQueries\Domain\SavedQueryName;
use Sova\SavedQueries\Domain\SavedQueryVisibility;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;

/**
 * Creating, editing, sharing and archiving saved queries.
 *
 * Two rules run through all of it. Only a query the language accepts may be
 * stored, and the canonical form is produced by the server — the client never
 * dictates it, or two people could save the "same" query under two spellings
 * and the cursor hash would stop agreeing with itself.
 *
 * And sharing is *not* access: a grant lets somebody run the query, never see
 * an issue they otherwise could not. The results are intersected with the
 * reader's own `issue.view` scope every time the query runs, so a shared query
 * legitimately returns different rows to different people.
 */
final readonly class SavedQueryService
{
    /** The width of `saved_queries.name`. */
    private const int MAX_NAME_LENGTH = 160;

    /**
     * How far `availableName()` counts before giving up. A member with fifty
     * queries of the same name is not being helped by a fifty-first.
     */
    private const int MAX_NAME_CANDIDATES = 50;

    public function __construct(
        private SavedQueryRepository $queries,
        private IssueSearchService $search,
        private AuthorizationService $authorization,
        private SavedQueryUsageProbe $usage,
        private SecurityAuditRecorder $audit,
    ) {}

    /**
     * @return list<SavedQuery>
     */
    public function listVisible(
        AuthorizationSubject $subject,
        string $tenantId,
        string $membershipId,
    ): array {
        return $this->queries->listVisible(
            $tenantId,
            $membershipId,
            $this->canAdminister($subject, $tenantId),
        );
    }

    public function get(
        AuthorizationSubject $subject,
        string $tenantId,
        string $savedQueryId,
        string $membershipId,
    ): SavedQuery {
        $query = $this->queries->find(
            $tenantId,
            $savedQueryId,
            $membershipId,
            $this->canAdminister($subject, $tenantId),
        );

        if ($query === null) {
            throw $this->notFound();
        }

        return $query;
    }

    /**
     * @return list<SavedQueryGrant>
     */
    public function grants(
        AuthorizationSubject $subject,
        string $tenantId,
        string $savedQueryId,
        string $membershipId,
    ): array {
        // Reaching the query at all is the gate; the grant list says who else
        // holds it, which a reader of the query may legitimately know.
        $this->get($subject, $tenantId, $savedQueryId, $membershipId);

        return $this->queries->listGrants($tenantId, $savedQueryId);
    }

    /**
     * @param list<string> $defaultColumns
     */
    public function create(
        AuthorizationSubject $subject,
        string $tenantId,
        string $membershipId,
        string $name,
        string $description,
        string $rawQuery,
        array $defaultColumns,
    ): string {
        $this->authorization->require(
            $subject,
            Permission::SavedQueryCreate,
            AuthorizationScope::tenant($tenantId),
        );

        $canonical = $this->canonicalise($subject, $tenantId, $rawQuery);
        $this->assertNameIsFree($tenantId, $membershipId, $name, null);

        $savedQueryId = (string) UuidV7::generate();

        $this->queries->create(
            $tenantId,
            $savedQueryId,
            $membershipId,
            trim($name),
            $description,
            $rawQuery,
            $canonical,
            $defaultColumns,
            // Private until somebody explicitly shares it.
            SavedQueryVisibility::Private_,
        );

        return $savedQueryId;
    }

    /**
     * A name near the one asked for that this owner does not already use.
     *
     * Queries seeded from a template propose their names, and a member who
     * receives that template twice would collide with their own earlier copy.
     * The uniqueness rule is this module's, so the search for a free name
     * belongs here rather than in whoever is doing the seeding.
     */
    public function availableName(
        string $tenantId,
        string $membershipId,
        string $preferred,
    ): string {
        $base = trim($preferred);

        for ($suffix = 1; $suffix <= self::MAX_NAME_CANDIDATES; ++$suffix) {
            $candidate = $suffix === 1 ? $base : $this->suffixed($base, $suffix);

            if ($this->queries->nameIsFree(
                $tenantId,
                $membershipId,
                SavedQueryName::normalize($candidate),
                null,
            )) {
                return $candidate;
            }
        }

        throw $this->nameTaken();
    }

    /**
     * @param list<string> $defaultColumns
     */
    public function update(
        AuthorizationSubject $subject,
        string $tenantId,
        string $savedQueryId,
        string $membershipId,
        int $expectedVersion,
        string $name,
        string $description,
        string $rawQuery,
        array $defaultColumns,
    ): void {
        $query = $this->get($subject, $tenantId, $savedQueryId, $membershipId);

        if ($query->archived) {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'SAVED_QUERY_ARCHIVED',
                'An archived query can no longer be edited.',
            );
        }

        if (!$query->viewerAccess->allowsEditing()) {
            throw $this->denied();
        }

        $canonical = $this->canonicalise($subject, $tenantId, $rawQuery);
        // Uniqueness is the owner's, not the editor's: a grant holder editing
        // somebody else's query must not collide with their own names.
        $this->assertNameIsFree(
            $tenantId,
            $query->ownerMembershipId,
            $name,
            $savedQueryId,
        );

        $version = $this->queries->update(
            $tenantId,
            $savedQueryId,
            $expectedVersion,
            trim($name),
            $description,
            $rawQuery,
            $canonical,
            $defaultColumns,
            // Visibility changes through the grant endpoint, never through a
            // content edit, so an editor cannot quietly publish a query.
            $query->visibility,
        );

        if ($version === null) {
            throw $this->versionConflict();
        }
    }

    public function archive(
        AuthorizationSubject $subject,
        string $tenantId,
        string $savedQueryId,
        string $membershipId,
        int $expectedVersion,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress = null,
    ): void {
        $query = $this->get($subject, $tenantId, $savedQueryId, $membershipId);

        if ($query->archived) {
            return;
        }

        // Archiving is the owner's decision, or an administrator's; holding
        // EDIT is not enough to retire somebody else's query.
        if (!$query->viewerIsOwner && !$this->canAdminister($subject, $tenantId)) {
            throw $this->denied();
        }

        // Retiring a query a widget still renders would leave somebody's
        // dashboard pointing at something withdrawn. The widget has to go
        // first. The count travels in the detail rather than in `errors`,
        // because field errors belong to validation problems and this is a
        // conflict — but a bare refusal would leave the person hunting.
        $usages = $this->usage->countUsages($tenantId, $savedQueryId);

        if ($usages > 0) {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'SAVED_QUERY_IN_USE',
                sprintf(
                    'The query still feeds %d dashboard %s.',
                    $usages,
                    $usages === 1 ? 'widget' : 'widgets',
                ),
            );
        }

        if ($this->queries->archive($tenantId, $savedQueryId, $expectedVersion) === null) {
            throw $this->versionConflict();
        }

        $this->recordAudit(
            'SAVED_QUERY_ARCHIVED',
            'SAVED_QUERY_ARCHIVED',
            $tenantId,
            $savedQueryId,
            $actorUserId,
            $requestId,
            $ipAddress,
            [
                'visibility' => $query->visibility->value,
                'owned_by_actor' => $query->viewerIsOwner,
            ],
        );
    }

    /**
     * @param list<array{membership_id: ?string, workgroup_id: ?string, access: string}> $grants
     */
    public function replaceGrants(
        AuthorizationSubject $subject,
        string $tenantId,
        string $savedQueryId,
        string $membershipId,
        array $grants,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress = null,
    ): void {
        $query = $this->get($subject, $tenantId, $savedQueryId, $membershipId);
        $administrator = $this->canAdminister($subject, $tenantId);

        if (!$query->viewerIsOwner && !$administrator) {
            throw $this->denied();
        }

        if (!$administrator) {
            $this->authorization->require(
                $subject,
                Permission::SavedQueryShare,
                AuthorizationScope::tenant($tenantId),
            );
        }

        $validated = $this->validatedGrants($tenantId, $grants);
        $this->queries->replaceGrants($tenantId, $savedQueryId, $validated, $actorUserId);

        $members = 0;
        $workgroups = 0;

        foreach ($validated as $grant) {
            if ($grant['membership_id'] !== null) {
                ++$members;

                continue;
            }

            ++$workgroups;
        }

        $this->recordAudit(
            'SAVED_QUERY_SHARED',
            $validated === [] ? 'SAVED_QUERY_UNSHARED' : 'SAVED_QUERY_SHARED',
            $tenantId,
            $savedQueryId,
            $actorUserId,
            $requestId,
            $ipAddress,
            [
                'grant_count' => count($validated),
                'member_grant_count' => $members,
                'workgroup_grant_count' => $workgroups,
                'owned_by_actor' => $query->viewerIsOwner,
            ],
        );
    }

    public function setFavourite(
        AuthorizationSubject $subject,
        string $tenantId,
        string $savedQueryId,
        string $membershipId,
        bool $favourite,
    ): void {
        // Favouriting requires nothing beyond being able to see the query; it
        // is a personal bookmark, not a change to the query itself.
        $this->get($subject, $tenantId, $savedQueryId, $membershipId);
        $this->queries->setFavourite($tenantId, $savedQueryId, $membershipId, $favourite);
    }

    /**
     * Sharing and retiring are decisions somebody may have to answer for later,
     * so both land in the tenant's security log.
     *
     * The metadata deliberately carries **no name and no query text**. The log
     * is read with `tenant.audit.view`, which is not the same right as seeing a
     * private query — recording what somebody called their filter would hand
     * that content to every administrator through the back door. Identifiers
     * and counts say what happened without saying what it was about.
     *
     * @param array<string, bool|int|string|null> $metadata
     */
    private function recordAudit(
        string $eventType,
        string $reasonCode,
        string $tenantId,
        string $savedQueryId,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
        array $metadata,
    ): void {
        $this->audit->record(
            eventType: $eventType,
            outcome: 'SUCCESS',
            reasonCode: $reasonCode,
            requestId: $requestId,
            actorUserId: $actorUserId,
            tenantId: $tenantId,
            ipAddress: $ipAddress,
            metadata: ['saved_query_id' => $savedQueryId] + $metadata,
        );
    }

    /**
     * @param list<array{membership_id: ?string, workgroup_id: ?string, access: string}> $grants
     *
     * @return list<array{membership_id: ?string, workgroup_id: ?string, access: SavedQueryAccess}>
     */
    private function validatedGrants(string $tenantId, array $grants): array
    {
        $memberships = [];
        $workgroups = [];

        foreach ($grants as $grant) {
            if ($grant['membership_id'] !== null) {
                $memberships[] = $grant['membership_id'];
            }

            if ($grant['workgroup_id'] !== null) {
                $workgroups[] = $grant['workgroup_id'];
            }
        }

        $active = $this->queries->activePrincipals($tenantId, $memberships, $workgroups);
        $validated = [];

        foreach ($grants as $grant) {
            $access = SavedQueryAccess::tryFrom($grant['access']);
            $membershipId = $grant['membership_id'];
            $workgroupId = $grant['workgroup_id'];

            if ($access === null || ($membershipId === null) === ($workgroupId === null)) {
                throw $this->invalidGrant();
            }

            // A grant may only name an active member or group of this tenant.
            // Anything else answers the same way, so the endpoint cannot be
            // used to find out who exists elsewhere.
            $known = $membershipId !== null
                ? in_array($membershipId, $active['memberships'], true)
                : in_array($workgroupId, $active['workgroups'], true);

            if (!$known) {
                throw $this->invalidGrant();
            }

            $validated[] = [
                'membership_id' => $membershipId,
                'workgroup_id' => $workgroupId,
                'access' => $access,
            ];
        }

        return $validated;
    }

    /**
     * Only a query the language accepts may be stored, and the canonical form
     * is the server's — never the client's.
     */
    private function canonicalise(
        AuthorizationSubject $subject,
        string $tenantId,
        string $rawQuery,
    ): string {
        $validation = $this->search->validate($subject, $tenantId, $rawQuery);

        if (!$validation->valid || $validation->canonical === null) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'SAVED_QUERY_INVALID',
                'Only a valid query can be saved.',
                ['query' => array_values(array_unique(array_map(
                    static fn($error): string => $error->code->value,
                    $validation->errors,
                )))],
            );
        }

        return $validation->canonical;
    }

    private function assertNameIsFree(
        string $tenantId,
        string $ownerMembershipId,
        string $name,
        ?string $exceptSavedQueryId,
    ): void {
        $normalized = SavedQueryName::normalize($name);

        if ($normalized === '') {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'SAVED_QUERY_NAME_INVALID',
                'Give the query a name.',
                ['name' => ['Give the query a name.']],
            );
        }

        if (!$this->queries->nameIsFree(
            $tenantId,
            $ownerMembershipId,
            $normalized,
            $exceptSavedQueryId,
        )) {
            throw $this->nameTaken();
        }
    }

    /**
     * Appends the counter without letting the result outgrow the column, so a
     * long name loses its tail rather than the whole write failing.
     */
    private function suffixed(string $base, int $suffix): string
    {
        $tail = ' ' . $suffix;

        return mb_substr($base, 0, self::MAX_NAME_LENGTH - mb_strlen($tail)) . $tail;
    }

    private function nameTaken(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::Conflict,
            'SAVED_QUERY_NAME_TAKEN',
            'A query of that name already exists.',
        );
    }

    private function canAdminister(AuthorizationSubject $subject, string $tenantId): bool
    {
        return $this->authorization->isGranted(
            $subject,
            Permission::SavedQueryManage,
            AuthorizationScope::tenant($tenantId),
        );
    }

    private function notFound(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ResourceNotFound,
            'SAVED_QUERY_NOT_FOUND',
            'The saved query was not found.',
        );
    }

    private function versionConflict(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::Conflict,
            'SAVED_QUERY_VERSION_CONFLICT',
            'The query was changed in the meantime. Reload and try again.',
        );
    }

    private function invalidGrant(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ValidationFailed,
            'SAVED_QUERY_GRANT_INVALID',
            'A grant must name one active member or workgroup of this tenant.',
            ['grants' => ['A grant must name one active member or workgroup of this tenant.']],
        );
    }

    private function denied(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::PermissionDenied,
            'PERMISSION_DENIED',
            'You do not have permission to perform this operation.',
        );
    }
}
