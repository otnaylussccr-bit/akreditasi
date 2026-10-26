# GOV-03 Institutional Governance Decision Package

**Status:** DRAFT — PENDING INSTITUTIONAL APPROVAL

**Code baseline:** `312350bad765f26e7308f36f1f7eb2bd89c98b26`

**Technical source:** `docs/GOV-02_Dedicated_Accreditation_Governance_Architecture_DRAFT.md`, TECHNICAL DESIGN LOCK v1.1.

**Purpose:** Provide leadership with a clear decision package for Option B, Dedicated Accreditation Governance. This document proposes policy options only. It does not approve an institutional mandate, assign any person or unit authority, create a role, activate a capability, or change AMS APU behavior.

## 1. Executive Brief

### Why this architecture is needed

AMS APU currently has safe operational controls, but routine LED governance is temporarily concentrated in SuperAdmin. A dedicated governance model will allow future, narrowly scoped accreditation operations to be delegated without giving recipients technical administration, global access, or authority outside their named project.

### What is already working

- Active accounts, global RBAC, project/criterion scope, and lifecycle controls are enforced.
- LED cycles, assignments, mappings, assessment revisions, reviewer independence, verified evidence provenance, and Relationship Explorer access are server scoped.
- SuperAdmin is excluded from document-review decisions; document submitter/uploader and reviewer conflicts are controlled.
- DB-18 protects Phase 2 project/cycle/version/indicator/evidence relationships and must remain unchanged.

### Why SuperAdmin should not be the routine business model

SuperAdmin is necessary for technical administration and is currently the temporary LED operational path. Making that technical role the permanent business-governance model would combine user/role administration, platform recovery, and accreditation operations more broadly than necessary. The GOV-02 design instead preserves SuperAdmin while allowing later project-scoped delegation after institutional approval.

### Decisions leadership must make

Leadership must decide whether there is a formal LPMI/LPME mandate, which capabilities may be delegated, who may request/approve/revoke them, how conflicts are handled, whether mapping and evidence have additional governance requirements, how long authority lasts, and how records are retained and assured.

### What happens after approval

Only after written decisions and implementation gates may the institution authorize a disabled-by-default delegation feature. The first candidate is a tightly scoped monitoring pilot for named projects, using a new delegation-aware read surface. Each later capability requires separate approval, testing, and activation.

### What remains unchanged in Production

- SuperAdmin retains current authority.
- Active Project Manager direct project-scoped operations remain unchanged.
- Existing PIC, assessor, reviewer, document verification, evidence provenance, and Explorer controls remain unchanged.
- No LPMI/LPME role, unit membership, capability, grant, or automatic authority is created by this package.

## 2. Institutional Decision Register

All entries below have **Approval status: PENDING**. “Recommended” means a proposal for institutional consideration, not an approved policy.

### GOV-03-01 — Formal LPMI/LPME Mandate

| Field | Decision content |
|---|---|
| Topic | Organizational mandate and project scope for future LPMI/LPME involvement. |
| Business question | Should either future function receive an accreditation-governance mandate, and if so, for which named projects and capabilities? |
| Current system behavior | No LPMI/LPME persona, authorization branch, grant, or organizational authority exists. SuperAdmin and active Project Manager retain current paths. |
| Option A | Retain SuperAdmin/Project Manager operating model; no LPMI/LPME delegated authority. |
| Option B | Define distinct project-scoped mandates for LPMI and LPME, with separate capability sets. |
| Optional alternative | Define a shared limited monitoring mandate only, with no workflow mutation authority. |
| Recommended option | Option B, but only with distinct written mandates and named-project scope. |
| Rationale | Avoids treating LPMI and LPME as interchangeable while enabling least-privilege governance. |
| Operational impact | Requires a mandate register and future named-user grants; no immediate change. |
| Technical implications | Uses GOV-02 capability grants; organizational affiliation remains provenance only. |
| Risk if unresolved | Implicit authority, duplicated responsibility, or continued SuperAdmin dependency. |
| Proposed accountable decision-making function | Institutionally designated accreditation-governance authority. |
| Approval status | PENDING. |

