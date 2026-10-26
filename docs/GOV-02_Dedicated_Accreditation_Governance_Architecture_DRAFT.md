# GOV-02 Dedicated Accreditation Governance Technical Design Lock

**Document version:** TECHNICAL DESIGN LOCK v1.1

**Approval status:** Option B and this technical contract are locked for design review. Capability activation, LPMI/LPME responsibility allocation, institutional operating authority, and implementation authorization remain **PENDING INSTITUTIONAL DECISION**.

**Baseline reviewed:** `312350bad765f26e7308f36f1f7eb2bd89c98b26`

**Decision context:** Option B, dedicated accreditation governance. Super Admin remains the temporary LED operational administrator. Future LPMI and LPME mandates are not approved or implemented.

**Change summary, v1.1:** Split the former broad delegation-management capability into request, approve, revoke, and audit-view capabilities; normalized stored lifecycle states and derived timing conditions; removed deferred supersession behavior; added technical design invariants, a pending-decision boundary, and a final consistency-review checklist. Final lock review also fixed scope-tuple algebra, monitoring entry/disclosure boundaries, lifecycle edge cases, command receipts, audit-use distinctions, and source-traceability wording.

**Open institutional decisions:** See Section 13. No decision in this document itself provisions authority.

## 1. Executive Summary

The current application has a suitable authorization foundation for future project-scoped delegation. It combines global Spatie RBAC with domain checks for active account, project membership, criterion assignment, LED cycle, active LED assignment, project lifecycle, and workflow state. Phase 2 also has actor-attributed cycle/review events, evidence provenance, and cross-scope relational constraints.

There is no current LPMI/LPME role, organizational authorization, delegation grant, or approved responsibility split. A future implementation must therefore add a narrow delegation overlay rather than reinterpret global roles, Project Manager membership, LED assignments, or organizational-unit records as authority.

The proposed model has these core properties:

- A grant is project-bound, capability-specific, time-bound, revocable, and independently approved.
- A grant does not create a global role, technical administration privilege, document-review exception, or cross-project visibility.
- Organizational affiliation is optional provenance only. A user is not authorized merely because they belong to LPMI or LPME.
- Existing Super Admin and active Project Manager paths remain unchanged until an explicit capability migration is approved.
- Existing lifecycle locks, project/criterion/cycle/indicator scope, reviewer independence, and evidence provenance remain mandatory after delegation is introduced.
- Grant lifecycle facts and decisions are recorded in a dedicated append-only ledger; the general workflow activity log is not the authority source.

All future LPMI/LPME responsibility allocation in this document is **PENDING INSTITUTIONAL DECISION**.

## 2. Current Architecture

### 2.1 Confirmed source facts

| Contract | Existing enforcement | Existing extension point | Design implication |
|---|---|---|---|
| Global RBAC | Five seeded roles and global permissions are defined in `database/seeders/AccreditationRolePermissionSeeder.php:12-52`. Spatie teams are not used for project scoping. | Permission seeder and authorization services. | A delegation grant must not be a Spatie role or global permission assignment. |
| Active identity | HTTP routes use `auth` and `active`; persistent Livewire middleware and `Gate::before` deny inactive users. | `app/Http/Middleware/EnsureUserIsActive.php:12-30`; `app/Providers/AppServiceProvider.php:61-66`. | Every grant decision and use must require an active authenticated actor. |
| Project and criterion scope | Super Admin has all-project scope. Project Managers and Viewers require active project membership; PIC/Reviewer require active criterion assignment. | `app/Services/AccreditationAuthorizationService.php:19-192`. | Delegation must be bounded by the same project and must not replace membership or criterion assignment where those remain required. |
| LED cycle authority | Cycle oversight and management require Super Admin or active Project Manager plus current permissions and lifecycle conditions. | `app/Services/LedAssessmentAuthorizationService.php:89-107`; cycle policy delegates to this service. | A future capability check should be introduced as a narrow alternative only after approval, not by weakening policy scope. |
| Instrument publication | Publication currently requires authenticated Super Admin plus `project.manage`, draft status, source-file hash, manifest hash, and complete rubric data. | `app/Services/LedInstrumentVersionService.php:73-110`. | Publication is high impact and should not be delegated in the first release. |
| Cycle create/activate | Create requires project management and a published matching version. Activation requires an active primary assessor and independent reviewer per assessable indicator. | `app/Services/LedAssessmentCycleService.php:27-128`. | Preserve lifecycle locks and readiness checks for all delegated cycle operations. |
| LED assignments | Manager-only assignment validates actor identity, active target account, project visibility, cycle/version/indicator scope, unique lead, and reviewer independence. Revocation/replacement require reason. | `app/Services/LedAssessorAssignmentService.php:20-209`. | Delegation must not permit an authority to bypass assessor/reviewer conflict rules. |
| Indicator-item mapping | Manager-only mapping requires a mutable cycle, assessable indicator, same project, same criterion, an item with an existing document requirement, and no duplicate active mapping. | `app/Services/LedIndicatorItemMappingService.php:22-159`. | Mapping management and mapping validation must be separate future capabilities if institutional policy requires independent validation. |
| Documents and verification | Upload, submission, review, and verification have separate document and criterion checks. Super Admin, submitter, and any uploader of a document attached to the submission are excluded from review decisions. | `app/Services/AccreditationAuthorizationService.php:194-305`. | Delegation cannot override document reviewer independence or grant document access implicitly. |
| Assessment workflow | Draft, submit, review, revision request, and approval require active LED assignment and workflow-state checks. Reviewer cannot be creator or submitter. | `app/Services/LedAssessmentAuthorizationService.php:116-161`; `app/Services/LedAssessmentWorkflowService.php:23-240`. | Delegated governance does not replace indicator work assignments or approval independence. |
| Evidence | Linking is draft-only and requires assessor update authority, verified Phase 1 provenance, same project, active mapping, and stored-file SHA-256 verification. Explorer disclosure separately applies document/verification authorization. | `app/Services/LedAssessmentEvidenceService.php:24-115`; `app/Livewire/LedAssessment/RelationshipExplorer.php:136-165`. | Evidence monitoring can be delegated only through a separately approved read-disclosure contract; evidence linking remains an assessor workflow operation. |
| Relationship Explorer | Explorer checks project/cycle authorization, limits non-oversight users to assigned indicators, and separately applies Phase 1 criterion/document access. | `app/Livewire/LedAssessment/RelationshipExplorer.php:44-195,254-269`. | Monitor capability must not reveal document or criterion details beyond existing authorization. |
| Lifecycle lock | Only active projects permit workflow mutations. Some current services lock/recheck the project inside transactions; others apply lifecycle authorization without that project-row lock. | `app/Services/AccreditationProjectLifecycleService.php:10-31`; relevant service-specific transaction paths. | A valid grant never overrides project or cycle lifecycle locks; every future delegated mutation must recheck lifecycle authority after its own locks. |
| Audit records | Administrative audit blocks application-model updates/deletes. LED cycle/review events block application-model updates/deletes. General activity logs are not immutable at model level. | `app/Models/AdministrativeAuditLog.php:23-27`; `app/Models/AccreditationLedCycleEvent.php:23-27`; `app/Models/AccreditationLedReviewEvent.php:24-28`; `app/Models/AccreditationActivityLog.php:7-20`. | Delegation needs its own authoritative append-only ledger; activity logs can be a secondary operational feed only. |

