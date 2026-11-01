<?php
/**
 * Rule-based job type, tag and category suggestions from a listing's title and description.
 *
 * @package Dev3\AiWpJobManager
 */

namespace Dev3\AiWpJobManager\Text;

use Dev3\AiWpJobManager\Wpjm\Wpjm;

defined( 'ABSPATH' ) || exit;

/**
 * Static service.
 */
class Suggest {

	const COMMON_SKILLS = array( 'JavaScript', 'TypeScript', 'React', 'Vue', 'Angular', 'Node.js', 'PHP', 'WordPress', 'WooCommerce', 'Laravel', 'Python', 'Django', 'Java', 'Kotlin', 'Swift', 'Go', 'Rust', 'C#', 'C++', '.NET', 'Ruby', 'SQL', 'MySQL', 'PostgreSQL', 'MongoDB', 'GraphQL', 'REST', 'AWS', 'Azure', 'Google Cloud', 'Docker', 'Kubernetes', 'Terraform', 'CI/CD', 'Git', 'Linux', 'HTML', 'CSS', 'Sass', 'Tailwind', 'Figma', 'Sketch', 'Photoshop', 'Illustrator', 'UX research', 'User research', 'Prototyping', 'Wireframing', 'Accessibility', 'SEO', 'Content marketing', 'Copywriting', 'Google Analytics', 'Google Ads', 'Social media', 'Email marketing', 'HubSpot', 'Salesforce', 'Excel', 'Power BI', 'Tableau', 'Data analysis', 'Machine learning', 'Statistics', 'Project management', 'Agile', 'Scrum', 'Jira', 'Product management', 'Stakeholder management', 'Customer service', 'Customer support', 'Sales', 'Negotiation', 'Account management', 'Bookkeeping', 'Accounting', 'Payroll', 'Recruiting', 'Leadership', 'Mentoring', 'Public speaking', 'Testing', 'QA', 'Cypress', 'Jest', 'Playwright', 'Security', 'Networking', 'Nursing', 'Teaching', 'Logistics', 'Supply chain', 'Warehouse', 'Forklift', 'Driving licence', 'Retail', 'Hospitality', 'Cooking', 'Barista', 'Cleaning', 'Construction', 'Electrical', 'Plumbing', 'Welding', 'CAD', 'AutoCAD', 'Video editing', 'Photography', 'Translation', 'Spanish', 'French', 'German' );

	/**
	 * Keywords per job type slug (WPJM default terms + common custom ones).
	 *
	 * @return array slug => regex
	 */
	public static function type_rules() {
		/**
		 * Filters the job type keyword rules (term slug => regex).
		 *
		 * @param array $rules Rules.
		 */
		return (array) apply_filters(
			'dev3jm_type_rules',
			array(
				'internship' => '/\b(intern(ship)?s?|trainee|apprentice(ship)?|placement year|graduate scheme)\b/i',
				'part-time'  => '/\b(part[\s-]?time|\d{1,2}\s*(hours|hrs)\s*(per|a|\/)\s*week|weekends? only|evenings? only)\b/i',
				'temporary'  => '/\b(temporary|temp|fixed[\s-]term|seasonal|maternity cover|\d+\s*(month|week)s?\s*contract)\b/i',
				'freelance'  => '/\b(freelance|freelancer|contractor|contract role|self[\s-]employed|1099|per project|gig)\b/i',
				'full-time'  => '/\b(full[\s-]?time|permanent|40\s*(hours|hrs)|37\.5\s*(hours|hrs)|salaried)\b/i',
			)
		);
	}

	/**
	 * Skill vocabulary: existing tags first, then common skills.
	 *
	 * @return string[]
	 */
	public static function vocabulary() {
		$names = array();
		foreach ( Wpjm::all_terms( Wpjm::TAX_TAG ) as $t ) {
			$names[] = $t['name'];
		}
		$all = array();
		foreach ( array_merge( $names, self::COMMON_SKILLS ) as $n ) {
			$key = self::norm( $n );
			if ( '' !== $key && ! isset( $all[ $key ] ) ) {
				$all[ $key ] = $n;
			}
		}
		/**
		 * Filters the skill/tag vocabulary.
		 *
		 * @param string[] $skills Names.
		 */
		return array_values( (array) apply_filters( 'dev3jm_skill_vocabulary', array_values( $all ) ) );
	}