### GOV-03-02 — Capability Ownership And Project Manager Participation

| Field | Decision content |
|---|---|
| Topic | Capability ownership, allowed scope tuples, and whether Project Managers may participate in delegation requests. |
| Business question | Which capabilities may exist, at what project/cycle/criterion/indicator scope, and may a Project Manager request a grant? |
| Current system behavior | Project Managers retain direct project-scoped cycle, assignment, and mapping authority. No delegation capability exists. |
| Option A | Keep all existing direct paths; do not introduce delegated capabilities. |
| Option B | Approve a controlled catalogue and permit Project Managers to request, but not approve, selected named-project grants. |
| Optional alternative | Permit no Project Manager participation in delegation and centralize requests. |
| Recommended option | Option B with a capability-by-capability approval matrix. |
| Rationale | Preserves direct operations while allowing controlled escalation without transitive authority. |
| Operational impact | Requires each capability owner and allowed scope tuple to be recorded. |
| Technical implications | No capability implies another; grants remain additive and project bound. |
| Risk if unresolved | Inconsistent delegation, accidental scope expansion, or blocked implementation. |
| Proposed accountable decision-making function | Institutionally designated accreditation-governance authority. |
| Approval status | PENDING. |

### GOV-03-03 — Request, Approval, Revocation, And Audit-View Authority

| Field | Decision content |
|---|---|
| Topic | Delegation decision authority. |
| Business question | Who may request, approve/reject, revoke, or inspect delegation records for each capability and project? |
| Current system behavior | No delegation workflow exists. User/role administration is SuperAdmin-only and must not be repurposed as delegation approval. |
| Option A | Centralize every decision in one institutionally designated governance function. |
| Option B | Designate decision functions per capability and project, with independent requester, approver, revoker, and audit viewer. |
| Optional alternative | Defer delegation-decision capabilities and retain direct authorization only. |
| Recommended option | Option B with an approved project-and-capability decision matrix. |
| Rationale | Supports local accountability while preventing self-approval, transitive delegation, and broad technical authority. |
| Operational impact | Requires a maintained authority matrix and replacement process for unavailable decision-makers. |
| Technical implications | `request`, `approve`, `revoke`, and `audit.view` remain independent capabilities with immutable events. |
| Risk if unresolved | Self-dealing, unclear revocation path, or unauthorized grant approval. |
| Proposed accountable decision-making function | Institutionally designated accreditation-governance authority. |
| Approval status | PENDING. |

### GOV-03-04 — Self-Request, Self-Assignment, And Governance Conflict

| Field | Decision content |
|---|---|
| Topic | Whether governance administrators may request a grant for themselves or assign themselves to assessment work. |
| Business question | When, if ever, may a governance administrator be both configuration actor and beneficiary? |
| Current system behavior | Current manager paths do not prohibit self-assignment as assessor; system-enforced assessor/reviewer independence remains in place. |
| Option A | Prohibit self-request and self-assignment for delegated governance administrators. |
| Option B | Permit only with prior independent approval and mandatory recorded reason. |
| Optional alternative | Permit through an emergency-only exception process. |
| Recommended option | Option A by default; Option B only for documented operational necessity. |
| Rationale | Reduces actual and perceived self-dealing while retaining a narrowly governed exception path if needed. |
| Operational impact | May require a substitute administrator for some projects. |
| Technical implications | Self-approval remains prohibited in all cases; exception use must not weaken reviewer independence. |
| Risk if unresolved | Conflicted configuration, unreviewable self-benefit, and audit challenge. |
| Proposed accountable decision-making function | Institutionally designated accreditation-governance authority. |
| Approval status | PENDING. |

### GOV-03-05 — Conflict, Recusal, And Exception Policy