### 2.2 Existing privilege separation

- Super Admin is the only supported user and role administrator. `user.manage` and `role.manage` are reserved from assignment to other roles through the supported role-management service.
- Super Admin is deliberately excluded from document review decisions, even if it also has Reviewer role membership.
- Project Manager can manage existing project-scoped LED operations but cannot create users, alter global roles, or edit global permissions.
- PIC Kriteria, document Reviewer, LED primary/supporting assessor, and LED reviewer are distinct work roles with separate scope and independence checks.
- Exact Viewer users only receive operational LED route access when an active LED assignment exists; this does not create document workflow rights.

### 2.3 DB-18 boundary

DB-18 is a production-approved locked contract. Its DDL contains project/instrument/cycle/indicator composite foreign keys, active-only uniqueness for primary assignments and mappings, revocation metadata, and LED evidence provenance. It must not be modified by GOV-02. See `AMS_APU_DB18_LOCKED_DDL.sql:1-10,133-347` and `docs/db18-migration-recovery.md:1-44`.

The DB-18 `qa_organizational_units` table may later be referenced as optional organizational provenance if approved, but it must not become an implicit authorization source. No current record or code establishes LPMI/LPME authority.

## 3. Capability Catalogue

Capability names are provisional controlled vocabulary, not current Spatie permissions. A future catalog must version each definition and prevent arbitrary capability strings.

| Capability | Business purpose and permitted operations | Existing prerequisite and allowed scope | Authorization and separation conditions | Audit obligation | Required negative tests |
|---|---|---|---|---|---|
| `accreditation.monitor` | Read delegated project/cycle monitoring data through a future delegation-aware read surface. No workflow write, document download, evidence link, or implied verified-evidence count. | Existing `project.view` remains required. The future read surface must use a non-authorizing base enrollment, such as existing Viewer role plus active project viewer membership, and must not reuse the current `operational` route path for an exact Viewer without an LED assignment. | Active actor; effective grant; matching project/scope tuple; existing document/criterion/review checks remain separately required. Without those checks, return only restricted/no-detail state. | Grant lifecycle events are mandatory. Successful read telemetry is optional only if institutional policy classifies the view as sensitive. | Cross-project URL, criterion detail, document-download, verified-count inference, expired-grant, revoked-grant, and existing-route bypass denial. |
| `led.cycle.manage` | Create, prepare, activate, and later manage approved LED cycles. No instrument publication or assessment approval. | Today the direct path requires `project.manage` and Super Admin or active Project Manager. Future creation requires project scope; management of an existing cycle may use project or exact cycle scope. | Preserve active project, matching published version, cycle state, readiness, locks, and independent assessor/reviewer requirements. Grant cannot bypass these controls. | Grant lifecycle event; existing cycle event/activity event must retain actor and grant correlation. | Cross-project/cycle tampering, cycle-scoped create attempt, inactive project, missing independent reviewer, expired/revoked grant, and audit-write rollback. |
| `led.assignment.manage` | Create, replace, and revoke lead/supporting/reviewer assignments for a delegated cycle. | Today requires `assignment.manage` plus cycle-manager authority. Scope: project or exact cycle; never indicator-only for this administrative capability. | Target must remain active with `project.view`; existing reviewer independence and one-primary rule remain mandatory. | Mandatory reason for replacement/revocation; grant ID added to existing cycle event payload; delegation event records decision and use. | Self-beneficial grant use where prohibited by policy, conflicting assessor/reviewer target, cross-cycle indicator, revoked grant during operation, and duplicate command. |
| `led.mapping.manage` | Create and revoke indicator-item mappings. | Today requires cycle-manager authority. Scope: project, cycle, or criterion-within-cycle if such scope is approved. | Same-project and same-criterion checks remain mandatory. Does not validate academic suitability or grant evidence/document access. | Existing mapping cycle event and project activity event plus grant correlation. Revocation reason remains mandatory. | Cross-project/cross-criterion mapping, duplicate active mapping, expired/revoked grant, and direct Livewire action denial. |
| `led.mapping.validate` | Independently attest or reject mapping suitability after mapping proposal. | No current matching operation, policy, state, or table exists. Proposed scope: project + cycle + criterion or indicator. | Validator must differ from mapping creator and any person who exercises `led.mapping.manage` on the same mapping, unless an approved exception process exists. | Append-only validation decision with basis/reference, actor, time, and mapping revision identity. | Self-validation, cross-scope validation, duplicate/concurrent decision, and validation-audit failure. |
| `led.evidence.monitor` | Read only the restricted/no-detail evidence state already permitted by the future delegation-aware read surface. It does not expose document count, verified-submission count, linked-evidence count, document metadata, or provenance detail unless the actor separately passes existing document and review authorization. | Existing `project.view`; exact project/cycle/indicator scope. A derived evidence-disclosure model beyond current Explorer restrictions is not defined and must not be activated. | Active actor; effective grant; no bypass of criterion/document/review access; no disclosure beyond the restricted existing response. | Grant lifecycle events are mandatory. Successful read telemetry is optional only if institutional policy classifies the view as sensitive. | Direct document access, verified-count inference, cross-criterion disclosure, expired/revoked grant, and route/Livewire bypass denial. |
| `led.instrument.publish` | Publish a verified LED instrument version. | Today requires Super Admin plus `project.manage`; version is instrument-scoped rather than project-scoped. | Preserve source hash, manifest hash, rubric completeness, draft-only state, and independent approval policy if introduced. Must not be initially delegated. | Current administrative audit records publication status but sanitizes hash fields and has no correlation field. A future delegated publication must use the dedicated ledger with a non-sensitive immutable source/manifest reference; it must not claim current administrative audit stores hashes. | Unauthorized actor, source/manifest mismatch, and grant misuse outside an approved future instrument-scoped model. |
| `accreditation.delegation.request` | Create draft and submit a request for one named grantee, one allowlisted capability/version, exact project-bound scope, validity interval, and reason. It cannot decide, revoke, or audit other grants. | No current equivalent. Proposed scope is the requested project plus allowed narrower resource scope. | Requester may not create all-project, technical-administration, unknown-capability, or transitive grants. Self-request is **PENDING INSTITUTIONAL DECISION**; self-approval is always denied. | Immutable draft/request event, requester, grantee, capability/version, scope, reason, command and grant correlation IDs. | Unknown capability, missing/multiple grantee, cross-project scope, duplicate replay with changed hash, and requester-as-approver denial. |
| `accreditation.delegation.approve` | Independently approve to `authorized` or reject a requested grant. It cannot request for itself, revoke, or grant technical authority. | No current equivalent. Proposed scope is the request project; exact institutional approver designation is **PENDING INSTITUTIONAL DECISION**. | Approver must be active, directly designated for that project/capability, and distinct from requester and grantee. Approval cannot modify requested capability, scope, or grantee; material changes require a new request. | Immutable decision event with approver, decision, reason/reference, pre-state version, and correlation IDs. | Self-approval, approving own request/grant, stale/double decision, out-of-scope approval, and audit-write rollback. |
| `accreditation.delegation.revoke` | Revoke an `authorized` grant with immediate effect and preserve history. It cannot approve requests, create replacement authority, or alter past actions. | No current equivalent. Proposed scope is the grant project; exact institutional revoker designation is **PENDING INSTITUTIONAL DECISION**. | Revoker must be active and directly designated for the grant/project. Revocation cannot benefit the revoker personally; it never bypasses ordinary separation rules. | One immutable revocation event with mandatory reason, effective instant, pre-state version, and correlation IDs. | Self-beneficial revocation, cross-project grant, revoke rejected/draft grant, concurrent revoke/use, and repeated revoke without duplicate event. |
| `accreditation.delegation.audit.view` | Read the dedicated delegation ledger and approved grant metadata for authorized governance oversight. It cannot request, approve, revoke, or read protected domain documents by implication. | No current equivalent. Proposed scope is project-bound ledger visibility; any institution-wide review requires explicit approval. | Active actor; effective capability; scope-filtered queries; no authority inferred from organizational membership. | Sensitive audit-view access is itself logged if approved policy requires it; no mutation is permitted. | Cross-project ledger enumeration, confidential payload disclosure, direct URL/Livewire bypass, and audit-view capability implying another action. |

