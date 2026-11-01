=== DEV3 AI Job Listings Assistant for WP Job Manager ===
Contributors: dev3tech
Tags: wp job manager, job listings, job description, jobposting schema, recruitment
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Requires Plugins: wp-job-manager
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An add-on for the free WP Job Manager plugin: AI job descriptions, inclusive-language checks, salary and title normalisation, job type and tag suggestions, better Google for Jobs schema, candidate matching and bulk improvements with review.

== Description ==

DEV3 AI Job Listings Assistant makes every WP Job Manager listing clearer, fairer and easier to find. It works inside WP Job Manager: the front-end submit form, the job edit screen and the Job Listings table, plus its own DEV3 AI screen.

**Listing quality**

* A 0-100 quality score for every listing (description length and structure, salary, job type, location, company, title, tags, inclusive wording), updated on every save.
* A sortable Quality column and an "Improve with DEV3 AI" row action in Job Listings.
* A dashboard with the weakest listings and what to fix first.

**Write and check**

* Job description writer with tone presets, from notes or the current description. Drafts are shown side by side with the current text and saved only when you accept.
* Inclusive-language check: a built-in word list (age-coded, gender-coded, ableist, origin and jargon terms) plus context-aware DEV3 AI findings, with one-click replacements.
* Both are available to employers on the front-end [submit_job_form] (neutral wording, no plugin branding) and to editors in the job edit screen.

**Structured data**

* Salary normalisation: "$50k-70k/yr", "£30,000 - £35,000 per annum", "20-25 USD/hour", "INR 12 LPA" become numbers, ISO currency and pay period in WP Job Manager's own salary fields.
* Title clean-up: removes hype words, emojis, shouting and pay from titles, expands abbreviations (Sr → Senior) and detects seniority.
* Job type, category and tag suggestions constrained to the terms you already use. Adds a Job tags taxonomy when the Job Tags add-on is not installed.
* Better JobPosting schema through WP Job Manager's own filter: numeric baseSalary ranges, remote jobs without a location (TELECOMMUTE + applicant country), employment types, experience, skills and industry. A schema checklist per listing.

**Candidate matching (with the WP Job Manager Resume Manager add-on)**

* Ranks resumes against a job with four transparent criteria (skills, experience, role fit, location), strengths and gaps, and an optional DEV3 AI explanation.
* Decision support only: nothing changes a resume or application, and every candidate stays visible.

**Bulk improve**

* Improve many listings in one background run (low quality, missing salary, missing type/tags, exclusionary wording, all, or selected).
* Every change lands in a review queue: accept, edit or reject; "Accept safe changes" applies salary, title and taxonomy updates in one click.

**DEV3 AI (optional, uses your own Google Gemini API key)**

Everything except AI writing and AI explanations works without a key: rules, templates and the word list are built in. A monthly budget cap, an AI kill switch and a separate switch for visitor-facing AI are in API Config.

**Fairness**

Matching never uses names, contact details, photos, age or date of birth, gender, marital or family status, nationality, ethnicity, religion, disability, health, sexual orientation, union membership, political views or street addresses. They are removed before scoring and never sent to AI.

**Privacy**

AI features send listing text (and anonymised resume data for matching) to Google Gemini only when you add an API key. The plugin adds text to your privacy policy and supports the WordPress personal data exporter and eraser for match scores.

== Installation ==

1. Install and activate WP Job Manager (free, from WordPress.org).
2. Upload this plugin's zip in Plugins > Add New > Upload, then activate it.
3. Recommended: in Job Listings > Settings turn on "Enable Job Salary", salary currency and salary unit.
4. Open DEV3 AI Jobs to see your listing scores.
5. Optional: add a Google Gemini API key in DEV3 AI Jobs > API Config.

== Frequently Asked Questions ==

= Does it need WP Job Manager add-ons? =

No. Resume Manager (paid) is only needed for candidate matching. When the Job Tags add-on is active its taxonomy is used; otherwise this plugin provides one.

= Does it change my listings automatically? =

No. AI and bulk results are drafts. Listings change only when you accept a draft. The optional "Normalise salary on submit" setting rewrites clear salaries with rules only.

= Will it print a second JobPosting block? =

No. It improves the JSON-LD that WP Job Manager already prints.

= What does AI cost? =

You pay Google for your own API usage. A description is roughly 1,500 tokens on gemini-2.5-flash. Set a monthly cap in API Config.

== Hooks ==

Filters: dev3jm_inclusive_rules, dev3jm_type_rules, dev3jm_skill_vocabulary, dev3jm_title_abbreviations, dev3jm_title_hype_words, dev3jm_quality, dev3jm_schema, dev3jm_protected_attributes, dev3jm_match_payload, dev3jm_resumes_active, dev3jm_prompt_<id>, dev3jm_public_rate_limit.
Actions: dev3jm_listing_applied.

== Screenshots ==

1. Dashboard with listing quality and WP Job Manager status.
2. Listing editor with the quality checklist and DEV3 AI tools.
3. Review queue for bulk improvements.
4. Candidate matching with criteria and explanations.
5. Writing assistant on the front-end submit form (mobile).

== Changelog ==

= 1.0.0 =
* First release.