| Field | Decision content |
|---|---|
| Topic | Broader organizational conflicts beyond system-enforced identity conflicts. |
| Business question | Must governance actors declare conflicts, recuse themselves, and use a controlled exception process? |
| Current system behavior | Assessor/reviewer and submitter/uploader/reviewer conflicts are enforced. No broader conflict declaration or recusal workflow exists. |
| Option A | Retain existing identity controls only. |
| Option B | Require declaration and recusal for governance decisions, with an independently reviewed exception process. |
| Optional alternative | Require declarations for high-impact capabilities only. |
| Recommended option | Option B, phased from high-impact mutation and approval capabilities. |
| Rationale | Preserves hard technical controls and adds accountable handling of institutional conflicts. |
| Operational impact | Requires policy, training, and a decision record for recusals/exceptions. |
| Technical implications | Future conflict records must not override existing reviewer/document-review denials. |
| Risk if unresolved | Undisclosed conflicts and decisions that cannot be independently defended. |
| Proposed accountable decision-making function | Institutionally designated accreditation-governance authority. |
| Approval status | PENDING. |

### GOV-03-06 — Delegated Monitoring Base Enrollment And Visibility

| Field | Decision content |
|---|---|
| Topic | Non-authorizing base enrollment for read-only delegated monitoring. |
| Business question | What minimal existing project enrollment may support a future monitor grant without creating LED, document, or reviewer authority? |
| Current system behavior | `project.view` is required. Exact Viewers cannot use current operational LED routes without an LED assignment. Explorer applies separate criterion/document/review disclosure checks. |
| Option A | Do not introduce delegated monitoring. |
| Option B | Use a formally approved non-authorizing base enrollment, such as existing Viewer role plus active project viewer membership, with a new delegation-aware read surface. |
| Optional alternative | Create a distinct future monitoring enrollment after separate approval. |
| Recommended option | Option B, provided the future read surface is separate from current operational routing and returns restricted/no-detail evidence state without separate disclosure authority. |
| Rationale | Enables visibility without treating membership as authorization or expanding document access. |
| Operational impact | Defines who may see project-level monitoring and under which project assignment. |
| Technical implications | Requires a future read surface, direct URL/Livewire denial tests, and no default grants. |
| Risk if unresolved | Monitoring cannot safely be piloted; ad hoc Viewer or SuperAdmin workarounds may continue. |
| Proposed accountable decision-making function | Institutionally designated accreditation-governance authority, with technical consultation. |
| Approval status | PENDING. |

### GOV-03-07 — Independent Academic Mapping Validation

| Field | Decision content |
|---|---|
| Topic | Academic validation of indicator-item mappings. |
| Business question | Is validation required, who performs it, and does it block cycle activation or evidence use? |
| Current system behavior | Mapping management exists; mapping validation has no current workflow, state, or table. Mappings are not an activation prerequisite. |
| Option A | Keep mapping management without a separate validation process. |
| Option B | Add optional independent validation, separate from mapping creation/revocation. |
| Optional alternative | Require validation and make completion an activation gate. |
| Recommended option | Option B initially; defer activation gating until criteria and capacity are approved. |
| Rationale | Improves academic assurance without prematurely blocking existing LED cycles. |
| Operational impact | Requires validation criteria and independent validator availability. |
| Technical implications | Requires a separate validation workflow/capability; it must not be implied by mapping management. |
| Risk if unresolved | Inconsistent mapping quality or a premature workflow blocker. |
| Proposed accountable decision-making function | Institutionally designated academic/accreditation governance authority. |
| Approval status | PENDING. |

### GOV-03-08 — Evidence Requirement Before Submission Or Approval