### 3.1 Capability activation policy

No capability implies another capability. No capability is automatically delegated when the model is introduced. Recommended activation order is monitoring, then narrow cycle/assignment operations, then mapping operations. Mapping validation, instrument publication, and all delegation-decision capabilities remain disabled until their separate governance workflows and institutional approvers are approved.

### 3.2 Delegation-management capability boundaries

| Capability | Effective authority | Explicit exclusions |
|---|---|---|
| `accreditation.delegation.request` | Allows only creation/submission of a request with one named grantee and exact requested scope. A request is never effective authority. | Cannot authorize, reject, revoke, amend a submitted request, assign a role, or provision a grant. |
| `accreditation.delegation.approve` | Allows an independently designated actor to authorize or reject an in-scope `requested` grant. The result is stored `authorized` or `rejected`. | Cannot approve own/requester/grantee request, revise scope/capability/grantee, revoke, or create a transitive grant. |
| `accreditation.delegation.revoke` | Allows an independently designated actor to revoke an in-scope `authorized` grant with immediate future effect. | Cannot approve, replace, extend, backdate, erase history, or create a second revocation event. |
| `accreditation.delegation.audit.view` | Allows scope-filtered read-only inspection of the dedicated grant/event ledger. | Cannot request, approve, reject, revoke, alter records, or grant access to domain documents/evidence. |

Exact institutional designation of requester, approver, and revoker is **PENDING INSTITUTIONAL DECISION**. The technical boundary is nevertheless fixed: no one of these capabilities implies another, and no grant is provisioned by default.

## 4. Delegation Lifecycle

### 4.1 Stored and derived states

The lifecycle must avoid persisting synonymous or contradictory states. Approval is a decision event; `authorized` is the only persisted state resulting from an approval.

| Term | Proposed representation | Meaning |
|---|---|---|
| DRAFT | Stored `draft` | Optional private preparation. Never authorizes an operation. |
| REQUESTED | Stored `requested` | Submitted for a defined approval authority. Never authorizes an operation. |
| APPROVED | Decision event that transitions to stored `authorized` | Approval is a decision, not a second active status. |
| AUTHORIZED | Stored `authorized` | Approved grant whose future or current authority is determined from the validity interval. |
| SCHEDULED | Derived `not_yet_effective` | `authorized` grant evaluated before its inclusive effective-start instant. It never authorizes use. |
| ACTIVE | Derived `effective_now` | `authorized`, not revoked, and evaluated at or after effective start but before effective end. It can authorize only after all other authorization checks pass. |
| EXPIRED | Derived | `authorized` grant evaluated at or after its effective-end instant. It remains historical and never reactivates. |
| REVOKED | Stored terminal `revoked` | Authority is withdrawn immediately with mandatory actor, timestamp, and reason. |
| REJECTED | Stored terminal `rejected` | Request was declined. A new request is required; rejection is never overwritten. |

Supersession is deliberately deferred from v1.1. A replacement authority is a new request/grant with explicit provenance to the earlier grant if required by policy. It does not introduce a `superseded` lifecycle state or alter the earlier grant.

### 4.2 Proposed transitions

- `draft -> requested`: requester submits purpose, capability, exact scope, validity period, and mandatory justification.
- `requested -> authorized`: independent approver accepts the exact requested capability, grantee, scope tuple, and validity interval; records an approval decision and source reference. Any material change requires a new request.
- `requested -> rejected`: independent approver records a mandatory rejection reason.
- `authorized -> revoked`: a designated revoker records mandatory reason while the grant is scheduled or active; effect is immediate on the next authorization check and after transaction recheck. An expired grant cannot take this transition.
- Terminal states never transition back to requested or authorized. Corrections create a new request and link it by provenance.
- A requester may abandon a `draft` before submission; it never creates a grant. If draft retention is enabled, abandonment is recorded as a non-authorizing draft event without adding a lifecycle state. A `requested` grant has a mandatory decision deadline. When an effective end is present, the deadline is no later than that end; when null expiry is separately approved, policy still supplies an explicit decision deadline. An undecided lapsed request is rejected with a controlled `request_validity_lapsed` reason. It can never become `authorized` after the requested interval has lapsed.

### 4.3 Timing, precedence, and terminal-state rules

