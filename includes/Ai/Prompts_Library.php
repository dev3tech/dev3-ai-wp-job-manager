<?php
/**
 * Versioned prompt templates and output schemas for every DEV3 AI feature of the plugin.
 *
 * Override with the `dev3jm_prompt_<id>` filter (e.g. `dev3jm_prompt_dev3jm_describe`).
 *
 * @package Dev3\AiWpJobManager
 */

namespace Dev3\AiWpJobManager\Ai;

use Dev3\AiWpJobManager\Core3\Api\Prompts;

defined( 'ABSPATH' ) || exit;

/**
 * Registers prompts on init.
 */
class Prompts_Library {

	const DESCRIBE  = 'dev3jm_describe';
	const INCLUSIVE = 'dev3jm_inclusive';
	const NORMALISE = 'dev3jm_normalise';
	const SUGGEST   = 'dev3jm_suggest';
	const MATCH     = 'dev3jm_match';

	/**
	 * Fairness instruction for matching.
	 */
	const FAIRNESS = 'Fairness rules: never use, mention, guess or infer protected attributes: name, age or date of birth, gender or pronouns, photo or appearance, marital or family status, pregnancy, nationality, ethnicity or race, religion, disability or health, sexual orientation, union membership or political views. Judge only job-relevant skills, experience, education and location fit. If the source contains such attributes, ignore them.';

	/**
	 * String schema helper.
	 *
	 * @param int $max Max length.
	 * @return array
	 */
	public static function str( $max ) {
		return array(
			'type'      => 'STRING',
			'maxLength' => $max,
		);
	}

	/**
	 * String list helper.
	 *
	 * @param int $items Max items.
	 * @param int $max   Max length per item.
	 * @return array
	 */
	public static function list_of( $items, $max ) {
		return array(
			'type'     => 'ARRAY',
			'maxItems' => $items,
			'items'    => self::str( $max ),
		);
	}

	/**
	 * Integer 0-100 schema.
	 *
	 * @return array
	 */
	public static function pct() {
		return array(
			'type'    => 'INTEGER',
			'minimum' => 0,
			'maximum' => 100,
		);
	}

	/**
	 * Job description schema.
	 *
	 * @return array
	 */
	public static function describe_schema() {
		return array(
			'type'       => 'OBJECT',
			'properties' => array(
				'title'            => self::str( 120 ),
				'summary'          => self::str( 600 ),
				'responsibilities' => self::list_of( 8, 220 ),
				'requirements'     => self::list_of( 8, 220 ),
				'nice_to_have'     => self::list_of( 5, 220 ),
				'benefits'         => self::list_of( 6, 220 ),
				'skills'           => self::list_of( 10, 40 ),
				'seniority'        => array(
					'type' => 'STRING',
					'enum' => array( '', 'entry', 'mid', 'senior', 'lead', 'executive' ),
				),
				'excerpt'          => self::str( 200 ),
			),
			'required'   => array( 'title', 'summary', 'responsibilities', 'requirements', 'skills', 'excerpt' ),
		);
	}

	/**
	 * Salary + title normalisation schema.
	 *
	 * @return array
	 */
	public static function normalise_schema() {
		return array(
			'type'       => 'OBJECT',
			'properties' => array(
				'title'      => self::str( 120 ),
				'seniority'  => array(
					'type' => 'STRING',
					'enum' => array( '', 'entry', 'mid', 'senior', 'lead', 'executive' ),
				),
				'salary_min' => array( 'type' => 'NUMBER' ),
				'salary_max' => array( 'type' => 'NUMBER' ),
				'currency'   => self::str( 3 ),
				'unit'       => array(
					'type' => 'STRING',
					'enum' => array( '', 'HOUR', 'DAY', 'WEEK', 'MONTH', 'YEAR' ),
				),
				'note'       => self::str( 200 ),
			),
			'required'   => array( 'title', 'seniority', 'salary_min', 'salary_max', 'currency', 'unit', 'note' ),
		);
	}