	/**
	 * Normalised key ("Node.js" → "node").
	 *
	 * @param string $s Name.
	 * @return string
	 */
	public static function norm( $s ) {
		$s = mb_strtolower( trim( (string) $s ) );
		$s = preg_replace( '/\.?js$/', '', $s );
		return (string) preg_replace( '/[^a-z0-9#+]+/u', '', (string) $s );
	}

	/**
	 * Skills named in a text.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max.
	 * @return string[]
	 */
	public static function skills_in( $text, $max = 8 ) {
		$hits = array();
		foreach ( self::vocabulary() as $skill ) {
			$pos = self::find( $text, $skill );
			if ( false !== $pos ) {
				$hits[ $skill ] = $pos;
			}
		}
		// Order by first appearance in the text.
		asort( $hits );
		return array_slice( array_keys( $hits ), 0, $max );
	}

	/**
	 * Whole-word position of a skill in a text (case-insensitive) or false.
	 *
	 * @param string $text  Text.
	 * @param string $skill Skill.
	 * @return int|false
	 */
	private static function find( $text, $skill ) {
		$case = mb_strlen( $skill ) <= 3 ? '' : 'i'; // Short names such as Go or QA match exact case only.
		if ( preg_match( '/(?<![A-Za-z0-9])' . preg_quote( $skill, '/' ) . '(?![A-Za-z0-9])/u' . $case, $text, $m, PREG_OFFSET_CAPTURE ) ) {
			return (int) $m[0][1];
		}
		return false;
	}

	/**
	 * Suggestions for a listing.
	 *
	 * @param array $job Listing (Wpjm::get shape) or { title, content }.
	 * @return array { types[{id,name,slug,reason}], tags[], categories[{id,name,slug}], source }
	 */
	public static function rules( array $job ) {
		$text  = ( isset( $job['title'] ) ? $job['title'] : '' ) . "\n" . Wpjm::plain( isset( $job['content'] ) ? $job['content'] : '' );
		$terms = Wpjm::all_terms( Wpjm::TAX_TYPE );
		$types = array();
		foreach ( self::type_rules() as $slug => $regex ) {
			if ( ! preg_match( $regex, $text, $m ) ) {
				continue;
			}
			foreach ( $terms as $t ) {
				if ( $t['slug'] === $slug || sanitize_title( $t['name'] ) === $slug ) {
					/* translators: %s: matched phrase */
					$types[] = array_merge( $t, array( 'reason' => sprintf( __( 'Mentions "%s"', 'dev3-ai-wp-job-manager' ), trim( $m[0] ) ) ) );
					break;
				}
			}
		}
		if ( ! $types ) {
			foreach ( $terms as $t ) {
				if ( 'full-time' === $t['slug'] ) {
					$types[] = array_merge( $t, array( 'reason' => __( 'No hours mentioned; full time is the most common type', 'dev3-ai-wp-job-manager' ) ) );
				}
			}
		}
		$cats  = array();
		$lower = mb_strtolower( $text );
		foreach ( Wpjm::all_terms( Wpjm::TAX_CAT ) as $c ) {
			$name = mb_strtolower( $c['name'] );
			if ( mb_strlen( $name ) > 2 && preg_match( '/\b' . preg_quote( $name, '/' ) . '/u', $lower ) ) {
				$cats[] = $c;
			}
		}
		return array(
			'types'      => array_slice( $types, 0, 2 ),
			'tags'       => self::skills_in( $text, 8 ),
			'categories' => array_slice( $cats, 0, 2 ),
			'source'     => 'rules',
		);
	}
}