- Time is stored and compared as timezone-safe UTC instants. Authorization obtains one authoritative evaluation instant per command and uses that same instant before and after locks. Application and database clock-source policy is **PENDING INSTITUTIONAL DECISION**, but mixed local-time comparisons are prohibited.
- Effective start is inclusive: an `authorized` grant is not yet effective when `now < effective_start`, and is eligible for effective authority when `now >= effective_start`.
- Effective end is exclusive: a grant is expired when `effective_end` is present and `now >= effective_end`. This half-open interval, `[effective_start, effective_end)`, prevents two adjacent grants from overlapping at the boundary.
- Null effective end is not implicitly perpetual authority. Whether a capability may have null expiry, its maximum duration, and default expiry are **PENDING INSTITUTIONAL DECISION**. Until approved, a future implementation should deny a request that omits expiry for a capability that has no approved null-expiry rule.
- Approval-time validation requires a non-empty interval with `effective_start < effective_end`. An approver cannot backdate, shorten, extend, or otherwise alter the requested interval. A null end is rejected unless the capability has an approved null-expiry rule.
- Precedence is fail-closed: `rejected` and `revoked` always deny authority regardless of dates; `requested` and `draft` always deny; `authorized` is scheduled, active, or expired only by its evaluated interval. Revocation overrides only scheduled or active authority immediately. Once expired, a grant remains historically `authorized`, is no longer revocable, and cannot be edited, extended, or reused; a new request is required.
- `rejected` and `revoked` are terminal and immutable. Expiry is a terminal authority condition rather than a stored lifecycle status; it creates no later revocation event and cannot be used to reactivate a grant by editing dates.
- Request replay is idempotent only for the same command ID and canonical request hash. The same ID with a different hash fails closed. Independently submitted equivalent requests are subject to the duplicate/effective-scope constraint and institutional overlap policy.
- Revocation is idempotent. The first accepted revoke command appends the sole revocation event and changes the grant to `revoked`; replay of the same command returns the committed result. A later distinct revoke command returns the terminal state and creates no second revocation event.
- Expiry and revocation are distinct: expiry is derived from time and preserves the `authorized` historical state; revocation is an actor-attributed terminal decision with a mandatory reason and immediate effect.
- One named grantee may have at most one matching effective grant for a capability and operation target at an instant. Scope-overlap is evaluated by the fixed scope tuple and its hierarchy: project covers every resource in that project; cycle covers its own cycle; criterion covers its own criterion; indicator covers its own cycle/version/indicator. Any two authorized intervals whose scope tuples can cover the same operation must not overlap. If integrity failure nevertheless returns more than one matching grant, authorization fails closed rather than choosing one.

### 4.4 Roles in the lifecycle

| Role | Proposed responsibility |
|---|---|
| Requester | Proposes a grant for a grantee and supplies business reason, scope, dates, and supporting reference. May be the prospective grantee only if policy allows self-request; self-approval remains prohibited. |
| Grantee | Receives no authority until the request is authorized and effective. Cannot approve or revoke for personal benefit. |
| Delegator | The institutionally recognized source of authority for the capability and project. The exact person/unit model is **PENDING INSTITUTIONAL DECISION**. |
| Approver | Independently authorizes or rejects the request through `accreditation.delegation.approve`. Must be active, directly authorized for the project/capability, and distinct from requester/grantee. |
| Revoker | Withdraws an authorized grant through `accreditation.delegation.revoke` under approved policy. Must be independent where revocation affects their own benefit. |
| Emergency approver | Not enabled by default. If approved, issues a separately marked, short-lived emergency grant with mandatory reason and post-event independent review. It never bypasses scope, lifecycle, or assessor/reviewer independence rules. |

### 4.5 Idempotency, concurrency, and transactional rechecks

- Every lifecycle command uses a client or server command identifier and canonical request hash.
- The same command ID and same hash returns the committed result; the same ID with a different hash fails closed.
- Creation, decision, revocation, and use of a grant lock the relevant grant/request, project, scope resources, actor authority source, and command receipt in deterministic order.
- Authorization is evaluated before the transaction for fast denial and repeated after locks immediately before mutation.
- A revocation, expiry, account deactivation, role/membership loss, project lifecycle change, or conflict discovered before the post-lock check denies the operation.
- There is no grace period after expiry or revocation unless a future approved emergency capability explicitly defines one.

## 5. Authorization Decision Model

### 5.1 Fail-closed decision sequence

For a future delegated mutation, the authorization engine must perform the following ordered checks and deny if any check fails:

1. Authenticate the actor and verify the supplied actor matches the authenticated user.
2. Verify that the actor account is active.
3. Resolve the target through its complete project/cycle/criterion/indicator lineage; reject cross-project or fabricated identifiers before returning protected details.
4. Apply current direct authorization unchanged. Existing Super Admin and active Project Manager paths retain their present behavior until a capability is explicitly migrated.
5. For a delegated path, load exactly one named-grantee grant for the actor, exact allowlisted capability/catalog version, and permitted server-validated scope tuple. More than one matching grant is an integrity failure and denies the operation.
6. Verify project visibility and every existing prerequisite that remains intrinsic to the operation, including project lifecycle, cycle state, document scope, assessment assignment, and evidence provenance.
7. Verify effective period, derived scheduled/active/expired condition, terminal state, revocation, grantor/approver provenance, and no disqualifying conflict or recusal.
8. Verify operation-specific separation of duties, including independent reviewer requirements.
9. Lock all authorization facts and target records, then repeat checks 2 through 8 inside the transaction.
10. Mutate only after the repeated authorization succeeds; append the domain event, delegation-use correlation, and command result in the same transaction.

### 5.2 Required invariants

- Organizational unit membership, a role name, a project membership, an LED assignment, or an SSO claim alone is never a delegation grant.
- A grant is additive and capability-specific; it never implies `user.manage`, `role.manage`, account administration, role assignment, permission assignment, password reset, broad document access, or Super Admin status.
- Grants are non-transitive by default. A grantee cannot issue a grant merely because they hold one.
- Grant scope cannot exceed the approver's direct institutional authority and cannot cross project boundaries.
- A project grant may cover a narrower allowed resource only when the capability catalog expressly permits hierarchical coverage. Each grant carries one conjunctive scope tuple, not a union of independent scope rows. Wildcard all-project grants are prohibited.
- Existing reviewer and assessor independence restrictions are not delegable and are evaluated after grant validation.
- A future grant must not silently convert a user into a Phase 1 PIC, Reviewer, Project Manager, or LED assessor. Those assignments retain their existing workflows and audit trails.
- No default grant exists. A missing, draft, requested, rejected, revoked, scheduled, expired, malformed, or scope-mismatched grant denies the delegated path.

### 5.3 Proposed integration boundaries

- Add future delegation-aware authorization methods beside `AccreditationAuthorizationService` and `LedAssessmentAuthorizationService`; do not place policy decisions in Livewire components or routes.
- Retain thin policies that delegate to central services, following existing cycle and revision policy patterns.
- Keep a dedicated delegation route/policy/service separate from SuperAdmin-only user and role administration. Do not broaden `UserPolicy` or `UserAccessManagementService` to make delegation work.
- Preserve `auth`, `active`, persistent Livewire middleware, direct URL policy checks, and service-level transaction rechecks.
- Provide a future delegation-aware monitoring read surface separate from current `operational` routing. It must require a non-authorizing project base enrollment and the effective monitor grant, then apply existing criterion/document/review disclosure checks without creating an LED assignment or document capability.

### 5.4 Technical Design Invariants

These are technical architecture recommendations ready for technical review. They do not allocate institutional operating authority.