| Field | Decision content |
|---|---|
| Topic | Whether verified linked evidence is mandatory for LED assessment submission or approval. |
| Business question | Should all, selected, or no indicators require linked verified evidence before a workflow transition? |
| Current system behavior | Evidence linking requires verified Phase 1 provenance, active mapping, same project, and hash validation. Submission currently requires rubric, justification, and independent reviewer, not universal evidence. |
| Option A | Retain current behavior with no additional evidence gate. |
| Option B | Require verified linked evidence for selected indicators defined by approved policy. |
| Optional alternative | Require verified linked evidence for every submission or approval, with formal exceptions. |
| Recommended option | Option B with explicit indicator-specific rules and exception criteria. |
| Rationale | Targets evidence requirements where material without blocking indicators that legitimately lack an approved evidence pattern. |
| Operational impact | Requires an approved indicator policy and support process for exceptions. |
| Technical implications | Must preserve verified-submission, mapping, project, and hash provenance; monitoring never infers restricted evidence detail. |
| Risk if unresolved | Uneven evidence practice or an overly broad future gate. |
| Proposed accountable decision-making function | Institutionally designated academic/accreditation governance authority. |
| Approval status | PENDING. |

### GOV-03-09 — Grant Duration, Expiry, Overlap, And Clock Authority

| Field | Decision content |
|---|---|
| Topic | Time validity for delegation grants. |
| Business question | What duration, expiry, overlap, and clock policy applies to each capability? |
| Current system behavior | No grants exist. GOV-02 locks UTC comparison, inclusive start, exclusive end, unique effective matching authority, and fail-closed revocation/expiry behavior. |
| Option A | Require a fixed short expiry for every capability. |
| Option B | Use capability-specific bounded durations, no null expiry initially, and a single approved UTC clock authority. |
| Optional alternative | Permit null expiry for narrowly defined, periodically recertified capabilities. |
| Recommended option | Option B. |
| Rationale | Limits persistent authority while allowing operationally realistic grant periods. |
| Operational impact | Requires renewal planning and periodic authority review. |
| Technical implications | Uses UTC half-open intervals, decision deadlines, idempotent revocation, and overlap exclusion. |
| Risk if unresolved | Long-lived authority, clock disagreement, or duplicate effective grants. |
| Proposed accountable decision-making function | Institutionally designated accreditation-governance authority, with technical consultation. |
| Approval status | PENDING. |

### GOV-03-10 — Emergency Delegation

| Field | Decision content |
|---|---|
| Topic | Emergency authority procedure. |
| Business question | Is emergency delegation needed, and if so, for which capabilities and duration? |
| Current system behavior | Emergency delegation is not implemented and is disabled by design. |
| Option A | Prohibit emergency delegation. |
| Option B | Permit monitoring-only emergency access. |
| Optional alternative | Permit narrowly scoped emergency operational access with mandatory reason and retrospective independent review. |
| Recommended option | Option A initially; consider the alternative only after normal delegation governance is proven. |
| Rationale | Avoids building an exception path before the ordinary path has institutional controls. |
| Operational impact | Emergency situations continue through current authorized SuperAdmin/Project Manager paths. |
| Technical implications | Any later emergency capability remains separate, short-lived, and non-waiving of scope/lifecycle/reviewer controls. |
| Risk if unresolved | No special risk while disabled; premature activation would create bypass risk. |
| Proposed accountable decision-making function | Institutionally designated emergency/accreditation governance authority. |
| Approval status | PENDING. |

### GOV-03-11 — Audit Retention, Privacy, And Privileged Database Assurance

| Field | Decision content |
|---|---|
| Topic | Records retention, legal hold, privacy, database privilege, recovery, and tamper-evidence requirements. |
| Business question | What assurance standard applies to delegation records and who may access them outside AMS? |
| Current system behavior | GOV-02 requires a future append-only delegation ledger. Current administrative/cycle/review models provide application-level append-only behavior; direct database privilege controls are not established by the current contract. |
| Option A | Use application-level append-only records with ordinary retention. |
| Option B | Define retention/legal-hold/privacy policy and restrict privileged database access before implementation. |
| Optional alternative | Add a separately approved tamper-evident archive or external immutable record control. |
| Recommended option | Option B, then select the optional alternative proportionate to record classification. |
| Rationale | Establishes minimum records governance before authority is delegated. |
| Operational impact | Requires retention ownership, access review, and recovery evidence. |
| Technical implications | Ledger/event/command-receipt history remains forward-only; no destructive rollback. |
| Risk if unresolved | Inability to defend decisions, preserve records, or control privileged alteration. |
| Proposed accountable decision-making function | Institutionally designated records, privacy, and accreditation-governance functions. |
| Approval status | PENDING. |

