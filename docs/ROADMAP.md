# Kinhold — Product Roadmap

> **Canonical tracking:** [GitHub Milestones](https://github.com/gregqualls/kinhold/milestones)
> This file provides an overview. Individual issues on GitHub have full specs and discussion.
>
> Last updated: 2026-05-02 (70-E shipped)

---

## 🚀 v1.0.0 Launched (April 12, 2026)

Launch shipped on schedule. Current version: **v1.8.8** (see [CHANGELOG.md](../CHANGELOG.md)). Original launch checklist below for history — all P0 done; only [#143](https://github.com/gregqualls/kinhold/issues/143) (demo banner CTA) remains as a P1 polish item.

**Full plan:** [docs/LAUNCH-PLAN.md](LAUNCH-PLAN.md)

| Issue | Title | Priority | Status |
|-------|-------|----------|--------|
| [#139](https://github.com/gregqualls/kinhold/issues/139) | Self-hosted mode: skip landing page on local installs | P0 | DONE |
| [#140](https://github.com/gregqualls/kinhold/issues/140) | OG/Twitter meta tags for social sharing | P0 | DONE |
| [#141](https://github.com/gregqualls/kinhold/issues/141) | Quick fixes: license text, domain refs, 404 page | P0 | DONE |
| [#117](https://github.com/gregqualls/kinhold/issues/117) | Versioning, GitHub Releases, update notifications | P0 | DONE |
| [#142](https://github.com/gregqualls/kinhold/issues/142) | Docker polish: sessions, .dockerignore, env defaults | P1 | DONE |
| [#124](https://github.com/gregqualls/kinhold/issues/124) | Demo family outdated — refresh seed data | P1 | DONE |
| [#126](https://github.com/gregqualls/kinhold/issues/126) | Demo email verification bug | P1 | DONE |
| [#143](https://github.com/gregqualls/kinhold/issues/143) | Demo banner: "install on your server" CTA | P1 | |

---

## What's Already Built (MVP Complete)

These features are live and working:

| Feature | Status |
|---------|--------|
| Email/password auth + Google OAuth (Sanctum + Socialite) | COMPLETE |
| Family creation, invite codes, parent/child roles | COMPLETE |
| Managed child accounts (no email required) | COMPLETE |
| Task management (CRUD, tags, priority, recurring via RRULE) | COMPLETE |
| Family calendar (read-only Google Calendar + ICS, manual events, recurrence, visibility modes) | COMPLETE |
| Encrypted family vault (WYSIWYG markdown, per-item permissions, document uploads, playbooks) | COMPLETE |
| AI chatbot (Claude-powered, queries calendar/tasks/vault) | COMPLETE |
| MCP server (7 consolidated domain-router tools, module-gated registration, full content coverage via Claude Desktop/Code) | COMPLETE |
| Gamification (points ledger, kudos, leaderboard, rewards store with auctions, badges) | COMPLETE |
| Email notifications (weekly digest, task assigned/completed, invites) | COMPLETE |
| Dark mode (full support across all views) | COMPLETE |
| Mobile-first responsive UI | COMPLETE |
| Profile pictures (upload, 26 presets, 12 color picker, Google photo) | COMPLETE |
| Onboarding wizard (5-step parent setup, simplified join flow) | COMPLETE |
| Google account linking + email verification | COMPLETE |
| Production deploy on Upsun (auto-deploy from GitHub) | COMPLETE |
| Open source on GitHub (Elastic License 2.0) | COMPLETE |
| Self-hosting infrastructure (SQLite, Docker, graceful degradation) | COMPLETE |
| GitHub Actions CI + SDLC pipeline (10 slash commands) | COMPLETE |
| Community docs (CODE_OF_CONDUCT, SECURITY, PR template) | COMPLETE |
| Security audit (cross-family isolation, OAuth hardening, rate limiting) | COMPLETE |
| Unified policy-based auth (8 Laravel Policies for API + MCP) | COMPLETE |
| Calendar event visibility (visible/busy/private modes) | COMPLETE |

---

## Phase A: Make It Solid

**Goal:** Foundational work — GDPR compliance, landing page separation, bug fixes, and quality-of-life improvements before the next feature push.

| Issue | Title | Priority | Status |
|-------|-------|----------|--------|
| [#96](https://github.com/gregqualls/kinhold/issues/96) | GDPR compliance: account deletion and data export | Critical | DONE |
| [#134](https://github.com/gregqualls/kinhold/issues/134) | Separate landing page from SPA | High | DONE |
| [#121](https://github.com/gregqualls/kinhold/issues/121) | Bug: file uploads to the vault | High | DONE |
| [#137](https://github.com/gregqualls/kinhold/issues/137) | AI assistant usage limits for hosted version | Medium | DONE |
| [#138](https://github.com/gregqualls/kinhold/issues/138) | License enforcement: single-family limit for self-hosted | Medium | DONE |
| [#104](https://github.com/gregqualls/kinhold/issues/104) | Brand colors in transactional email templates | Low | DONE |
| [#99](https://github.com/gregqualls/kinhold/issues/99) | Show Kinhold icon in Claude Desktop MCP connector | Low | |

---

## Phase B: Make It Reachable

**Goal:** Get the app onto phones, enable notifications, and start accepting payments.

| Issue | Title | Priority | Status |
|-------|-------|----------|--------|
| [#68](https://github.com/gregqualls/kinhold/issues/68) | PWA support (service worker, manifest, installable) | Very High | DONE |
| [#69](https://github.com/gregqualls/kinhold/issues/69) | Web push notifications | Very High | DONE |
| [#70](https://github.com/gregqualls/kinhold/issues/70) | Stripe billing & subscription system | Critical | DONE (v1.9.0 shipped 2026-05-09, BILLING_ENABLED=true live on production) |

**Pricing model:**
- Self-hosted: Free forever (Elastic License 2.0)
- Hosted (BYOK): $4.99/mo or $49.99/yr — bring your own Anthropic API key
- Hosted (Managed AI): $9.99/mo — we provide the Claude API access
- The only upsell is the API key. No feature gating.

---

## Phase C: Make It Complete

**Goal:** Close the biggest functional gaps that prevent Kinhold from being a true family command center.

| Issue | Title | Priority |
|-------|-------|----------|
| [#71](https://github.com/gregqualls/kinhold/issues/71) | Two-way Google Calendar sync (create/edit events) | High |
| [#122](https://github.com/gregqualls/kinhold/issues/122) | Calendar features (additional calendar capabilities) | Medium |
| [#125](https://github.com/gregqualls/kinhold/issues/125) | Add to a kudo (enhance kudos system) | Medium |
| [#72](https://github.com/gregqualls/kinhold/issues/72) | Family announcements / bulletin board | Medium |
| [#26](https://github.com/gregqualls/kinhold/issues/26) | Rearrange the dashboard | Medium |

---

## Phase D: Make It Grow

**Goal:** Differentiating features that attract new users and generate press/word-of-mouth.

| Issue | Title | Priority |
|-------|-------|----------|
| [#73](https://github.com/gregqualls/kinhold/issues/73) | Allowance & money tracking for kids | Medium |
| [#74](https://github.com/gregqualls/kinhold/issues/74) | AI photo-to-calendar: snap a flyer, extract events | High |
| [#25](https://github.com/gregqualls/kinhold/issues/25) | Voting on stuff | Low |

---

## Phase E: Make It Visible

**Goal:** Public launch and community building.

- Launch on r/selfhosted, Hacker News, Product Hunt
- README polish with screenshots and demo video
- One-click deploy options (Docker, Upsun, Railway)
- Blog content: "Why we built an open-source family hub"
- Family dashboard mode (optimized for a cheap tablet on the wall)
- Branding finalized and applied everywhere

---

## Phase F: Food & Meal Planning

**Goal:** Shopping lists, meal planning, and grocery AI integrations. Multi-week effort — not blocking MVP or foundational work.

**Spec:** [docs/FOOD-FEATURES-SPEC.md](FOOD-FEATURES-SPEC.md) · **Plan:** [docs/FOOD-IMPLEMENTATION-PLAN.md](FOOD-IMPLEMENTATION-PLAN.md)

| Issue | Title | Priority | Status |
|-------|-------|----------|--------|
| [#148](https://github.com/gregqualls/kinhold/issues/148) | Food Step 1: Recipe backend + module gating | High | DONE |
| [#149](https://github.com/gregqualls/kinhold/issues/149) | Food Step 2: Recipe import service (URL + photo) | High | DONE |
| [#150](https://github.com/gregqualls/kinhold/issues/150) | Food Step 3: Recipe frontend UI | High | DONE |
| [#151](https://github.com/gregqualls/kinhold/issues/151) | Food Step 4: Shopping backend + product catalog | High | DONE |
| [#152](https://github.com/gregqualls/kinhold/issues/152) | Food Step 5: Shopping frontend + UX rework | High | DONE |
| [#153](https://github.com/gregqualls/kinhold/issues/153) | Food Step 6: Meal plan backend | High | DONE (PR #165) |
| [#154](https://github.com/gregqualls/kinhold/issues/154) | Food Step 7: Meal plan frontend | High | DONE (PR #166 + PR #169) |
| — | Meal planner UX overhaul: drag-fix, transposed grid, restaurant import, DRY components | High | DONE (PR #169) |
| [#155](https://github.com/gregqualls/kinhold/issues/155) | Food Step 8: Integration & polish (MCP, chatbot, dashboard, seeder) | Medium | DONE (MCP delivered via `kinhold-food`) |
| [#65](https://github.com/gregqualls/kinhold/issues/65) | Shopping & grocery lists | Very High | DONE |
| [#66](https://github.com/gregqualls/kinhold/issues/66) | Meal planning | High | DONE |
| [#67](https://github.com/gregqualls/kinhold/issues/67) | AI chatbot: meal and grocery integrations | Medium | DONE (`kinhold-food` action enum) |
| [#311](https://github.com/gregqualls/kinhold/issues/311) | Sharable recipes (public Blade page, share token, opt-in family attribution) | High | DONE (v1.10.0) |
| [#312](https://github.com/gregqualls/kinhold/issues/312) | Allergen tagging & filtering (Big 9 + custom, AI-assisted, false-safe filtering, meal-planner guard) | High | DONE (v1.10.0) |
| [#323](https://github.com/gregqualls/kinhold/issues/323) | Multi-image recipes (carved out of #311) | Medium | DONE (v1.10.0) |
| — | Settings: Food module toggle — note that URL import may use AI (token cost) | Low | Future |

---

## Future Ideas (Parking Lot)

> Ideas captured from competitive analysis and brainstorming. Not planned yet.

- Google OAuth + invite codes — `GoogleAuthController::findOrCreateUser` creates a new family for every first-time OAuth user, so on a self-hosted instance a spouse signing in via Google ends up in their own family rather than joining the existing one. Should accept an invite-code query param. Follow-up from #138.
- Notification PII audit (follow-up from #305): `WeeklyDigestNotification::__construct(public array $digest)` and possibly others take raw arrays instead of model keys. With `failed_jobs` restored, that data lands in `failed_jobs.payload` on dispatch (bounded to 72h by the new prune schedule, but the structural fix is to refactor to model-only constructor args so SerializesModels handles the payload).
- Plugin/module system for community extensions (under investigation)
- Staging environment strategy (discussion pending)
- Vault overhaul (explore Obsidian-like or Standard Notes integration)
- Vault audit log and version history
- More calendar providers (Outlook, iCloud, CalDAV)
- Family chat/messaging (beyond announcements)
- Location sharing
- Chore rotation system
- Budget & expense tracking
- School schedule integration
- Mobile app (React Native or Capacitor wrapper)
- Internationalization (i18n)
- Accessibility audit (WCAG 2.1 AA)
- Two-factor authentication / passkeys
- Family photo gallery
- Emergency info page
- Shared-with-external vault (share with doctor, lawyer, school)
- Proactive AI reminders
- Webhook integrations (IFTTT, Zapier, Home Assistant)

---

## Strategic Positioning

> **"The open-source family hub that respects your privacy and actually gets smarter over time."**

Three pillars no competitor combines:
1. **Privacy-first** — self-hosted, encrypted vault, no ads, open source
2. **AI-powered** — Claude chatbot queries your family data, MCP server for automation
3. **Gamification that works** — points, badges, rewards that motivate the whole family

Target audience (in go-to-market order):
1. Self-hosters and privacy-conscious tech parents (r/selfhosted, HN, GitHub)
2. AI-forward families who already use Claude/ChatGPT
3. Parents frustrated with Cozi's decline looking for a modern alternative