- Fail closed: delegated authority exists only for an active authenticated named grantee with one effective allowlisted capability grant.
- Project-scoped authorization: every grant and target resource resolves to one matching project; cross-project delegation and all-project wildcard grants are denied.
- No global technical privilege escalation: delegation never grants global roles, `user.manage`, `role.manage`, user administration, role/permission administration, account lifecycle control, or SuperAdmin status.
- No implicit organizational authority: LPMI/LPME affiliation, organizational-unit association, project membership, LED assignment, and SSO claims are not grants.
- No self-approval: requester and grantee cannot approve the request; grant use does not create power to request, approve, or revoke another grant unless the actor separately holds that exact capability.
- Independent reviewer rules remain non-waivable: assessor/reviewer and creator/submitter/reviewer restrictions continue after any grant is accepted.
- Immutable audit-event history: request, decision, revocation, and delegated state-changing-use events are append-only, retain immutable grant/command correlation IDs, and are written atomically with affected mutations. Read telemetry remains policy-controlled.
- No bypass of document verification or evidence provenance: delegation cannot expose restricted documents or bypass verified-submission, active-mapping, project-scope, or hash checks.

### 5.5 Institutional Decisions Pending

These are policy choices, not current or proposed automatic authorities.

- **PENDING INSTITUTIONAL DECISION:** Formal LPMI and LPME mandates and whether either unit receives any capability.
- **PENDING INSTITUTIONAL DECISION:** Eligible delegation approvers and revokers for each capability and project.
- **PENDING INSTITUTIONAL DECISION:** Capability ownership, permitted scope shapes, and whether Project Managers may request or delegate any capability.
- **PENDING INSTITUTIONAL DECISION:** The approved non-authorizing base enrollment for delegated monitoring, including whether existing Viewer role/project membership is the institutional model.
- **PENDING INSTITUTIONAL DECISION:** Maximum duration, default expiry, and whether null expiry is allowed for each capability.
- **PENDING INSTITUTIONAL DECISION:** Self-request and self-assignment policy for governance administrators.
- **PENDING INSTITUTIONAL DECISION:** Independent academic mapping-validation policy and its approval/rejection effects.
- **PENDING INSTITUTIONAL DECISION:** Evidence requirements before LED submission or approval.
- **PENDING INSTITUTIONAL DECISION:** Emergency delegation authority, duration, retrospective review, and permitted capabilities.
- **PENDING INSTITUTIONAL DECISION:** Audit retention, legal hold, privileged database controls, and tamper-evidence standard.

## 6. Proposed Logical Data Model

This is a logical model only. It is not an executable migration, SQL contract, or authorization implementation.

### 6.1 Delegation grant

**Identity and cardinality:** One immutable grant aggregate belongs to one project, exactly one named grantee user, one controlled allowlisted capability definition/version, and exactly one conjunctive scope tuple. A user and project can each have many historical grants. A group, unit, role, or free-text identity cannot be a grantee.

**Required fields:** Internal ID, immutable UUID, project ID, grantee user ID, capability code, capability catalog version, lifecycle status, requester ID/time, business reason, request decision deadline, effective start, optional effective end, authority/delegator reference, approval source reference, state version, created time, and correlation ID.

**Decision/revocation fields:** Requester ID/time, authorizer ID/time, authorization reason/reference, rejector ID/time/reason when rejected, revoker ID/time/reason when revoked, immutable root grant correlation ID, current state version, and immutable command correlation IDs in related events.

**Uniqueness and indexing:**

- Unique UUID.
- Scope-overlap exclusion preventing two authorized grants for the same grantee and capability version from covering the same operation target during overlapping intervals. A project tuple overlaps every narrower tuple in that project; cycle/criterion/indicator overlap follows their validated parent lineage.
- Indexes beginning with `(grantee_user_id, project_id, capability_code, status, effective_start, effective_end)` for authorization decisions.
- Indexes beginning with `(project_id, capability_code, status, effective_start, effective_end)` for governance review.
- Validity constraint: effective end is null only where capability policy permits it; otherwise it is strictly after effective start. Zero-length intervals are invalid.

**Immutability and lifecycle:** Approval facts, scope, capability version, requester, grantee, effective interval, and original reason are immutable after authorization. A correction or replacement creates a new request/grant and records a provenance link; it does not edit prior approval facts or introduce an implicit supersession state.

### 6.2 Grant scope tuple

**Identity and cardinality:** One grant has exactly one conjunctive scope tuple, not a set of independent scope rows. The tuple always includes `project_id`; optional narrower fields are validated together and cannot broaden each other by union.

**Allowed shapes:**

- Project tuple: `project_id` only.
- Cycle tuple: `project_id + cycle_id + instrument_version_id`, with version consistency.
- Criterion tuple: `project_id + criterion_id`.
- Indicator tuple: `project_id + cycle_id + instrument_version_id + indicator_id`.

The capability catalog determines permitted tuple shapes. A cycle-less indicator, a criterion from another project, an empty scope, an all-project wildcard, and a tuple that mixes unsupported dimensions are invalid. A capability may permit a project tuple to cover a narrower target only where the catalog explicitly says so.

**Query and integrity expectations:** Resolve through project parent records and existing composite project/cycle/version/indicator relationships before resource access. Index each supported tuple shape. At authorization time, calculate coverage and overlap using the hierarchy defined in Section 4.3; never union a project tuple with an unrelated criterion, cycle, or indicator row.

### 6.3 Approval decision

**Identity and cardinality:** One request has one terminal approve-or-reject decision executed through `accreditation.delegation.approve`. A new request is required after rejection or after a material change.

**Required fields:** Request/grant ID, decision type, independent actor ID, occurred time, reason, authority source/reference, policy/catalog version, correlation ID, and prior-state version.

**Immutability:** Decision records are append-only. A rejected or authorized decision is never edited; subsequent correction is a new decision event on a new request.

### 6.4 Revocation event

**Identity and cardinality:** A revoked grant has one terminal revocation event. Replacement is a new request/grant; no supersession event or status is defined in v1.1.

**Required fields:** Grant ID, actor ID, mandatory reason, occurred time, effective time, authority reference, and correlation ID.

**Semantics:** Revocation removes future authority immediately. It does not rewrite historic actions correctly performed before revocation. In-flight mutations must fail if revocation is observed during the post-lock authorization recheck.

### 6.5 Command receipt

**Identity and cardinality:** One command receipt exists for each accepted or completed governance command. It is separate from a correlation ID and is the authoritative idempotency record for request, approve/reject, revoke, and delegated state-changing use.

**Required fields:** Globally unique command ID, canonical request hash, actor ID, command type, target grant/request ID when known, project ID, received time, committed outcome/result reference, root grant correlation ID when known, and completion time.

**Uniqueness and semantics:** Command ID is globally unique. Replaying the same command ID with the same hash returns the committed outcome; replaying it with a different hash fails closed. A receipt is created/locked in the same transaction as the lifecycle/domain mutation and its authoritative event. It is never fulfilled by nullable/non-unique DB-18 correlation columns.