### GOV-03-12 — Organizational-Unit Provenance

| Field | Decision content |
|---|---|
| Topic | Source and use of LPMI/LPME organizational-unit information. |
| Business question | Is an organizational-unit association required, and what is its authoritative source? |
| Current system behavior | `qa_organizational_units` can be future provenance only. Unit records and labels do not authorize a user. |
| Option A | Do not store unit association for delegation. |
| Option B | Store optional sponsoring-unit provenance for grants only. |
| Optional alternative | Add governed affiliation history as a separate future concern. |
| Recommended option | Option B initially. |
| Rationale | Supports accountability context without turning unit membership into authority. |
| Operational impact | Requires a maintained source reference for sponsoring units. |
| Technical implications | Named-user grant remains the only authority; no DB-18 modification. |
| Risk if unresolved | Unit labels may be used informally as access claims. |
| Proposed accountable decision-making function | Institutionally designated organizational/governance authority. |
| Approval status | PENDING. |

### GOV-03-13 — Implementation Authorization And Activation Sequence

| Field | Decision content |
|---|---|
| Topic | Whether to authorize implementation after policy decisions. |
| Business question | When may technical work begin, and what may be activated first? |
| Current system behavior | No delegation storage, routes, policies, roles, permissions, or grants exist. Existing production behavior remains active. |
| Option A | No implementation authorization. |
| Option B | Authorize disabled-by-default implementation only; activate nothing. |
| Optional alternative | After G0-G6, authorize a named-project `accreditation.monitor` pilot. |
| Recommended option | Option B, followed by the optional alternative only after all applicable gates pass. |
| Rationale | Preserves production behavior while producing verifiable technical evidence before authority is activated. |
| Operational impact | No immediate end-user change; later pilot is limited to named projects/capability. |
| Technical implications | Forward-only storage outside DB-18, no default grants, regression/negative/concurrency/audit-failure testing. |
| Risk if unresolved | Safe delay; premature activation without policy would be higher risk. |
| Proposed accountable decision-making function | Institutionally designated implementation-approval authority. |
| Approval status | PENDING. |

## 3. Proposed Responsibility Matrix

**Legend:** R = responsible for execution, A = accountable for institutional approval, C = consulted, I = informed, PENDING = no present allocation or authority. An A shown for an existing operational role describes proposed business accountability only; it is not an institutional appointment.

| Business work area | SuperAdmin IT | Project Manager | Future LPMI | Future LPME | PIC Kriteria | Lead Assessor | Supporting Assessor | Independent LED Reviewer | Phase 1 Document Reviewer |
|---|---|---|---|---|---|---|---|---|---|
| Platform, accounts, global roles, recovery | R; current technical control | I | PENDING | PENDING | I | I | I | I | I |
| Project delivery; LED cycle, assignment, and mapping administration | R temporarily; existing direct path | R; existing project-scoped path | PENDING | PENDING | C | C | C | C | C |
| Instrument publication | R; current direct path | C | PENDING | PENDING | I | I | I | I | I |
| Criterion document preparation, upload, submission | R only where current SuperAdmin path applies | C | PENDING | PENDING | R | C | C | I | I |
| Document review, revision request, verification | I; excluded from decision | I | PENDING | PENDING | C | I | I | I | R |
| Indicator assessment draft and verified-evidence linking | I | I | PENDING | PENDING | C | R | R within assignment | C | I |
| Indicator assessment submission | I | I | PENDING | PENDING | I | R | I | I | I |
| LED assessment review, revision request, approval | I | I | PENDING | PENDING | I | C | I | R | I |
| Delegation mandate, grant decision designation, and capability ownership | C on technical constraints | C | PENDING | PENDING | I | I | I | I | I |
| Governance records, retention, privacy, database assurance | R for platform controls | C | PENDING | PENDING | I | I | I | I | I |