	/**
	 * Type/tag suggestion schema.
	 *
	 * @param string[] $types      Allowed type slugs.
	 * @param string[] $categories Allowed category slugs.
	 * @return array
	 */
	public static function suggest_schema( array $types, array $categories ) {
		$enum = static function ( array $values ) {
			$values = array_values( array_unique( array_merge( array( '' ), $values ) ) );
			return array(
				'type'     => 'ARRAY',
				'maxItems' => 3,
				'items'    => array(
					'type' => 'STRING',
					'enum' => $values,
				),
			);
		};
		return array(
			'type'       => 'OBJECT',
			'properties' => array(
				'types'      => $enum( $types ),
				'categories' => $enum( $categories ),
				'tags'       => self::list_of( 8, 40 ),
				'reason'     => self::str( 240 ),
			),
			'required'   => array( 'types', 'categories', 'tags', 'reason' ),
		);
	}

	/**
	 * Match explanation schema.
	 *
	 * @return array
	 */
	public static function match_schema() {
		return array(
			'type'       => 'OBJECT',
			'properties' => array(
				'score'     => self::pct(),
				'criteria'  => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'skills'     => self::pct(),
						'experience' => self::pct(),
						'role'       => self::pct(),
						'location'   => self::pct(),
					),
					'required'   => array( 'skills', 'experience', 'role', 'location' ),
				),
				'strengths' => self::list_of( 4, 200 ),
				'gaps'      => self::list_of( 4, 200 ),
				'summary'   => self::str( 400 ),
			),
			'required'   => array( 'score', 'criteria', 'strengths', 'gaps', 'summary' ),
		);
	}

	/**
	 * Registers all templates.
	 */
	public static function register() {
		Prompts::register(
			self::DESCRIBE,
			array(
				'version'     => '1',
				'task'        => 'job_describe',
				'max_tokens'  => 4096,
				'temperature' => 0.6,
				'cache'       => DAY_IN_SECONDS,
				'system'      => 'You are an expert recruiter who writes clear, honest and inclusive job ads for a WP Job Manager job board. Use plain words, short bullet points and gender-neutral language ("you", "they"). Do not invent salary numbers, perks or facts that the employer did not give; when benefits are unknown, return an empty list. Avoid age-coded or exclusionary words (young, digital native, rockstar, ninja, energetic, native speaker, culture fit). Write in {{language}} with a {{tone}} tone.',
				'template'    => "Write a job ad.\n\nJob title: {{title}}\nCompany: {{company}}\nCompany tagline: {{tagline}}\nJob type: {{types}}\nLocation: {{location}}\nRemote: {{remote}}\nSalary given: {{salary}}\n\nEMPLOYER NOTES OR CURRENT DESCRIPTION (main source of facts)\n{{notes}}\n\nReturn: an improved title (searchable, no emojis, no salary), a 2-3 sentence summary, 4-8 responsibilities, 4-8 must-have requirements (only what is truly needed), up to 5 nice-to-haves, benefits (only from the notes), up to 10 skills (short names), the seniority level, and a one-sentence excerpt.",
			)
		);

		Prompts::register(
			self::INCLUSIVE,
			array(
				'version'     => '1',
				'task'        => 'job_inclusive',
				'max_tokens'  => 2048,
				'temperature' => 0.2,
				'cache'       => DAY_IN_SECONDS,
				'system'      => 'You review job ads for exclusionary or biased language (gender-coded, age-coded, ableist, nationality or culture-coded wording, unnecessary requirements such as degrees or years when skills are what matter). Suggest short, neutral alternatives. Do not flag neutral wording. Answer in {{language}}.',
				'template'    => "Review this job ad. Return each problem phrase exactly as it appears in the text, why it may exclude candidates, and a replacement phrase that fits the same sentence.\n\nRules already found by a word list (confirm or skip them): {{rule_hits}}\n\nJOB AD\n{{text}}",
				'schema'      => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'issues' => array(
							'type'     => 'ARRAY',
							'maxItems' => 12,
							'items'    => array(
								'type'       => 'OBJECT',
								'properties' => array(
									'phrase'     => self::str( 80 ),
									'reason'     => self::str( 200 ),
									'suggestion' => self::str( 80 ),
								),
								'required'   => array( 'phrase', 'reason', 'suggestion' ),
							),
						),
					),
					'required'   => array( 'issues' ),
				),
			)
		);

		Prompts::register(
			self::NORMALISE,
			array(
				'version'     => '1',
				'task'        => 'job_normalise',
				'max_tokens'  => 1024,
				'temperature' => 0.1,
				'cache'       => WEEK_IN_SECONDS,
				'system'      => 'You clean job listing data for structured data (schema.org JobPosting). Titles: standard, searchable job titles in title case, no emojis, hype words (rockstar, ninja, guru), salary, location or company names; expand abbreviations (Sr → Senior). Salary: numbers only, as min and max for one pay period, ISO 4217 currency, unit HOUR, DAY, WEEK, MONTH or YEAR. Use 0 for both numbers when the text gives no amount (e.g. "competitive"). Never invent amounts. Site default currency: {{currency}}.',
				'template'    => "Title: {{title}}\nSalary text: {{salary}}\nCurrency field: {{salary_currency}}\nUnit field: {{salary_unit}}\nRule-based reading (may be incomplete): {{rules}}\nDescription excerpt: {{excerpt}}",
				'schema'      => self::normalise_schema(),
			)
		);

		Prompts::register(
			self::SUGGEST,
			array(
				'version'     => '1',
				'task'        => 'job_suggest',
				'max_tokens'  => 1024,
				'temperature' => 0.2,
				'cache'       => WEEK_IN_SECONDS,
				'system'      => 'You classify job listings for a WP Job Manager board. Choose job types and categories ONLY from the allowed slugs (empty list when none fit). Tags are short skill or tool names (1-3 words, title case like "React", "Customer Support"); prefer existing tags when they fit. Answer in {{language}}.',
				'template'    => "Allowed job types (slug: name): {{types}}\nAllowed categories (slug: name): {{categories}}\nExisting tags: {{tags}}\n\nLISTING\n{{listing}}",
			)
		);

		Prompts::register(
			self::MATCH,
			array(
				'version'     => '1',
				'task'        => 'resume_match',
				'max_tokens'  => 2048,
				'temperature' => 0.1,
				'cache'       => WEEK_IN_SECONDS,
				'system'      => 'You help a hiring team see how well a candidate resume fits a job. You give decision support, not decisions: be balanced, concrete and brief, and cite job requirements in every strength and gap. ' . self::FAIRNESS . ' Score 0-100 where 50 means a partial fit and 80+ means most requirements are clearly met. Criteria: skills (required skills present), experience (relevant years and level), role (similar responsibilities done before), location (location fit; 100 when the job is remote). Write in {{language}}.',
				'template'    => "JOB\n{{job}}\n\nCANDIDATE (anonymised)\n{{candidate}}\n\nRULE-BASED PRE-CHECK (for reference)\n{{precheck}}\n\nReturn the score, the four criteria scores, up to 4 strengths, up to 4 gaps and a 1-2 sentence summary.",
				'schema'      => self::match_schema(),
			)
		);
	}

	/**
	 * Output language name for the site locale.
	 *
	 * @return string
	 */
	public static function language() {
		$names = array(
			'en' => 'English',
			'es' => 'Spanish',
			'fr' => 'French',
			'de' => 'German',
			'it' => 'Italian',
			'pt' => 'Portuguese',
			'nl' => 'Dutch',
			'ar' => 'Arabic',
			'hi' => 'Hindi',
			'ja' => 'Japanese',
			'ko' => 'Korean',
			'zh' => 'Chinese',
			'ru' => 'Russian',
			'tr' => 'Turkish',
			'pl' => 'Polish',
			'sv' => 'Swedish',
		);
		$code  = strtolower( substr( (string) determine_locale(), 0, 2 ) );
		return isset( $names[ $code ] ) ? $names[ $code ] : 'the same language as the input';
	}

	/**
	 * Common AI result meta for the UI.
	 *
	 * @param array $res Ai::run result.
	 * @return array
	 */
	public static function meta( array $res ) {
		return array(
			'model'  => isset( $res['model'] ) ? $res['model'] : '',
			'tokens' => isset( $res['usage']['total'] ) ? (int) $res['usage']['total'] : 0,
			'cached' => ! empty( $res['cached'] ),
		);
	}
}