### 6.6 Delegation audit ledger

The authoritative ledger contains append-only events for draft creation where retained, request, authorization, rejection, revocation, emergency grant, delegated state-changing use, and expired-use denial where policy requires it. Policy-controlled sensitive-read telemetry is separate from these authoritative mutation events.

Each event records actor, actor display snapshot, grant ID, project, capability/version, normalized scope snapshot, prior/new lifecycle state, reason/reference, correlation ID, command receipt ID, request IP/user-agent where applicable, and occurrence timestamp. It must not contain passwords, tokens, source-file bytes, confidential conflict narratives, or unrestricted document content.

The existing `AdministrativeAuditLog` pattern is appropriate as a behavioral reference because it sanitizes sensitive fields and rejects application-model update/delete. The general `AccreditationActivityLog` may receive a secondary operational event but must not be the authoritative grant ledger.

### 6.7 Optional organizational-unit association

An optional `sponsoring_unit_id` or equivalent provenance reference may point to an approved `qa_organizational_units` record. It indicates the business unit sponsoring or accountable for a grant. It does not confer authority, does not replace a named user grantee, and must not cause a unit-wide implicit grant.

If the institution later requires named LPMI/LPME membership management, model that as a separate governed affiliation/history concern with its own effective period and audit record. Do not infer it from free-text labels or modify the locked DB-18 organizational structures.

### 6.8 Relationship to existing contracts

The proposed model must be forward-only and separate from DB-18 tables. It may reference existing users, projects, cycles, criteria, indicators, and optional organizational units with restrictive relationships, but it must not alter DB-18 keys, state values, checks, event tables, mappings, assignments, evidence, revisions, or final snapshots.

## 7. Separation of Duties

| Conflict | Current enforced control | Proposed additional governance control |
|---|---|---|
| Configuring an assessor and performing assessment | No current rule prevents a permitted project/cycle manager from assigning themselves and then assessing. | **PENDING INSTITUTIONAL DECISION:** prohibit or require independent approval for self-assignment by a delegated governance administrator. Preserve existing assessor/reviewer checks. |
| Lead/supporting assessor and LED reviewer | Same person cannot hold conflicting active assignment roles for one indicator; reviewer cannot review their created/submitted revision. | Keep as non-waivable system rule. Add conflict declaration/recusal only if formally required. |
| Document submitter/uploader and document reviewer | Submitter and any uploader of a document attached to the submission cannot review; Super Admin is excluded from review. | Keep as non-waivable system rule. No governance grant may override it. |
| Mapping manager and mapping validator | Mapping creation/revocation is manager-controlled; no current validation workflow exists. | `led.mapping.validate` must be independent from mapping creator/manager for the same mapping. |
| Delegation requester/grantee and approver | No delegation workflow exists. | `accreditation.delegation.request`, `.approve`, and `.revoke` are distinct. Requester/grantee cannot approve; approval cannot alter requested scope/capability/grantee; revoker cannot use revocation for personal benefit. |
| Delegation audit viewer and delegation decision-maker | No delegation workflow exists. | `accreditation.delegation.audit.view` is read-only and does not imply request, approval, or revocation authority. |
| SuperAdmin IT and accreditation operations | Super Admin currently holds operational authority and technical user/role authority; it is excluded from document review. | Preserve temporary direct operation. Future dedicated governance capabilities must not grant technical user/role administration to LPMI/LPME delegates. |
| LPMI and LPME | No current allocation exists. | **PENDING INSTITUTIONAL DECISION:** define distinct mandates before any capability activation; do not assume identical authority. |

### 7.1 Proposed governance matrix

The LPMI and LPME columns are proposals only and are **PENDING INSTITUTIONAL DECISION**. They are not current application permissions or approved operating procedures.

| Actor | Current confirmed authority | Proposed dedicated-governance allocation | Explicit boundary |
|---|---|---|---|
| SuperAdmin IT | Global technical user/role administration, all-project visibility, current instrument publication, and temporary operational LED configuration. Excluded from document review decisions. | Retain technical administration and emergency platform recovery. Preserve temporary direct LED path until capability migration approval. | Do not make SuperAdmin an implicit delegation bypass. Do not grant it document-review authority through delegation. |
| Project Manager | Active project membership plus current project/assignment permissions permits project-scoped cycle, assignment, and mapping management. | Retain existing direct project operations unchanged. May become a requester or scoped delegator only if approved policy names it. | Cannot manage global users, roles, or permissions; no automatic authority outside active project membership. |
| Future LPMI | No current authority or application persona. | Potentially `accreditation.monitor`, `led.cycle.manage`, and `led.assignment.manage` for named projects only. | **PENDING INSTITUTIONAL DECISION.** No technical administration, implicit document access, assessor work, or reviewer-independence exception. |
| Future LPME | No current authority or application persona. | Potentially `led.evidence.monitor` and independent `led.mapping.validate` for named project/cycle/criterion scope only. | **PENDING INSTITUTIONAL DECISION.** Must not be assumed equivalent to LPMI or permitted to publish instruments by default. |
| PIC Kriteria | Criterion-scoped document upload/submission and document access under existing role/assignment checks. | No delegation change proposed. | Does not gain LED assessment review or governance authority from PIC assignment. |
| Lead Assessor | Active primary LED assignment; can draft, link verified evidence, and submit assigned indicator assessment. | No delegation change proposed. | Cannot review/approve own assessment; cannot act outside active assigned indicator. |
| Supporting Assessor | Active support LED assignment; can draft/link evidence for assigned indicator. | No delegation change proposed. | Cannot submit as primary or review the same indicator. |
| LED Reviewer | Active reviewer LED assignment; can review, request revision, and approve subject to creator/submission independence. | No delegation change proposed. | Cannot be creator, submitter, or assessor for the same reviewed indicator. |
| Document Reviewer | Active criterion reviewer assignment and document review permissions; verifies or requests revision subject to submitter/uploader conflict controls. | No delegation change proposed. | Cannot verify own submission or any submission containing a document they uploaded; delegation cannot waive this rule. |

## 8. Audit And Evidence Requirements

### 8.1 Required provenance

- Every grant request, decision, revocation, and emergency action has a mandatory actor, timestamp, reason, capability version, exact scope, immutable root grant correlation ID, and immutable command correlation ID.
- Approval and revocation require a policy/decision reference sufficient to explain the source of authority without copying confidential records into event JSON.
- Every delegated state-changing LED operation retains the real operational actor and adds the effective grant UUID plus immutable grant/command correlations as supplemental provenance in the relevant existing cycle, mapping, review, or activity event.
- Grant use must never replace current evidence provenance, document verification, mapping, assessment revision, or reviewer-event records.
- Audit write and domain mutation must be atomic. If authoritative delegation-event creation fails, the operation must roll back.
- Request, approve/reject, revoke, and every delegated state-changing use require an authoritative ledger event and command receipt. Successful read telemetry for `accreditation.monitor` and `accreditation.delegation.audit.view` is optional and policy-controlled; it does not participate in mutation rollback. Denied-read telemetry is also policy-controlled and cannot become an authorization prerequisite.