**Institutional approval boundary:** The accountable function for delegation mandate, approver/revoker designation, and future LPMI/LPME responsibility is intentionally not assigned in this matrix. It must be designated in writing by the institution.

## 4. Recommended Policy Options

The package recommends the following combined policy direction, subject to separate approval of each decision:

1. Define LPMI and LPME as distinct, project-scoped governance mandates rather than global technical roles.
2. Retain current SuperAdmin and Project Manager authorization unchanged during any transition.
3. Approve a capability-by-capability decision matrix; do not issue broad delegation management authority.
4. Prohibit self-approval; prohibit self-request/self-assignment by default, with only documented independent exceptions if later approved.
5. Use a separate delegated-monitoring read surface with minimal base enrollment, no document/evidence disclosure by implication, and no default grants.
6. Introduce independent mapping validation as optional before considering it as a cycle gate.
7. Define indicator-specific verified-evidence requirements before imposing a universal submission/approval gate.
8. Use bounded capability-specific grant duration, no null expiry initially, UTC timing, and no overlapping effective authority.
9. Keep emergency delegation disabled initially.
10. Approve records retention, privacy, privileged database controls, and recovery requirements before authority activation.
11. Authorize implementation only as disabled-by-default after G0-G3; consider a named-project monitoring pilot only after G4-G6.

## 5. Approval And Sign-off Framework

### 5.1 Required decision record

Each approved GOV-03 decision should record the decision ID, selected option, scope, effective date, policy reference, accountable institutional function, consultation record, conditions, review date, and sign-off evidence. This is an institutional record, not an AMS delegation grant.

### 5.2 Decision sequencing

1. Decide mandate and LPMI/LPME distinction: GOV-03-01.
2. Decide capability ownership and decision authority: GOV-03-02 and GOV-03-03.
3. Decide conflicts, monitoring visibility, mapping, and evidence policy: GOV-03-04 through GOV-03-08.
4. Decide validity, emergency, records, and organizational provenance: GOV-03-09 through GOV-03-12.
5. Decide whether disabled-by-default implementation may begin: GOV-03-13.

### 5.3 Approval safeguards

- No recommendation is an approval.
- No LPMI/LPME organizational label, project membership, or technical role becomes authority without an approved named-user grant in a future implementation.
- Any institutional approval must retain GOV-02 invariants: fail closed, project bound, no self-approval, no technical privilege escalation, independent review, immutable events, and evidence/document controls.
- Any institutional decision that conflicts with GOV-02 requires a new technical review before implementation.

## 6. Implications For AMS APU

### Current production behavior

No change is authorized by GOV-03. SuperAdmin retains its current temporary operational LED authority. Active Project Managers retain current direct project-scoped authority. Existing document, assessment, evidence, and Explorer controls continue unchanged.

### Future implementation boundary

If approved, future work creates a separate delegation overlay outside locked DB-18. It does not convert LPMI/LPME labels into access, replace existing work assignments, or alter current technical administration. It must begin disabled, issue no default grants, and preserve immutable history if later disabled.

### Leadership assurance

The proposed model separates technical administration from business governance without removing current operating capacity. It supports a measured transition, where each new authority is approved, scoped, tested, and reviewed before use.

## 7. Technical Traceability Annex