### 8.2 Immutability and records handling

- The delegation grant ledger must reject normal application update/delete operations after event creation.
- State changes are represented by append-only events and constrained aggregate transitions, not mutable audit rows.
- Retention, legal hold, archival, database privilege restrictions, backup restoration, and tamper-evidence requirements are **PENDING INSTITUTIONAL DECISION**.
- Direct database privilege controls are outside current source evidence and require a separately approved database security design.

### 8.3 Evidence boundary

`led.evidence.monitor` can report only data visible under existing project, criterion, document, and verification checks. It does not permit document download, evidence linking, submission verification, or access to restricted source content.

## 9. Threat Model

| Threat | Required control |
|---|---|
| LPMI/LPME label treated as authority | Require a named, effective grant; organizational affiliation is provenance only. |
| Privilege escalation through grant | Capability allowlist, fixed scope shapes, independent approval, no global RBAC/user/role/membership mutation, no transitive delegation. |
| One delegation action implying another | Separate request, approve, revoke, and audit-view capabilities; each service action checks its exact capability and never infers another. |
| Cross-project or cross-criterion identifier tampering | Resolve complete parent lineage, policy/service checks, project-bound grant scope, and post-lock recheck. |
| Scheduled, expired, revoked, or clock-skewed grant use | UTC instant comparisons, inclusive start/exclusive end, derived timing state, immediate revocation semantics, and post-lock reauthorization using one command evaluation instant. |
| Concurrent approval/revocation or duplicate request | Command receipt/idempotency hash, row locks, optimistic state version, terminal lifecycle transitions. |
| Self-delegation or self-approval | Distinct requester/grantee/approver constraints and negative tests. |
| Assessor/reviewer or submitter/reviewer conflict | Retain existing independent-assignment and workflow checks; grants cannot waive them. |
| Direct URL or Livewire action bypass | Route middleware, component mount checks, policies, central service authorization, and transaction-level rechecks. |
| Audit suppression or partial write | Single transaction for domain change, grant-use provenance, authoritative event, and idempotency receipt. Failure rolls back all changes. |
| Confidential information in governance logs | Sanitized structured payloads; store references/hashes rather than document content, credentials, tokens, or confidential narratives. |
| Emergency authority abuse | Disabled by default; separate short-duration capability, mandatory reason, unmistakable event marker, and retrospective independent review. |

## 10. Test Strategy

No tests are added by this draft. Future implementation must add unit, feature, policy, Livewire, and database-concurrency coverage before any capability activation.

| Scenario | Required assertion |
|---|---|
| Delegation capability split | Request capability cannot approve/revoke/audit beyond its own request; approve cannot request/revoke; revoke cannot approve; audit view cannot mutate or imply another capability. |
| Delegation request | Exactly one named grantee, one allowlisted capability/version, and one valid project-bound scope are required; replay with same command/hash is idempotent and changed-hash replay is denied. |
| Delegation approval/rejection | Only an independently designated approver may decide a `requested` grant; stale, duplicate, self, requester, grantee, altered-scope, and altered-capability decisions fail. |
| Delegation revocation | Only an independently designated revoker may revoke an `authorized` grant; replay is idempotent, creates no second event, and cannot alter historic use. |
| Delegation audit view | Audit viewer sees only project-scoped ledger metadata and cannot enumerate other projects, mutate grants, or access protected documents by implication. |
| Scope tuple algebra | Project/cycle/criterion/indicator tuple validation is conjunctive; a project parent cannot broaden a narrower tuple by union, and overlapping authorized grants for one target fail integrity checks. |
| Scheduled grant | Grant is denied immediately before inclusive effective start and can be evaluated as effective at the same UTC instant as start. |
| Expired grant | Actor is denied before and after transaction lock; history remains unchanged except permitted denial telemetry. |
| Request lapse and validity | Draft abandonment is non-authorizing; an undecided request is rejected when its decision deadline/validity interval lapses; approval rejects zero-length, expired, backdated, altered, or null-expiry-without-policy intervals. |
| Revoked grant | Actor is denied immediately; prior valid actions remain historically attributable to their prior grant. |
| Expired revocation | An expired grant cannot transition to `revoked` or append a revocation event; an active/scheduled grant can be revoked exactly once. |
| Cross-project denial | Same capability grant for project A cannot read or mutate project B by route, Livewire property, service input, or crafted ID. |
| Cross-criterion denial | Criterion-scoped monitoring/mapping capability cannot expose or modify another criterion. |
| Self-delegation attempt | Grantee/requester cannot approve or revoke for personal benefit; policy returns denial without partial records. |
| Unauthorized privilege grant | Non-approved actor cannot create grant; a grant cannot contain unknown capability, all-project scope, global role authority, or reserved technical permission. |
| Conflicting assessor/reviewer assignment | Delegated assignment manager cannot bypass one-primary, active-user, same-indicator reviewer independence, or cycle status rules. |
| Double approval/concurrent decisions | Exactly one terminal decision succeeds; replay returns prior result only for identical command ID/hash. |
| Revocation during operation | Transaction post-lock recheck sees revocation and rolls back the target operation and grant-use event. |
| Direct URL/Livewire bypass | Unauthorized direct page, query parameter, public Livewire action, and stale component request are denied. |
| Audit write failure | Grant decision and delegated domain mutation roll back if authoritative event or receipt write fails. |
| Command receipt and correlation provenance | Globally unique command receipt returns the committed result only for the same request hash; request, decision, revocation, and delegated mutation events retain immutable grant and command correlations without payload mutation. |
| Monitoring access/disclosure | Delegation-aware read surface requires non-authorizing project base enrollment plus effective monitor grant; it remains separate from existing operational routing and returns restricted/no-detail evidence state without separately authorized document/review access. |
| Existing SuperAdmin compatibility | Existing direct SuperAdmin workflow tests pass with no grant present. SuperAdmin does not gain document-review authority. |
| Existing Project Manager compatibility | Active Project Manager retains current project-scoped cycle/assignment/mapping behavior with no grant present. |
| Lifecycle compatibility | Inactive accounts, inactive projects, closed/finalized cycles, revoked LED assignments, and document review conflicts remain denied even with a valid grant. |
| Explorer disclosure | Monitor grant does not reveal unauthorized document/criterion details or mappings beyond approved scope. |

## 11. Backward Compatibility

- Existing SuperAdmin LED configuration, instrument publication, cycle, assignment, mapping, and monitoring paths remain unchanged until individual capability migration is approved.
- Existing active Project Manager project-scoped LED operations remain direct authorization paths and must not require a delegation grant.
- Existing PIC, Reviewer, Viewer, document workflow, LED assignment, assessment revision, evidence, and Explorer behavior remains unchanged.
- No existing Spatie role, permission, project membership, criterion assignment, LED assignment, mapping, or audit record is reinterpreted as a delegation grant.
- Initial deployment of future storage must create no grants, provision no default authority, and leave new feature paths disabled. It must be safe to deploy before policy activation.
- If delegation functionality is disabled after rollout, deny only the new delegated paths. Preserve existing SuperAdmin and Project Manager paths and retain all grant/audit history.
- Do not use destructive rollback for governance history. Any future schema recovery must be forward-only and separately approved.

## 12. Phased Implementation Gates

| Gate | Required result before proceeding |
|---|---|
| G0: Institutional mandate | Written approval of LPMI/LPME remit, accountable authority, capability ownership, and separation requirements. |
| G1: Capability decision | Approve capability vocabulary, including separate request/approve/revoke/audit-view actions, scope shapes, delegator/approver/revoker model, validity, overlap, emergency process, and no-transitive-delegation rule. |
| G2: Data and audit design | Approve forward-only logical-to-physical design, retention, privacy, database privilege, recovery, and immutable-record approach without touching DB-18. |
| G3: Security design review | Approve authorization engine, direct-path compatibility, conflict rules, idempotency/concurrency design, delegation-aware monitoring read surface, and threat-model mitigations. |
| G4: Implementation readiness | Implement behind a disabled feature boundary, with no grants provisioned, no changed direct authorization behavior, and no delegated monitoring path until its base-enrollment/disclosure contract is implemented. |
| G5: Automated assurance | Pass all existing regression suites plus new negative authorization, lifecycle, race, audit-failure, and database integrity tests. |
| G6: Institutional UAT | Approved delegates perform controlled project-scoped scenarios; SuperAdmin and Project Manager regression scenarios remain successful. |
| G7: Capability activation | Activate one approved capability for named approved projects only. No blanket LPMI/LPME activation. |
| G8: Operational review | Review grant-use audit evidence, expiry/revocation handling, access scope, incidents, and separation-of-duty outcomes before additional capability activation. |

### 12.1 Rollback strategy

The preferred rollback is operational: disable delegated capability evaluation, revoke active grants if authorized, and preserve immutable history. Do not delete grants, decisions, events, command receipts, or existing Phase 2 records. Existing direct SuperAdmin and Project Manager paths must continue to function throughout rollback.

## 13. Institutional Decisions Required

This is the canonical open-decision register for this draft. Items in Sections 3, 4, 5, 7, and 12 refer here; none is treated as granted authority before written approval.

- **PENDING INSTITUTIONAL DECISION:** Formal LPMI and LPME mandates and whether either unit has project, cycle, criterion, or indicator authority.
- **PENDING INSTITUTIONAL DECISION:** Whether LPMI and LPME receive distinct capability sets, shared capabilities, or no direct operational capability.
- **PENDING INSTITUTIONAL DECISION:** Eligible requesters, delegators, approvers, revokers, audit viewers, and emergency approvers for each capability.
- **PENDING INSTITUTIONAL DECISION:** The non-authorizing base enrollment model for delegated monitoring and whether existing Viewer role/project membership is suitable.
- **PENDING INSTITUTIONAL DECISION:** Whether an administrator may assign themselves as an assessor and what independent approval is required.
- **PENDING INSTITUTIONAL DECISION:** Whether mapping validation is mandatory, who validates it, and whether mapping completeness gates cycle activation.
- **PENDING INSTITUTIONAL DECISION:** Whether linked verified evidence is mandatory for assessment submission, approval, or only selected indicators.
- **PENDING INSTITUTIONAL DECISION:** Effective period defaults, null-expiry policy, maximum duration, overlap rules, timezone/clock authority, expiry behavior, revocation authority, and emergency access procedure.
- **PENDING INSTITUTIONAL DECISION:** Conflict declaration, recusal, organizational conflict, and exception policy beyond existing identity-based separation checks.
- **PENDING INSTITUTIONAL DECISION:** Audit retention, legal hold, privacy classification, archival, database privileges, and tamper-evidence standard.
- **PENDING INSTITUTIONAL DECISION:** Whether organizational-unit association is required and the authoritative source for LPMI/LPME unit records.

## 14. Explicit Non-Goals

- No LPMI/LPME role, account, unit membership, capability, grant, or workflow is implemented by GOV-02.
- No source code, migration, SQL, DB-18 artifact, Phase 3C-1 artifact, permission seed, route, policy, or existing workflow is changed by this draft.
- No global technical administration is delegated. This includes users, roles, permissions, account activation, password resets, and platform configuration.
- No document-review independence rule, LED reviewer independence rule, evidence provenance rule, project lifecycle lock, or cross-project isolation rule is relaxed.
- No organization label is treated as an authorization decision.
- No production, UAT, deployment, environment, or database operation is performed.
- No assertion is made that LPMI and LPME have identical institutional duties.

## 15. GO/NO-GO Recommendation

**GO - TECHNICAL DESIGN LOCK v1.1:** The capability, lifecycle, authorization, data, audit, threat, and compatibility contracts are internally consistent for the next design gate.

**NO-GO** for implementing or activating LPMI/LPME authority now. The absence of an approved responsibility split, grant approver hierarchy, monitoring enrollment policy, mapping/evidence policy, conflict policy, and audit-retention standard means that code or schema changes would be premature.

When the listed institutional decisions are approved and the separate delegation-aware monitoring read surface has passed its implementation gates, begin with a disabled-by-default delegation ledger and `accreditation.monitor` pilot. Preserve current SuperAdmin and Project Manager behavior, activate capabilities one at a time, and require the implementation gates in this document before operational use.

### 15.1 Final Architecture Consistency Review Checklist

- [x] The former broad `accreditation.delegation.manage` capability is not an operative capability; request, approve, revoke, and audit-view actions are distinct.
- [x] Stored states are limited to `draft`, `requested`, `authorized`, `rejected`, and `revoked`; approval is an event and scheduled/active/expired are derived conditions.
- [x] Lifecycle precedence, UTC timing, inclusive start, exclusive end, replay handling, idempotent revocation, and terminal immutability are defined.
- [x] Every grant has one named grantee, one allowlisted/versioned capability, exact project-bound scope, and no default authority.
- [x] Direct SuperAdmin and active Project Manager paths remain backward compatible until an explicit approved migration.
- [x] Reviewer/document-verification independence and evidence provenance remain non-waivable.
- [x] Request, decision, revocation, and delegated-use provenance requires immutable grant and command correlation identifiers; authoritative audit failure requires rollback.
- [x] Direct URL and Livewire bypass scenarios are included in the required test strategy.
- [x] LPMI/LPME authority remains **PENDING INSTITUTIONAL DECISION** and is never inferred from organizational affiliation.
- [x] No executable migration, DDL, application code, permission change, DB-18 change, or Phase 3C-1 change is introduced by this document.