| GOV-03 decision | GOV-02 reference | Future capability/workflow | Required gate | Backward-compatibility constraint | Audit and security requirement |
|---|---|---|---|---|---|
| GOV-03-01 | Sections 2.3, 5.5, 7.1, 13 | Capability allocation | G0 | No LPMI/LPME authority before written mandate | Unit affiliation never authorizes |
| GOV-03-02 | Sections 3, 5.2, 7.1, 13 | Capability catalogue and scope tuple | G1 | PM direct path remains unchanged | Allowlist, project scope, no capability inheritance |
| GOV-03-03 | Sections 3.2, 4.4, 5.4-5.5, 13 | Request/approve/revoke/audit view | G1-G3 | No change to SuperAdmin user/role administration | Named grantee, no self-approval, command receipt, append-only events |
| GOV-03-04 | Sections 3, 5.5, 7, 13 | Assignment and delegation conflict controls | G1-G3 | Existing reviewer independence remains | Independent approval, mandatory reason, negative tests |
| GOV-03-05 | Sections 5.2, 7, 13 | Future conflict/recusal process | G1-G3 | Existing identity conflict denials remain | Recusal/event evidence cannot waive technical controls |
| GOV-03-06 | Sections 3, 5.3, 5.5, 13 | `accreditation.monitor` read surface | G1-G6 | Current operational routes and Viewer behavior unchanged | Project base enrollment, restricted disclosure, direct URL/Livewire tests |
| GOV-03-07 | Sections 3, 5.5, 7, 13 | `led.mapping.validate` | G1-G6 | Existing mapping management/activation remains | Independent validator, append-only decision, cross-scope tests |
| GOV-03-08 | Sections 2.1, 5.5, 13 | Evidence/submission policy | G1-G6 | Current submission rule remains until approved | Preserve verified submission, mapping, project, and hash provenance |
| GOV-03-09 | Sections 4.3, 5.5, 13 | Grant lifecycle | G1-G5 | No grant requirement for current direct paths | UTC, start-inclusive/end-exclusive, overlap exclusion, revoke/expiry checks |
| GOV-03-10 | Sections 4.4-4.5, 5.5, 9, 13 | Separate emergency capability, if ever approved | G1-G6 | Current normal authority remains | Short duration, mandatory reason, independent retrospective review |
| GOV-03-11 | Sections 6.5-6.6, 8, 13 | Ledger, command receipt, records assurance | G2-G5 | DB-18 remains locked; no destructive rollback | Append-only events, privacy controls, atomic audit/mutation, recovery evidence |
| GOV-03-12 | Sections 2.3, 6.7, 13 | Optional unit provenance | G1-G3 | No unit is reinterpreted as a grant | Named-user authority only; unit association is provenance |
| GOV-03-13 | Sections 11-12, 15 | Disabled implementation and pilot | G4-G8 | SuperAdmin/PM direct paths continue; no default grants | Regression, authorization, concurrency, audit-failure, and UAT evidence |

## 8. Pending Decision Checklist

- [ ] GOV-03-01: Approve or reject formal LPMI/LPME mandates.
- [ ] GOV-03-02: Approve capability ownership, scope tuples, and Project Manager participation.
- [ ] GOV-03-03: Designate requester, approver, revoker, audit-viewer, and emergency decision functions.
- [ ] GOV-03-04: Decide self-request, self-assignment, and exception policy.
- [ ] GOV-03-05: Decide conflict declaration, recusal, and exception process.
- [ ] GOV-03-06: Decide monitoring base enrollment and read-only visibility policy.
- [ ] GOV-03-07: Decide independent mapping validation and whether it becomes a cycle gate.
- [ ] GOV-03-08: Decide verified-evidence requirements for selected/all/no LED transitions.
- [ ] GOV-03-09: Decide duration, null expiry, clock authority, overlap, and revocation policy.
- [ ] GOV-03-10: Decide whether emergency delegation remains prohibited or becomes a separate process.
- [ ] GOV-03-11: Decide retention, legal hold, privacy, database privilege, recovery, and tamper-evidence standards.
- [ ] GOV-03-12: Decide organizational-unit provenance and authoritative source.
- [ ] GOV-03-13: Decide whether disabled-by-default implementation may begin after prior decisions.

## 9. Next Implementation Gate

**Next gate: G0 — Institutional Mandate.**

Before any implementation authorization, the institution should record decisions for GOV-03-01 through GOV-03-03 at minimum: formal mandate, capability ownership, and delegation decision authority. GOV-03-04 through GOV-03-12 establish the operating safeguards required before activation. GOV-03-13 may be considered only after those decisions are approved and GOV-02 remains technically applicable.

**Current recommendation:** No implementation, grant activation, LPMI/LPME authority, role change, or schema change should proceed from this document alone.
